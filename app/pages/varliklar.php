<?php
require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/asset_helpers.php";
require_once __DIR__ . "/../includes/auth_helper.php";

// Helper to avoid 404 console errors by checking file existence on server
function getSafeImageUrl($path)
{
    if (empty($path))
        return null;
    $fullPath = __DIR__ . "/../../" . $path;
    if (file_exists($fullPath) && !is_dir($fullPath)) {
        return $path . "?v=" . filemtime($fullPath);
    }
    return null;
}

function deletedWhereClause(PDO $pdo, string $table, bool $showDeleted = false, string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    $tenantCond = "";
    $tenantCond = "";

    if (!tableHasColumn($pdo, $table, 'deleted_at')) {
        return '1=1' . $tenantCond;
    }

    return ($showDeleted ? "{$prefix}deleted_at IS NOT NULL" : "{$prefix}deleted_at IS NULL") . $tenantCond;
}

// Güvenli çıktı için yardımcı fonksiyon (XSS Koruması)
if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('getCleanInventoryRedirectUrl')) {
    function getCleanInventoryRedirectUrl($view, $isTrash = false, $type = '') {
        global $base_url;
        if ($isTrash) {
            $url = $base_url . "varliklar/" . $view . "/deleted";
            if ($type) {
                $url .= "?type=" . urlencode($type);
            }
        } else {
            $url = $base_url . "varliklar?view=" . $view;
            if ($type) {
                $url .= "&type=" . urlencode($type);
            }
        }
        return $url;
    }
}

if (!function_exists('cleanCpuName')) {
    function cleanCpuName($cpu) {
        if (empty($cpu) || $cpu === '-') return '-';
        // Remove (R), (TM)
        $cpu = str_ireplace(['(R)', '(TM)'], '', $cpu);
        // Remove "CPU" as a separate word
        $cpu = preg_replace('/\bCPU\b/i', '', $cpu);
        // Remove speed details like "@ 3.70GHz" or "3.70GHz" or "3.70 GHz" or "2.30GHz"
        $cpu = preg_replace('/@\s*\d+(\.\d+)?\s*[G|M]Hz/i', '', $cpu);
        $cpu = preg_replace('/\b\d+(\.\d+)?\s*[G|M]Hz/i', '', $cpu);
        // Remove Core, Processor
        $cpu = preg_replace('/\bCore\b/i', '', $cpu);
        $cpu = preg_replace('/\bProcessor\b/i', '', $cpu);
        // Remove "with ... Graphics"
        $cpu = preg_replace('/with\s+.*graphics.*/i', '', $cpu);
        // Clean up extra spaces
        $cpu = preg_replace('/\s+/', ' ', $cpu);
        return trim($cpu);
    }
}

if (!function_exists('ensureIndex')) {
    function ensureIndex($pdo, $table, $column) {
        try {
            $cleanCol = str_replace([',', ' '], '_', $column);
            $indexName = "idx_{$table}_{$cleanCol}";
            $pdo->exec("CREATE INDEX $indexName ON $table ($column)");
        } catch (Exception $e) {
            // İndeks zaten varsa veya hata oluşursa sessizce geç
        }
    }
}


$isTr = (($_SESSION['lang'] ?? 'tr') === 'tr');
$SALT = "inventory_secure_2024_super_salt";

requireLogin();
// Consumable log deletion now handled via bulk_delete_consumable_logs action below

$pdo = db();

// Performans için tüm veritabanı şema/indeks kontrollerini oturum başına sadece 1 defa çalıştır
if (empty($_SESSION['inventory_schema_checked_v4'])) {
    ensureIndex($pdo, 'assets', 'deleted_at');
    ensureIndex($pdo, 'assets', 'category_id');
    ensureIndex($pdo, 'assets', 'name');
    ensureIndex($pdo, 'assets', 'model_id');
    ensureIndex($pdo, 'assets', 'assigned_user_id');
    ensureIndex($pdo, 'assets', 'department_id');
    ensureIndex($pdo, 'assets', 'company_id');
    ensureIndex($pdo, 'assets', 'supplier_id');
    ensureIndex($pdo, 'assets', 'status_id');
    ensureIndex($pdo, 'asset_categories', 'deleted_at');
    ensureIndex($pdo, 'asset_components', 'asset_id');
    ensureIndex($pdo, 'inventory_asset_field_values', 'asset_id');
    ensureIndex($pdo, 'asset_models', 'deleted_at');
    ensureIndex($pdo, 'users', 'deleted_at');
    ensureIndex($pdo, 'users', 'fullname');
    ensureIndex($pdo, 'bolumler', 'deleted_at');

    // Sütunların varlığından emin ol
    ensureInventoryColumn($pdo, 'asset_suppliers', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'asset_companies', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'asset_manufacturers', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'asset_models', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'asset_locations', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'bolumler', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'asset_status_labels', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'asset_categories', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'assets', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'asset_licenses', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'asset_accessories', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'asset_consumables', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'asset_components', 'deleted_at', "DATETIME DEFAULT NULL");
    ensureInventoryColumn($pdo, 'users', 'email', "VARCHAR(255) NULL");
    ensureInventoryColumn($pdo, 'users', 'username', "VARCHAR(255) NULL");
    ensureInventoryColumn($pdo, 'users', 'status', "TINYINT DEFAULT 1");

    // Self-heal: Seed and migrate default status labels
    try {
        $expectedStatuses = [
            1 => ['name' => 'Arızalı', 'type' => 'undeployable', 'color' => '#f59e0b', 'show_in_nav' => 0, 'is_default' => 0],
            2 => ['name' => 'Atanmış', 'type' => 'deployable', 'color' => '#1a365d', 'show_in_nav' => 0, 'is_default' => 0],
            3 => ['name' => 'Hazır', 'type' => 'deployable', 'color' => '#10b981', 'show_in_nav' => 1, 'is_default' => 1],
            6 => ['name' => 'Hurda', 'type' => 'archived', 'color' => '#64748b', 'show_in_nav' => 0, 'is_default' => 0]
        ];

        foreach ($expectedStatuses as $id => $data) {
            $stmt = $pdo->prepare("SELECT name FROM asset_status_labels WHERE id = ?");
            $stmt->execute([$id]);
            $existingName = $stmt->fetchColumn();

            if ($existingName !== false) {
                if ($existingName !== $data['name']) {
                    $newId = 10;
                    while (true) {
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM asset_status_labels WHERE id = ?");
                        $chk->execute([$newId]);
                        if ((int)$chk->fetchColumn() === 0) break;
                        $newId++;
                    }
                    $pdo->prepare("UPDATE asset_status_labels SET id = ? WHERE id = ?")->execute([$newId, $id]);
                    $pdo->prepare("UPDATE assets SET status_id = ? WHERE status_id = ?")->execute([$newId, $id]);
                    $pdo->prepare("INSERT INTO asset_status_labels (id, name, type, color, show_in_nav, is_default) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$id, $data['name'], $data['type'], $data['color'], $data['show_in_nav'], $data['is_default']]);
                } else {
                    $pdo->prepare("UPDATE asset_status_labels SET type = ?, color = ?, show_in_nav = ?, is_default = ? WHERE id = ?")
                        ->execute([$data['type'], $data['color'], $data['show_in_nav'], $data['is_default'], $id]);
                }
            } else {
                $pdo->prepare("INSERT INTO asset_status_labels (id, name, type, color, show_in_nav, is_default) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$id, $data['name'], $data['type'], $data['color'], $data['show_in_nav'], $data['is_default']]);
            }
        }
        // Set all NULL or 0 status_id assets to default (Hazır, ID 3)
        $pdo->exec("UPDATE assets SET status_id = 3 WHERE status_id IS NULL OR status_id = 0");
    } catch (Throwable $e) {
        error_log("Failed to seed default status labels: " . $e->getMessage());
    }

    try { $pdo->exec("ALTER TABLE asset_accessory_checkouts ADD COLUMN transaction_type VARCHAR(20) NOT NULL DEFAULT 'assign'"); } catch (Exception $e) { }
    try { $pdo->exec("ALTER TABLE asset_license_checkouts ADD COLUMN transaction_type VARCHAR(20) NOT NULL DEFAULT 'assign'"); } catch (Exception $e) { }
    try { $pdo->exec("ALTER TABLE asset_consumable_checkouts ADD COLUMN transaction_type VARCHAR(20) NOT NULL DEFAULT 'consume'"); } catch (Exception $e) { }
    try { $pdo->exec("ALTER TABLE asset_timeline ADD COLUMN is_deleted TINYINT DEFAULT 0"); } catch (Exception $e) { }
    try { $pdo->exec("ALTER TABLE asset_components ADD COLUMN manufacturer_id INT DEFAULT NULL"); } catch (Exception $e) { }
    try { $pdo->exec("ALTER TABLE assets ADD COLUMN manufacturer_id INT DEFAULT NULL"); } catch (Exception $e) { }
    try { $pdo->exec("ALTER TABLE assets ADD COLUMN supplier_id INT DEFAULT NULL"); } catch (Exception $e) { }
    try {
        $pdo->exec("ALTER TABLE assets ADD COLUMN mainboard VARCHAR(255) DEFAULT NULL");
        try { $pdo->exec("ALTER TABLE assets MODIFY COLUMN hdd_size VARCHAR(500) DEFAULT NULL"); } catch (Exception $e) {}
    } catch (Exception $e) { }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_signatures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            asset_id INT NOT NULL,
            user_id INT NOT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            signed_at DATETIME NULL,
            template_id INT NULL,
            UNIQUE KEY uniq_asset_user (asset_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $pdo->exec("ALTER TABLE asset_signatures ADD COLUMN IF NOT EXISTS notes TEXT NULL");
        $pdo->exec("ALTER TABLE asset_signatures ADD COLUMN IF NOT EXISTS signature_image LONGTEXT NULL");
        try { $pdo->exec("ALTER TABLE asset_signatures ADD COLUMN IF NOT EXISTS admin_name VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
    } catch (Exception $e) {}

    try { $pdo->exec("ALTER TABLE asset_signatures MODIFY COLUMN asset_id INT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE asset_signatures MODIFY COLUMN user_id INT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE asset_signatures MODIFY COLUMN status ENUM('pending','pending_user','pending_admin','approved','rejected') NOT NULL DEFAULT 'pending_user'"); } catch (Exception $e) {}
    ensureInventoryColumn($pdo, 'asset_signatures', 'accessory_id', "INT NULL");
    ensureInventoryColumn($pdo, 'asset_signatures', 'component_id', "INT NULL");
    ensureInventoryColumn($pdo, 'asset_signatures', 'license_id', "INT NULL");
    ensureInventoryColumn($pdo, 'asset_signatures', 'created_by', "INT NULL COMMENT 'iadeyi/zimmeti başlatan admin ID'");
    ensureInventoryColumn($pdo, 'asset_signatures', 'action_type', "VARCHAR(20) NOT NULL DEFAULT 'checkout'");
    ensureInventoryColumn($pdo, 'asset_signatures', 'admin_id', "INT NULL");
    ensureInventoryColumn($pdo, 'asset_signatures', 'admin_signature_image', "LONGTEXT NULL");
    ensureInventoryColumn($pdo, 'asset_signatures', 'admin_signed_at', "DATETIME NULL");
    ensureInventoryColumn($pdo, 'asset_signatures', 'bypass_user_signature', "TINYINT DEFAULT 0");
    ensureInventoryColumn($pdo, 'asset_signatures', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

    try { $pdo->exec("ALTER TABLE asset_components ADD COLUMN company_id INT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE asset_components ADD COLUMN department_id INT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE asset_components ADD COLUMN warranty_months INT DEFAULT 0"); } catch (Exception $e) {}

    $_SESSION['inventory_schema_checked_v4'] = true;
}

// Global lookups moved inside modal/form logic where possible to reduce memory usage.
// Only essential ones remain for immediate rendering.
$all_categories = $pdo->query("SELECT id, name, type, parent_id FROM asset_categories WHERE " . deletedWhereClause($pdo, 'asset_categories') . " ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_status_labels = $pdo->query("SELECT id, name, type FROM asset_status_labels WHERE " . deletedWhereClause($pdo, 'asset_status_labels') . " ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_manufacturers = $pdo->query("SELECT id, name FROM asset_manufacturers WHERE " . deletedWhereClause($pdo, 'asset_manufacturers') . " ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$current_user_role = $_SESSION['role'] ?? 2;
$current_user_id = $_SESSION['user_id'];

// Fetch Custom Fields for specific category (AJAX) - PLACED AT TOP FOR RELIABILITY
if (isset($_GET['fetch_custom_fields'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $catId = intval($_GET['cat_id'] ?? 0);
        $assetId = intval($_GET['asset_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT DISTINCT f.* FROM inventory_custom_fields f 
                               LEFT JOIN inventory_field_group_links l ON f.field_group = l.field_group 
                               WHERE (l.category_id = ? OR f.category_id = ?) AND f.status = 1 
                               ORDER BY f.field_group ASC, f.id ASC");
        $stmt->execute([$catId, $catId]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $values = [];
        if ($assetId > 0) {
            $vStmt = $pdo->prepare("SELECT field_id, value FROM inventory_asset_field_values WHERE asset_id = ?");
            $vStmt->execute([$assetId]);
            while ($rv = $vStmt->fetch()) {
                $values[$rv['field_id']] = $rv['value'];
            }
        }
        echo json_encode(['fields' => $fields, 'values' => $values], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['fields' => [], 'values' => [], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Dynamic paginated Select2 AJAX Autocomplete search endpoint (highly optimized for 100,000+ items)
if (isset($_GET['search_select2'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    
    $type = trim($_GET['search_select2']);
    $q = trim($_GET['q'] ?? '');
    $page = intval($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $results = [];
    $more = false;
    
    if ($type === 'users') {
        $where = " WHERE " . deletedWhereClause($pdo, 'users', false) . " AND status = 1 AND username != 'customer_gateway'";
        $params = [];
        if (!empty($q)) {
            $where .= " AND (fullname LIKE ? OR username LIKE ? OR email LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        $stmt = $pdo->prepare("SELECT id, fullname as text FROM users $where ORDER BY fullname ASC LIMIT " . ($limit + 1) . " OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) {
            $more = true;
            array_pop($rows);
        }
        $results = $rows;
    } elseif ($type === 'models') {
        $where = " WHERE " . deletedWhereClause($pdo, 'asset_models', false);
        $params = [];
        if (!empty($q)) {
            $where .= " AND name LIKE ?";
            $params[] = "%$q%";
        }
        $stmt = $pdo->prepare("SELECT id, name as text FROM asset_models $where ORDER BY name ASC LIMIT " . ($limit + 1) . " OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) {
            $more = true;
            array_pop($rows);
        }
        $results = $rows;
    } elseif ($type === 'departments') {
        $where = " WHERE " . deletedWhereClause($pdo, 'bolumler', false);
        $params = [];
        if (!empty($q)) {
            $where .= " AND bolum_adi LIKE ?";
            $params[] = "%$q%";
        }
        $stmt = $pdo->prepare("SELECT id, bolum_adi as text FROM bolumler $where ORDER BY bolum_adi ASC LIMIT " . ($limit + 1) . " OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) {
            $more = true;
            array_pop($rows);
        }
        $results = $rows;
    } elseif ($type === 'assets') {
        try {
            $where = " WHERE " . deletedWhereClause($pdo, 'assets', false);
            $params = [];
            if (!empty($q)) {
                $q_clean = str_replace([' ', '-', '_'], '', $q);
                $where .= " AND (name LIKE ? OR asset_tag LIKE ? OR serial_no LIKE ? OR REPLACE(REPLACE(REPLACE(name, ' ', ''), '-', ''), '_', '') LIKE ? OR REPLACE(REPLACE(REPLACE(asset_tag, ' ', ''), '-', ''), '_', '') LIKE ? OR REPLACE(REPLACE(REPLACE(serial_no, ' ', ''), '-', ''), '_', '') LIKE ?)";
                $params[] = "%$q%";
                $params[] = "%$q%";
                $params[] = "%$q%";
                $params[] = "%$q_clean%";
                $params[] = "%$q_clean%";
                $params[] = "%$q_clean%";
            }
            $stmt = $pdo->prepare("SELECT id, CASE WHEN LOWER(TRIM(name)) = LOWER(TRIM(asset_tag)) OR asset_tag IS NULL OR TRIM(asset_tag) = '' THEN COALESCE(name, 'Unnamed') ELSE CONCAT(COALESCE(name, 'Unnamed'), ' [', asset_tag, ']') END as text FROM assets $where ORDER BY COALESCE(name, 'Unnamed') ASC LIMIT " . ($limit + 1) . " OFFSET $offset");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > $limit) {
                $more = true;
                array_pop($rows);
            }
            $results = $rows;
        } catch (Exception $e) {
            error_log("Search assets select2 error: " . $e->getMessage());
            $results = [];
        }
    } elseif ($type === 'manufacturers') {
        $where = " WHERE " . deletedWhereClause($pdo, 'asset_manufacturers', false);
        $params = [];
        if (!empty($q)) {
            $where .= " AND name LIKE ?";
            $params[] = "%$q%";
        }
        $stmt = $pdo->prepare("SELECT id, name as text FROM asset_manufacturers $where ORDER BY name ASC LIMIT " . ($limit + 1) . " OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) {
            $more = true;
            array_pop($rows);
        }
        $results = $rows;
    } elseif ($type === 'suppliers') {
        $where = " WHERE " . deletedWhereClause($pdo, 'asset_suppliers', false);
        $params = [];
        if (!empty($q)) {
            $where .= " AND name LIKE ?";
            $params[] = "%$q%";
        }
        $stmt = $pdo->prepare("SELECT id, name as text FROM asset_suppliers $where ORDER BY name ASC LIMIT " . ($limit + 1) . " OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) {
            $more = true;
            array_pop($rows);
        }
        $results = $rows;
    }
    
    echo json_encode([
        'results' => $results,
        'pagination' => ['more' => $more]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// List fetching for dynamic dropdown updates
if (isset($_GET['get_list'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    $type = trim($_GET['get_list']);
    $meta = inventoryTableMeta($type);
    $table = $meta['table'];
    $nameCol = $meta['name_column'] ?? 'name';
    $items = $pdo->query("SELECT * FROM $table WHERE " . deletedWhereClause($pdo, $table, false) . " ORDER BY $nameCol ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        if (!isset($item['name']) && isset($item[$nameCol])) {
            $item['name'] = $item[$nameCol];
        }
    }
    unset($item);
    echo json_encode($items, JSON_UNESCAPED_UNICODE);
    exit;
}

// Excel checkout template download: ?checkout_excel_template=ATTACHMENT_ID
if (isset($_GET['checkout_excel_template'])) {
    $attachId = intval($_GET['checkout_excel_template']);
    // Load attachment to get the asset and user IDs
    $att = $pdo->prepare("SELECT * FROM attachments WHERE id = ?");
    $att->execute([$attachId]);
    $attRow = $att->fetch(PDO::FETCH_ASSOC);

    $assetId   = 0;
    $userId    = 0;
    $assetInfo = [];
    $userInfo  = [];
    $deptName  = '-';
    $isTrExcel = ($_SESSION['lang'] ?? 'tr') === 'tr';
    $isReturnExcel = false;

    $initialDate = date('d.m.Y H:i');
    $assignee = '-';
    $adminName = '';
    $adminDept = '';
    
    $returnReason = '';
    $returnStatus = '';
    $damageNote = '';
    $returnDate = '';
    $receiverName = '';
    $receiverDept = '';

    if ($attRow) {
        $isReturnExcel = (($attRow['document_type'] ?? '') === 'return');
        $entityType = $attRow['entity_type'] ?? 'asset';
        $entityId   = intval($attRow['entity_id'] ?? 0);

        // Determine asset_id
        $assetId = intval($attRow['asset_id'] ?? 0);
        if (!$assetId && !empty($attRow['ref_id'])) $assetId = intval($attRow['ref_id']);
        if (!$assetId && $entityType === 'asset') $assetId = $entityId;

        if ($assetId) {
            $r = $pdo->prepare("SELECT a.*, m.name as model_name, c.name as category_name, b.bolum_adi as dept_name, u.fullname as assigned_user
                                FROM assets a
                                LEFT JOIN asset_models m ON a.model_id = m.id
                                LEFT JOIN asset_categories c ON a.category_id = c.id
                                LEFT JOIN bolumler b ON a.department_id = b.id
                                LEFT JOIN users u ON a.assigned_user_id = u.id
                                WHERE a.id = ?");
            $r->execute([$assetId]);
            $assetInfo = $r->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        // Determine user_id
        if ($isReturnExcel) {
            $colName = match($entityType) {
                'accessory' => 'accessory_id',
                'component' => 'component_id',
                'license' => 'license_id',
                default => 'asset_id'
            };
            $stmtSig = $pdo->prepare("SELECT * FROM asset_signatures WHERE `{$colName}` = ? AND action_type = 'checkin' ORDER BY id DESC LIMIT 1");
            $stmtSig->execute([$entityId]);
            $sigRow = $stmtSig->fetch(PDO::FETCH_ASSOC);
            if ($sigRow) {
                $userId = intval($sigRow['user_id'] ?? 0);
                $returnDate = date('d.m.Y H:i', strtotime($sigRow['signed_at'] ?? 'now'));
                $notes = json_decode($sigRow['notes'] ?? '', true);
                if (is_array($notes)) {
                    $returnReason = $notes['return_reason'] ?? '';
                    $returnStatus = $notes['return_status'] ?? 'hasarsiz';
                    $damageNote = $notes['damage_note'] ?? '';
                }
                
                // Get receiving admin
                $recAdminId = intval($sigRow['admin_id'] ?? 0);
                if ($recAdminId) {
                    $stmtRec = $pdo->prepare("SELECT u.fullname, b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.id = ?");
                    $stmtRec->execute([$recAdminId]);
                    $recAdmin = $stmtRec->fetch();
                    if ($recAdmin) {
                        $receiverName = $recAdmin['fullname'];
                        $receiverDept = $recAdmin['dept_name'] ?? '';
                    }
                }
                if (empty($receiverName) && !empty($sigRow['admin_name'])) {
                    $receiverName = $sigRow['admin_name'];
                }
            }
            
            if (empty($userId) && $assetId) {
                $userId = intval($assetInfo['assigned_user_id'] ?? 0);
            }
            
            if ($userId) {
                $r2 = $pdo->prepare("SELECT u.fullname, u.email, b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.id = ?");
                $r2->execute([$userId]);
                $userInfo = $r2->fetch(PDO::FETCH_ASSOC) ?: [];
                $assignee = $userInfo['fullname'] ?? '-';
                $deptName = $userInfo['dept_name'] ?? '';
            }
            
            // Get original checkout info
            $stmtTimeline = $pdo->prepare("SELECT user_id, created_at FROM asset_timeline WHERE asset_id = ? AND item_type = ? AND event_type = 'checkout' AND context_id = ? AND context_type = 'user' ORDER BY id DESC LIMIT 1");
            $stmtTimeline->execute([$entityId, $entityType, $userId]);
            $timeline = $stmtTimeline->fetch();
            if ($timeline) {
                $initialDate = date('d.m.Y H:i', strtotime($timeline['created_at']));
                $stmtAdmin = $pdo->prepare("SELECT u.fullname, b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.id = ?");
                $stmtAdmin->execute([$timeline['user_id']]);
                $adminInfoRow = $stmtAdmin->fetch();
                if ($adminInfoRow) {
                    $adminName = $adminInfoRow['fullname'];
                    $adminDept = $adminInfoRow['dept_name'] ?? '';
                }
            }
            
            // Fallback for original checkout signature admin_name/date
            $stmtSigCheckout = $pdo->prepare("SELECT signed_at, admin_name FROM asset_signatures WHERE `{$colName}` = ? AND user_id = ? AND (action_type = 'checkout' OR action_type IS NULL) AND status = 'approved' ORDER BY id DESC LIMIT 1");
            $stmtSigCheckout->execute([$entityId, $userId]);
            $sigCheckout = $stmtSigCheckout->fetch();
            if ($sigCheckout) {
                if (empty($initialDate) && !empty($sigCheckout['signed_at'])) {
                    $initialDate = date('d.m.Y H:i', strtotime($sigCheckout['signed_at']));
                }
                if (empty($adminName) && !empty($sigCheckout['admin_name'])) {
                    $adminName = $sigCheckout['admin_name'];
                }
            }
        } else {
            // Checkout Excel
            $userId = intval($assetInfo['assigned_user_id'] ?? 0);
            if ($userId) {
                $r2 = $pdo->prepare("SELECT u.fullname, u.email, b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.id = ?");
                $r2->execute([$userId]);
                $userInfo = $r2->fetch(PDO::FETCH_ASSOC) ?: [];
                $assignee = $userInfo['fullname'] ?? '-';
                $deptName = $userInfo['dept_name'] ?? '';
            }
            
            // Current admin
            $currentAdminId = $_SESSION['user_id'] ?? 0;
            $stmtAdmin = $pdo->prepare("SELECT u.fullname, b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.id = ?");
            $stmtAdmin->execute([$currentAdminId]);
            $adminInfoRow = $stmtAdmin->fetch();
            if ($adminInfoRow) {
                $adminName = $adminInfoRow['fullname'];
                $adminDept = $adminInfoRow['dept_name'] ?? '';
            }
        }
    }

    $assetName  = $assetInfo['name'] ?? '-';
    $modelName  = $assetInfo['model_name'] ?? '-';
    $categoryName = $assetInfo['category_name'] ?? '-';
    $serialNo   = $assetInfo['serial_no'] ?? '-';
    $ipAddress  = $assetInfo['ip_address'] ?? '-';
    $macAddress = $assetInfo['mac_address'] ?? '-';
    $os         = $assetInfo['os'] ?? '-';
    $cpu        = cleanCpuName($assetInfo['cpu'] ?? '-');
    $ram        = $assetInfo['ram'] ?? '-';
    $gpu        = $assetInfo['gpu'] ?? '-';
    $disk       = $assetInfo['disk'] ?? '-';
    $monitor    = $assetInfo['monitor'] ?? '-';
    $mainboard  = $assetInfo['mainboard'] ?? '-';

    if (is_numeric($ram) && $ram > 0) {
        $ram = $ram . ' GB';
    }
    if (is_numeric($disk) && $disk > 0) {
        $disk = $disk . ' GB';
    }

    $defaultAgreementTr = 'Aşağıda donanımsal detayları belirtilerek tarafıma teslim edilen envanteri, sağlam ve çalışır durumda teslim aldığımı, kullanımına ve temizliğine özen göstereceğimi, arıza veya hata durumunda Bilgi İşlem Personeli\'ni bilgilendireceğimi ve kendi başıma müdahale etmeyeceğimi, iş amacı dışında kullanmayacağımı ve başkasına kullandırmayacağımı, Bilgi İşlem Personeli\'nin izni olmadan donanımsal veya yazılımsal değişiklik yapmayacağımı, yazılım kurmayacağımı ve şifre belirlemeyeceğimi, her türlü aktivitenin (e-posta kayıtları, anlık mesajlaşma yazılımları, ziyaret edilen web siteleri, kopyalanan ve silinen dosyalar, telefon görüşmeleri, donanım kimlikleri, kullanıcı hesapları vb. gibi) Bilgi İşlem birimi tarafından uygun teknik yöntemlerle kayıt altında tutulduğunu ve ihtiyaç durumunda bu kayıtlara bakılabildiğini, bu teslim tutanağı ile birlikte ekte tarafıma teslim edilen "Donanım Kullanma Talimatı"na uyacağımı beyan ve taahhüt ederim.';
    $defaultAgreementEn = 'I hereby acknowledge that I have received the assets specified below in good working condition, agree to use them carefully and keep them clean. I agree to notify IT staff in case of any failure or error and will not attempt to repair it myself. I agree not to use the assets for non-work purposes or allow others to use them. I will not make any hardware or software changes, install software, or set passwords without IT authorization. I am aware that all activities (email records, instant messaging, web browsing, file operations, hardware IDs, user accounts, etc.) are monitored and recorded by the IT department for security and audit purposes.';
    
    if ($isTrExcel) {
        $agreementText = get_setting_fallback('inv_signature_agreement_tr', get_setting_fallback('inv_signature_agreement', $defaultAgreementTr));
    } else {
        $agreementText = get_setting_fallback('inv_signature_agreement_en', $defaultAgreementEn);
    }

    // Build Logo URL
    $siteUrl = get_setting_fallback('site_url', '');
    if (empty($siteUrl)) {
        $reqUri = $_SERVER['REQUEST_URI'];
        $subDir = '';
        if (strpos($reqUri, '/varliklar') !== false) {
            $subDir = substr($reqUri, 0, strpos($reqUri, '/varliklar'));
        }
        $siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $subDir;
    }
    $siteUrl = rtrim($siteUrl, '/');
    $logoUrl = $siteUrl . '/public/logo.png';

    $prefix = $isReturnExcel ? 'Iade_' : 'Zimmet_';
    $filename = $prefix . preg_replace('/[^A-Za-z0-9_\-]/', '_', $assetName) . '_' . date('Y-m-d') . '.xls';

    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    // Output HTML-based Excel Table (fully inline styles - CSS classes don't work in Excel)
    $cWhite  = '#ffffff';
    $cLight  = '#eef2f7';
    $cNavy   = '#1a3c6e';
    $cRed    = '#c0392b';
    $tdBase  = 'font-family:Arial,sans-serif; font-size:9pt; vertical-align:top; padding:5px 8px;';
    $bdrAll  = 'border:1px solid #000000;';
    $bdrThick= 'border:2px solid #000000;';
    ?>
<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<!--[if gte mso 9]><xml>
 <x:ExcelWorkbook>
  <x:ExcelWorksheets>
   <x:ExcelWorksheet>
    <x:Name><?= $isTrExcel ? 'Tutanak' : 'Report' ?></x:Name>
    <x:WorksheetOptions>
     <x:FitToPage/>
     <x:Print>
      <x:FitWidth>1</x:FitWidth>
      <x:FitHeight>1</x:FitHeight>
      <x:ValidPrinterInfo/>
     </x:Print>
     <x:PageSetup>
      <x:Layout x:Orientation="Portrait"/>
      <x:PaperSizeIndex>9</x:PaperSizeIndex>
      <x:PageMargins x:Bottom="0.4" x:Left="0.4" x:Right="0.4" x:Top="0.4"/>
     </x:PageSetup>
    </x:WorksheetOptions>
   </x:ExcelWorksheet>
  </x:ExcelWorksheets>
 </x:ExcelWorkbook>
</xml><![endif]-->
<style>
 body { font-family:Arial,sans-serif; margin:6px; }
 td   { font-family:Arial,sans-serif; font-size:9pt; }
</style>
</head>
<body>
<table width="700" border="0" cellspacing="0" cellpadding="0"
       style="border-collapse:collapse; width:700px; border:2px solid #000000;">

  <!-- HEADER -->
  <tr>
    <td width="130" bgcolor="#ffffff"
        style="width:130px; border:1px solid #000000; border-right:2px solid #000000; border-bottom:2px solid #000000;
               text-align:center; vertical-align:middle; padding:8px;">
      <img src="<?= $logoUrl ?>" width="90" height="36" />
    </td>
    <td width="570" bgcolor="#ffffff"
        style="width:570px; border:1px solid #000000; border-left:0; border-bottom:2px solid #000000;
               text-align:center; vertical-align:middle; padding:8px; font-family:Arial,sans-serif;">
      <b style="font-size:12pt;"><?= e(get_setting_fallback('company_name', 'Eaprimus A.Ş.')) ?></b><br>
      <span style="font-size:10.5pt;"><?= $isReturnExcel
        ? ($isTrExcel ? 'Donanım İade Tutanağı'   : 'Hardware Return Report')
        : ($isTrExcel ? 'Donanım Teslim Tutanağı' : 'Hardware Handover Report') ?></span>
    </td>
  </tr>

  <!-- AGREEMENT -->
  <tr>
    <td colspan="2" bgcolor="#ffffff"
        style="border:1px solid #000000; border-top:0; border-bottom:2px solid #000000;
               font-family:Arial,sans-serif; font-size:8pt; text-align:justify; line-height:1.3; padding:7px 10px;">
      <?= html_entity_decode($agreementText, ENT_QUOTES, 'UTF-8') ?>
    </td>
  </tr>

  <!-- SPECS HEADER -->
  <tr>
    <td width="300" bgcolor="#1a3c6e"
        style="width:300px; border:1px solid #000000; border-top:0; border-bottom:2px solid #000000;
               background-color:#1a3c6e; color:#ffffff; font-family:Arial,sans-serif; font-size:9.5pt; font-weight:bold; padding:5px 8px;">
      <b><?= $isTrExcel ? 'Donanım Özellikleri' : 'Hardware Specifications' ?></b>
    </td>
    <td width="400" bgcolor="#1a3c6e"
        style="width:400px; border:1px solid #000000; border-top:0; border-left:0; border-bottom:2px solid #000000;
               background-color:#1a3c6e; color:#ffffff; font-family:Arial,sans-serif; font-size:9.5pt; font-weight:bold; padding:5px 8px;">
      <b><?= $isTrExcel ? 'Açıklama' : 'Description' ?></b>
    </td>
  </tr>

  <!-- SPEC ROWS -->
  <?php
  $specs = [
    [$isTrExcel?'Donanım Adı / ID'    :'Hardware Name / ID',     $assetName,    '#ffffff'],
    [$isTrExcel?'Donanım Modeli'       :'Hardware Model',          $modelName,    '#eef2f7'],
    [$isTrExcel?'Donanım Kategorisi'   :'Hardware Category',       $categoryName, '#ffffff'],
    [$isTrExcel?'Seri Numarası'        :'Serial Number',           $serialNo,     '#eef2f7'],
    [$isTrExcel?'IP Adresi (Ethernet)' :'IP Address (Ethernet)',   $ipAddress,    '#ffffff'],
    [$isTrExcel?'MAC Adresi (Ethernet)':'MAC Address (Ethernet)',  $macAddress,   '#eef2f7'],
    [$isTrExcel?'İşletim Sistemi'      :'Operating System',        $os,           '#ffffff'],
    [$isTrExcel?'İşlemci (CPU)'        :'Processor (CPU)',         $cpu,          '#eef2f7'],
    [$isTrExcel?'Bellek (RAM)'         :'Memory (RAM)',            $ram,          '#ffffff'],
    [$isTrExcel?'Ekran Kartı (GPU)'    :'Graphics Card (GPU)',     $gpu,          '#eef2f7'],
    [$isTrExcel?'Disk'                 :'Disk',                    $disk,         '#ffffff'],
    [$isTrExcel?'Monitör'              :'Monitor',                 $monitor,      '#eef2f7'],
    [$isTrExcel?'Anakart'              :'Mainboard',               $mainboard,    '#ffffff'],
  ];
  foreach ($specs as [$lbl, $val, $bg]):
  ?>
  <tr>
    <td bgcolor="<?= $bg ?>" style="border:1px solid #cccccc; border-top:0; border-right:1px solid #000000; background-color:<?= $bg ?>; font-family:Arial,sans-serif; font-size:9pt; padding:4px 7px;">
      <?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?>
    </td>
    <td bgcolor="<?= $bg ?>" style="border:1px solid #cccccc; border-top:0; border-left:0; background-color:<?= $bg ?>; font-family:Arial,sans-serif; font-size:9pt; padding:4px 7px; white-space:nowrap;">
      <?= e($val) ?>
    </td>
  </tr>
  <?php endforeach; ?>

  <!-- CHECKOUT SIGNATURE HEADER -->
  <tr>
    <td colspan="2" bgcolor="#1a3c6e"
        style="border:2px solid #000000; background-color:#1a3c6e; color:#ffffff;
               font-family:Arial,sans-serif; font-size:10pt; font-weight:bold; text-align:center; padding:6px;">
      <b><?= $isReturnExcel
          ? ($isTrExcel?'TESLİM ALINAN BÖLÜM (ZİMMET)':'ORIGINAL CHECKOUT SECTION')
          : ($isTrExcel?'TESLİM ALINAN PERSONEL':'RECEIVING PERSONNEL') ?></b>
    </td>
  </tr>

  <!-- CHECKOUT SIGNATURE BOXES -->
  <tr>
    <td width="350" bgcolor="#ffffff"
        style="width:350px; border:1px solid #000000; border-top:0; border-right:2px solid #000000;
               font-family:Arial,sans-serif; font-size:9pt; padding:8px 10px; vertical-align:top;">
      <b><?= $isTrExcel?'Teslim Alan':'Received By' ?></b><br>
      <?= $isTrExcel?'Teslim Tarihi':'Date' ?>: <?= e($initialDate) ?><br>
      <?= $isTrExcel?'Adı Soyadı':'Full Name' ?>: <?= e($assignee) ?><br>
      <?= $isTrExcel?'Bölümü':'Department' ?>: <?= e($deptName) ?><br><br>
      <?= $isTrExcel?'İmza':'Signature' ?>:<br><br><br><br><br><br>
      ________________________________
    </td>
    <td width="350" bgcolor="#ffffff"
        style="width:350px; border:1px solid #000000; border-top:0; border-left:0;
               font-family:Arial,sans-serif; font-size:9pt; padding:8px 10px; vertical-align:top;">
      <b><?= $isTrExcel?'Teslim Eden':'Delivered By' ?></b><br>
      <?= $isTrExcel?'Adı Soyadı':'Full Name' ?>: ........................................<br>
      <?= $isTrExcel?'Bölümü':'Department' ?>: ........................................<br><br>
      <?= $isTrExcel?'İmza':'Signature' ?>:<br><br><br><br><br><br>
      ________________________________
    </td>
  </tr>

  <!-- RETURN SECTION HEADER -->
  <tr>
    <td colspan="2" bgcolor="#c0392b"
        style="border:2px solid #000000; background-color:#c0392b; color:#ffffff;
               font-family:Arial,sans-serif; font-size:10pt; font-weight:bold; text-align:center; padding:6px;">
      <b>(<?= $isTrExcel?'Bu bölüm geri teslimde doldurulacaktır':'This section will be filled upon return' ?>)</b>
    </td>
  </tr>

  <!-- RETURN STATUS -->
  <tr>
    <td colspan="2" bgcolor="#ffffff"
        style="border:1px solid #000000; border-top:0; border-bottom:2px solid #000000;
               font-family:Arial,sans-serif; font-size:8.5pt; padding:7px 10px;">
      <?php if ($isReturnExcel && !empty($returnReason)): ?><u><?= e($returnReason) ?></u><?php else: ?>........................................<?php endif; ?>
      <?= $isTrExcel
          ?'sebebi ile teslim edilen envanterin aşağıda adı, soyadı ve imzası olan personelden;'
          :'reason – from the personnel whose name and signature are below;' ?><br><br>
      [<?= ($isReturnExcel&&$returnStatus==='hasarsiz')?'X':'&nbsp;' ?>] <?= $isTrExcel?'Hasarsız ve Tam Teslim Edilmiştir.':'Returned undamaged.' ?>
      &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
      [<?= ($isReturnExcel&&($returnStatus==='hasarli'||$returnStatus==='hasarli_kullanilabilir'))?'X':'&nbsp;' ?>] <?= $isTrExcel?'Hasarlı yada Eksik Teslim Edilmiştir.':'Returned damaged or incomplete.' ?>
      <?php if ($isReturnExcel&&($returnStatus==='hasarli'||$returnStatus==='hasarli_kullanilabilir')&&!empty($damageNote)): ?>
        <br><br><b><?= $isTrExcel?'Hasar / Eksik Açıklaması: ':'Damage / Missing Details: ' ?></b><?= e($damageNote) ?>
      <?php endif; ?>
    </td>
  </tr>

  <!-- RETURN SIGNATURE BOXES -->
  <tr>
    <td width="350" bgcolor="#ffffff"
        style="width:350px; border:1px solid #000000; border-top:0; border-right:2px solid #000000;
               font-family:Arial,sans-serif; font-size:9pt; padding:8px 10px; vertical-align:top;">
      <b><?= $isTrExcel?'Teslim Eden':'Returned By' ?></b><br>
      <?= $isTrExcel?'İade Tarihi':'Return Date' ?>: <?= $isReturnExcel?e($returnDate):'..../..../20...' ?><br>
      <?= $isTrExcel?'Adı Soyadı':'Full Name' ?>: <?= $isReturnExcel?e($assignee):'........................................' ?><br>
      <?= $isTrExcel?'Bölümü':'Department' ?>: <?= $isReturnExcel?e($deptName):'........................................' ?><br><br>
      <?= $isTrExcel?'İmza':'Signature' ?>:<br><br><br><br><br><br>
      ________________________________
    </td>
    <td width="350" bgcolor="#ffffff"
        style="width:350px; border:1px solid #000000; border-top:0; border-left:0;
               font-family:Arial,sans-serif; font-size:9pt; padding:8px 10px; vertical-align:top;">
      <b><?= $isTrExcel?'Teslim Alan':'Received By' ?></b><br>
      <?= $isTrExcel?'Adı Soyadı':'Full Name' ?>: ........................................<br>
      <?= $isTrExcel?'Bölümü':'Department' ?>: ........................................<br><br>
      <?= $isTrExcel?'İmza':'Signature' ?>:<br><br><br><br><br><br>
      ________________________________
    </td>
  </tr>

</table>
</body>
</html>
<?php
    exit;
}

// Fetch Technical Details of multiple assets for Excel/PDF export (AJAX)
if (isset($_REQUEST['get_assets_tech_details'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    $idsRaw = $_REQUEST['get_assets_tech_details'];
    $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
    
    $results = [];
    if (!empty($ids)) {
        $isTrLocal = ($_SESSION['lang'] ?? 'tr') === 'tr';
        // Fetch custom field labels
        $cfLabels = $pdo->query("SELECT id, field_label FROM inventory_custom_fields")->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, name, serial_no, asset_tag, ip_address, mac_address, notes, cpu, ram, disk, gpu, os, mainboard, monitor, assigned_user_id, specs FROM assets WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch custom field values for all these assets
        $stmtVal = $pdo->prepare("SELECT asset_id, field_id, value FROM inventory_asset_field_values WHERE asset_id IN ($placeholders)");
        $stmtVal->execute($ids);
        $cfValues = [];
        while ($row = $stmtVal->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['value']) && $row['value'] !== '-') {
                $cfValues[$row['asset_id']][$row['field_id']] = $row['value'];
            }
        }
        
        $stmtLic = $pdo->prepare("
            SELECT software_name, license_key FROM asset_licenses 
            WHERE (asset_id = ? OR (assigned_user_id IS NOT NULL AND assigned_user_id = ? AND assigned_user_id > 0)) AND deleted_at IS NULL
            UNION
            SELECT l.software_name, l.license_key FROM asset_licenses l
            JOIN asset_license_checkouts c ON l.id = c.license_id
            WHERE (c.asset_id = ? OR (c.user_id IS NOT NULL AND c.user_id = ? AND c.user_id > 0)) 
              AND (c.transaction_type IS NULL OR c.transaction_type != 'checkin') 
              AND l.deleted_at IS NULL
        ");
        
        foreach ($assets as $ast) {
            $tech = [];
            $specsData = [];
            if (!empty($ast['specs'])) {
                $decoded = @json_decode($ast['specs'], true);
                if (is_array($decoded)) {
                    $specsData = $decoded;
                }
            }

            // Basic Specs
            $tech[$isTrLocal ? 'IP Adresi' : 'IP Address'] = (!empty($ast['ip_address']) && $ast['ip_address'] !== '-') ? $ast['ip_address'] : ($specsData['ip_address'] ?? '-');
            $tech[$isTrLocal ? 'MAC Adresi' : 'MAC Address'] = (!empty($ast['mac_address']) && $ast['mac_address'] !== '-') ? $ast['mac_address'] : ($specsData['mac_address'] ?? '-');
            $tech[$isTrLocal ? 'İşlemci (CPU)' : 'Processor (CPU)'] = (!empty($ast['cpu']) && $ast['cpu'] !== '-') ? cleanCpuName($ast['cpu']) : (isset($specsData['cpu']) ? cleanCpuName($specsData['cpu']) : '-');
            
            $ramVal = trim($ast['ram'] ?? ($specsData['ram'] ?? ''));
            if (!empty($ramVal) && $ramVal !== '-') {
                if (is_numeric($ramVal) && $ramVal > 0) $ramVal .= ' GB';
            } else {
                $ramVal = '-';
            }
            $tech[$isTrLocal ? 'Bellek (RAM)' : 'Memory (RAM)'] = $ramVal;
            
            $diskVal = trim($ast['disk'] ?? ($specsData['disk'] ?? ''));
            if (empty($diskVal) || $diskVal === '-') {
                if (!empty($specsData['disks']) && is_array($specsData['disks'])) {
                    $diskParts = [];
                    foreach ($specsData['disks'] as $dk => $dv) {
                        if (is_array($dv)) {
                            $diskParts[] = implode(' ', array_filter($dv));
                        } else {
                            $diskParts[] = (string)$dv;
                        }
                    }
                    $diskVal = implode(' + ', array_filter($diskParts));
                }
            }
            if (!empty($diskVal) && $diskVal !== '-') {
                if (is_numeric($diskVal) && $diskVal > 0) $diskVal .= ' GB';
            } else {
                $diskVal = '-';
            }
            $tech[$isTrLocal ? 'Disk' : 'Disk'] = $diskVal;
            
            $tech[$isTrLocal ? 'Ekran Kartı (GPU)' : 'Graphics Card (GPU)'] = (!empty($ast['gpu']) && $ast['gpu'] !== '-') ? $ast['gpu'] : ($specsData['gpu'] ?? '-');
            $tech[$isTrLocal ? 'İşletim Sistemi' : 'Operating System'] = (!empty($ast['os']) && $ast['os'] !== '-') ? $ast['os'] : ($specsData['os'] ?? '-');
            $tech[$isTrLocal ? 'Anakart' : 'Mainboard'] = (!empty($ast['mainboard']) && $ast['mainboard'] !== '-') ? $ast['mainboard'] : ($specsData['mainboard'] ?? '-');
            $tech[$isTrLocal ? 'Monitör' : 'Monitor'] = (!empty($ast['monitor']) && $ast['monitor'] !== '-') ? $ast['monitor'] : ($specsData['monitor'] ?? '-');
            
            // Antivirus check
            $antivirusVal = trim($ast['antivirus'] ?? ($specsData['antivirus'] ?? ($specsData['installed_antivirus'] ?? '')));
            if (!empty($antivirusVal) && $antivirusVal !== '-') {
                $tech['Antivirus'] = $antivirusVal;
            }

            if (!empty($ast['notes']) && $ast['notes'] !== '-') $tech[$isTrLocal ? 'Notlar' : 'Notes'] = $ast['notes'];
            
            // Extra specs JSON keys
            if (!empty($specsData)) {
                $excluded = ['os', 'cpu', 'ram', 'ram_gb', 'gpu', 'monitor', 'disk', 'disks', 'disk_c_total_gb', 'disk_c_free_gb', 'ip_address', 'mac_address', 'ip_secondary', 'antivirus', 'installed_antivirus', 'installed_software'];
                foreach ($specsData as $sk => $sv) {
                    if (in_array(strtolower($sk), $excluded)) continue;
                    if (!empty($sv) && $sv !== '-') {
                        $displayKey = ucfirst(str_replace('_', ' ', $sk));
                        if (!isset($tech[$displayKey])) {
                            $tech[$displayKey] = is_array($sv) ? implode(', ', array_filter($sv)) : (string)$sv;
                        }
                    }
                }
            }

            // Fetch and append assigned licenses (product keys) with software names (device or assigned user)
            $uId = intval($ast['assigned_user_id'] ?? 0);
            $stmtLic->execute([$ast['id'], $uId, $ast['id'], $uId]);
            $licRows = $stmtLic->fetchAll(PDO::FETCH_ASSOC);
            $lics = [];
            foreach ($licRows as $lr) {
                $sName = trim($lr['software_name'] ?? '');
                $sKey = trim($lr['license_key'] ?? '');
                if (!empty($sName)) {
                    if (!empty($sKey)) {
                        $lics[] = $sName . " [" . $sKey . "]";
                    } else {
                        $lics[] = $sName;
                    }
                } elseif (!empty($sKey)) {
                    $lics[] = $sKey;
                }
            }
            $lics = array_filter(array_unique($lics));
            $tech[$isTrLocal ? 'Ürün Anahtarı / Lisanslar' : 'Product Key / Licenses'] = !empty($lics) ? implode("\n", $lics) : '-';
            
            // Add custom fields
            $aid = $ast['id'];
            if (isset($cfValues[$aid])) {
                foreach ($cfValues[$aid] as $fid => $val) {
                    if (isset($cfLabels[$fid]) && !empty($val) && $val !== '-') {
                        $tech[$cfLabels[$fid]] = $val;
                    }
                }
            }
            $results[$aid] = $tech;
        }
    }
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
    exit;
}

// Fetch Model Details (AJAX) - Auto-populate Category & Manufacturer
if (isset($_GET['get_model_details'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['get_model_details']);
    $stmt = $pdo->prepare("SELECT m.category_id, c.name as category_name, m.manufacturer_id, mf.name as manufacturer_name FROM asset_models m LEFT JOIN asset_categories c ON m.category_id = c.id LEFT JOIN asset_manufacturers mf ON m.manufacturer_id = mf.id WHERE m.id = ?");
    $stmt->execute([$id]);
    $details = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($details ?: new stdClass(), JSON_UNESCAPED_UNICODE);
    exit;
}

// Fetch Component Instances for Grouped View (AJAX)
if (isset($_GET['get_component_instances'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    $compName = $_GET['get_component_instances'];
    $catId = intval($_GET['cat_id'] ?? 0);

    $sql = "SELECT c.*, a.name as asset_name, u.fullname as user_name 
            FROM asset_components c 
            LEFT JOIN assets a ON c.asset_id = a.id 
            LEFT JOIN users u ON c.assigned_user_id = u.id 
            WHERE c.name = ? AND c.category_id = ? AND c.deleted_at IS NULL 
            ORDER BY c.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$compName, $catId]);
    $instances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enrich with checkout info
    foreach ($instances as &$ins) {
        $ch = $pdo->query("SELECT * FROM asset_component_checkouts WHERE component_id = " . $ins['id'] . " LIMIT 1")->fetch();
        if ($ch) {
            if ($ch['asset_id']) {
                $assetData = $pdo->query("SELECT name FROM assets WHERE id = " . $ch['asset_id'])->fetch();
                $ins['assigned_to'] = $assetData['name'] ?? 'Asset';
                $ins['assigned_to_type'] = 'asset';
                $ins['assigned_to_id'] = $ch['asset_id'];
            } else if ($ch['user_id']) {
                $userData = $pdo->query("SELECT fullname FROM users WHERE id = " . $ch['user_id'])->fetch();
                $ins['assigned_to'] = $userData['fullname'] ?? 'User';
                $ins['assigned_to_type'] = 'user';
                $ins['assigned_to_id'] = $ch['user_id'];
            }
        } else if (!empty($ins['asset_id'])) {
            // Fallback for legacy/sync issues
            $ins['assigned_to'] = $ins['asset_name'] ?? 'Asset';
            $ins['assigned_to_type'] = 'asset';
            $ins['assigned_to_id'] = $ins['asset_id'];
        } else if (!empty($ins['assigned_user_id'])) {
            $ins['assigned_to'] = $ins['user_name'] ?? 'User';
            $ins['assigned_to_type'] = 'user';
            $ins['assigned_to_id'] = $ins['assigned_user_id'];
        } else {
            $ins['assigned_to'] = null;
        }
    }

    echo json_encode($instances, JSON_UNESCAPED_UNICODE);
    exit;
}

// Fetch Active Checkout Signature Type (AJAX)
if (isset($_GET['get_active_checkout_signature'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['get_active_checkout_signature']);
    $view = $_GET['assign_view'] ?? 'assets';
    $checkoutId = isset($_GET['checkout_id']) ? intval($_GET['checkout_id']) : null;
    
    // Strict whitelist check to prevent SQL injection
    $allowedViews = ['assets', 'accessories', 'licenses', 'components', 'consumables'];
    if (!in_array($view, $allowedViews)) {
        echo json_encode(['error' => 'Invalid view', 'is_paper' => false]);
        exit;
    }
    
    $isPaper = false;
    
    if ($view === 'assets') {
        $stmtUser = $pdo->prepare("SELECT assigned_user_id FROM assets WHERE id = ?");
        $stmtUser->execute([$id]);
        $assignedUserId = $stmtUser->fetchColumn();
        
        if ($assignedUserId) {
            $stmt = $pdo->prepare("
                SELECT notes, action_type, signature_image, admin_signature_image, bypass_user_signature
                FROM asset_signatures 
                WHERE asset_id = ? AND user_id = ? AND (action_type = 'checkout' OR action_type IS NULL) AND status = 'approved'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$id, $assignedUserId]);
            $sigRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($sigRow) {
                $notes      = $sigRow['notes'] ?? '';
                $actionType = $sigRow['action_type'];
                $sigImage   = $sigRow['signature_image'] ?? '';
                $adminSig   = $sigRow['admin_signature_image'] ?? '';
                
                $bypassUser = isset($sigRow['bypass_user_signature']) ? $sigRow['bypass_user_signature'] : null;

                $hasDigitalSig = ((!empty($sigImage) && strpos($sigImage, 'data:image/') === 0)
                               || (!empty($adminSig)  && strpos($adminSig,  'data:image/') === 0));
                if ($bypassUser == 1) {
                    $isPaper = true;
                } elseif ($hasDigitalSig) {
                    $isPaper = false;
                } else {
                    $isPaper = true;
                }
            }
        }
    } else {
        $singular = rtrim($view, 's');
        if (substr($view, -3) === 'ies') $singular = substr($view, 0, -3) . 'y';
        $col = $singular . "_id";
        
        $userId = null;
        if ($checkoutId > 0) {
            $checkoutTable = "asset_" . $singular . "_checkouts";
            $stmtUser = $pdo->prepare("SELECT user_id FROM $checkoutTable WHERE id = ?");
            $stmtUser->execute([$checkoutId]);
            $userId = $stmtUser->fetchColumn();
        }
        
        if ($userId) {
            $stmt = $pdo->prepare("
                SELECT notes, action_type, signature_image, admin_signature_image, bypass_user_signature
                FROM asset_signatures 
                WHERE $col = ? AND user_id = ? AND (action_type = 'checkout' OR action_type IS NULL) AND status = 'approved'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$id, $userId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT notes, action_type, signature_image, admin_signature_image, bypass_user_signature
                FROM asset_signatures 
                WHERE $col = ? AND (action_type = 'checkout' OR action_type IS NULL) AND status = 'approved'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$id]);
        }
        $sigRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sigRow) {
            $notes      = $sigRow['notes'] ?? '';
            $actionType = $sigRow['action_type'];
            $sigImage   = $sigRow['signature_image'] ?? '';
            $adminSig   = $sigRow['admin_signature_image'] ?? '';
            
            $bypassUser = isset($sigRow['bypass_user_signature']) ? $sigRow['bypass_user_signature'] : null;

            $hasDigitalSig = ((!empty($sigImage) && strpos($sigImage, 'data:image/') === 0)
                           || (!empty($adminSig)  && strpos($adminSig,  'data:image/') === 0));
            if ($bypassUser == 1) {
                $isPaper = true;
            } elseif ($hasDigitalSig) {
                $isPaper = false;
            } else {
                $isPaper = true;
            }
        }
    }
    
    echo json_encode(['is_paper' => $isPaper]);
    exit;
}

// Fetch Assignments for Return Selection (AJAX) - Supports Consumables, Accessories, Licenses
if (isset($_GET['get_assignments'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['get_assignments']);
    $view = $_GET['assign_view'] ?? 'consumables';
    
    $singular = rtrim($view, 's');
    if (substr($view, -3) === 'ies') $singular = substr($view, 0, -3) . 'y';
    $table = "asset_" . $singular . "_checkouts";
    $idCol = $singular . "_id";
    
    $extraWhere = "";
    if ($view === 'consumables') {
        $extraWhere = " AND c.transaction_type = 'consume'";
    }

    $sql = "SELECT c.*, a.name as asset_name, u.fullname as user_name 
            FROM $table c 
            LEFT JOIN assets a ON c.asset_id = a.id 
            LEFT JOIN users u ON c.user_id = u.id 
            WHERE c.$idCol = ? $extraWhere
            ORDER BY c.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($assignments as &$as) {
        $as['assigned_to'] = $as['user_name'] ?: ($as['asset_name'] ?: 'Unknown');
        $as['date'] = date('d.m.Y', strtotime($as['created_at']));
    }
    
    echo json_encode($assignments, JSON_UNESCAPED_UNICODE);
    exit;
}

// Small JSON endpoint: return component info (asset_id, total_qty) for client validations
if (isset($_GET['get_component_info'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    $cid = intval($_GET['get_component_info']);
    $row = $pdo->query("SELECT id, asset_id, total_qty FROM asset_components WHERE id = $cid AND deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
    echo json_encode($row ?: new stdClass(), JSON_UNESCAPED_UNICODE);
    exit;
}

// ENHANCEMENT: Fetch individual accessory/consumable instances for transfer selection (AJAX)
if (isset($_GET['get_accessory_instances'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    $itemId = intval($_GET['get_accessory_instances']);
    $view = $_GET['view'] ?? 'accessories';
    
    $singular = getSingularType($view);
    $table = "asset_" . $singular . "_checkouts";
    $idCol = $singular . "_id";
    $mainTable = "asset_" . $view; 
    
    // Select correct quantity field
    $qtyField = ($view === 'licenses') ? 'seats' : (($view === 'consumables') ? 'remaining_qty' : 'total_qty');
    
    // Get total quantity from main table
    $stmt = $pdo->prepare("SELECT $qtyField FROM $mainTable WHERE id = ?");
    $stmt->execute([$itemId]);
    $totalFromTable = intval($stmt->fetchColumn() ?: 0);

    // Get active assignments from checkouts table
    $sql = "SELECT c.*, u.fullname as assigned_to, a.name as asset_name
            FROM $table c 
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN assets a ON c.asset_id = a.id
            WHERE c.$idCol = ?
            ORDER BY c.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$itemId]);
    $instances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $assignedTotal = 0;
    $result = [];
    foreach($instances as $inst) {
        $qty = intval($inst['quantity'] ?? 1);
        $assignedTotal += $qty;
        $result[] = [
            'id' => $inst['id'],
            'assigned_to' => $inst['assigned_to'] ?: ($inst['asset_name'] ?: 'Unknown'),
            'quantity' => $qty,
            'type' => 'assigned'
        ];
    }
    
    // Calculate available (stock)
    // For consumables, remaining_qty IS the available stock.
    // For accessories/licenses, total pool - assigned = available.
    if ($view === 'consumables') {
        $available = $totalFromTable;
    } else {
        $available = $totalFromTable - $assignedTotal;
    }

    if ($available > 0) {
        $result[] = [
            'id' => 'stock',
            'assigned_to' => null,
            'quantity' => $available,
            'type' => 'stock'
        ];
    }
    
    echo json_encode(['total_qty' => $totalFromTable, 'available' => $available, 'instances' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

// Autocomplete endpoint for smart search
if (isset($_GET['autocomplete'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    $term = trim($_GET['term'] ?? '');
    $results = [];
    if (strlen($term) >= 1) {
        $termLower = "%" . strtolower($term) . "%";

        // 1. Assets (ID, Name, Tag, IP, MAC, Serial, Assigned User)
        $stmt = $pdo->prepare("SELECT a.id, a.name, a.asset_tag, a.ip_address, a.mac_address, a.serial_no, u.fullname as user_name 
                               FROM assets a 
                               LEFT JOIN users u ON a.assigned_user_id = u.id
                               WHERE (LOWER(a.name) LIKE ? OR LOWER(a.asset_tag) LIKE ? OR LOWER(a.ip_address) LIKE ? OR LOWER(a.mac_address) LIKE ? OR LOWER(a.serial_no) LIKE ? OR LOWER(u.fullname) LIKE ? OR LOWER(a.notes) LIKE ?) 
                               AND a.deleted_at IS NULL LIMIT 10");
        $stmt->execute([$termLower, $termLower, $termLower, $termLower, $termLower, $termLower, $termLower]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $label = !empty($r['asset_tag']) ? $r['name'] . " (" . $r['asset_tag'] . ")" : $r['name'];
            $val = !empty($r['asset_tag']) ? $r['asset_tag'] : $r['name'];
            $assetUrl = "varlik-detay/" . $r['id'];
            $results[] = ['label' => $label, 'value' => $val, 'category' => $isTr ? 'Varlıklar' : 'Assets', 'id' => $r['id'], 'url' => $assetUrl];
            if (!empty($r['user_name']) && strpos(strtolower($r['user_name']), strtolower($term)) !== false)
                $results[] = ['label' => "Zimmetli: " . $r['user_name'] . (!empty($r['asset_tag']) ? " (" . $r['asset_tag'] . ")" : " (" . $r['name'] . ")"), 'value' => $val, 'category' => $isTr ? 'Kullanıcı' : 'User', 'id' => $r['id'], 'url' => $assetUrl];
        }

        // 1b. Assets via Custom Fields (Technical Details)
        $stmt = $pdo->prepare("SELECT DISTINCT a.id, a.name, a.asset_tag, fv.value as match_val 
                               FROM inventory_asset_field_values fv 
                               JOIN assets a ON fv.asset_id = a.id 
                               WHERE LOWER(fv.value) LIKE ? AND " . deletedWhereClause($pdo, 'assets', false, 'a') . " LIMIT 5");
        $stmt->execute([$termLower]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $val = !empty($r['asset_tag']) ? $r['asset_tag'] : $r['name'];
            $results[] = ['label' => $r['match_val'] . " (" . $r['name'] . ")", 'value' => $val, 'category' => $isTr ? 'Teknik Detay' : 'Technical Detail', 'id' => $r['id'], 'url' => "varlik-detay/" . $r['id']];
        }

        // 2. Users
        $stmt = $pdo->prepare("SELECT id, fullname FROM users WHERE LOWER(fullname) LIKE ? AND status = 1 AND deleted_at IS NULL LIMIT 5");
        $stmt->execute([$termLower]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = ['label' => $r['fullname'], 'value' => $r['fullname'], 'category' => $isTr ? 'Personel' : 'Personnel', 'url' => "kullanici-detay/" . $r['id']];
        }

        // 3. Categories
        $stmt = $pdo->prepare("SELECT name FROM asset_categories WHERE LOWER(name) LIKE ? AND " . deletedWhereClause($pdo, 'asset_categories') . " LIMIT 5");
        $stmt->execute([$termLower]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = ['label' => $r['name'], 'value' => $r['name'], 'category' => $isTr ? 'Kategoriler' : 'Categories'];
        }

        // 4. Companies
        $stmt = $pdo->prepare("SELECT name FROM asset_companies WHERE LOWER(name) LIKE ? AND " . deletedWhereClause($pdo, 'asset_companies') . " LIMIT 5");
        $stmt->execute([$termLower]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = ['label' => $r['name'], 'value' => $r['name'], 'category' => $isTr ? 'Şirket' : 'Company'];
        }

        // 5. Suppliers
        $stmt = $pdo->prepare("SELECT name FROM asset_suppliers WHERE LOWER(name) LIKE ? AND " . deletedWhereClause($pdo, 'asset_suppliers') . " LIMIT 5");
        $stmt->execute([$termLower]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = ['label' => $r['name'], 'value' => $r['name'], 'category' => $isTr ? 'Tedarikçi' : 'Supplier'];
        }

    }

    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    exit;
}



$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

function normalizeInventoryCategoryType(?string $type): string
{
    $value = strtolower(trim((string) $type));
    return match ($value) {
        'asset', 'assets', 'demirbas', 'demirbaş', 'fixed_asset', 'fixed assets' => 'asset',
        'accessory', 'accessories', 'aksesuar', 'aksesuarlar' => 'accessory',
        'consumable', 'consumables', 'sarf', 'sarf_malzeme', 'sarf malzeme', 'sarf_malzemesi' => 'consumable',
        'component', 'components', 'bilesen', 'bileşen', 'bilesenler', 'bileşenler' => 'component',
        'license', 'licenses', 'lisans', 'lisanslar' => 'license',
        default => $value,
    };
}

function postValue(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function postNullableInt(string $key): ?int
{
    $value = $_POST[$key] ?? null;
    if ($value === null || $value === '') {
        return null;
    }
    return (int) $value;
}

function postInt(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    return (int) $value;
}

function postFloat(string $key, float $default = 0): float
{
    $value = $_POST[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    return (float) $value;
}







function uploadInventoryImage(array $file, string $folder): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return null;
    }

    $mime = mime_content_type($tmpName) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        return null;
    }

    $baseDir = __DIR__ . '/../../public/uploads/' . trim($folder, '/');
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0775, true);
    }

    $fileName = $folder . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $targetPath = $baseDir . '/' . $fileName;
    if (move_uploaded_file($tmpName, $targetPath)) {
        return $fileName;
    }
    return null;
}

// Check for missing columns
ensureInventoryColumn($pdo, 'asset_accessories', 'warranty_months', 'INT DEFAULT 0');
ensureInventoryColumn($pdo, 'asset_accessories', 'purchase_date', 'DATE NULL');
ensureInventoryColumn($pdo, 'asset_accessories', 'deleted_at', 'DATETIME NULL');
ensureInventoryColumn($pdo, 'asset_consumables', 'serial_no', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_accessories', 'serial_no', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_consumables', 'deleted_at', 'DATETIME NULL');
ensureInventoryColumn($pdo, 'asset_licenses', 'deleted_at', 'DATETIME NULL');
ensureInventoryColumn($pdo, 'asset_consumables', 'deleted_at', 'DATETIME NULL');
ensureInventoryColumn($pdo, 'asset_components', 'deleted_at', 'DATETIME NULL');
ensureInventoryColumn($pdo, 'asset_accessories', 'image', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_consumables', 'image', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_components', 'image', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_licenses', 'image', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_consumables', 'company_id', 'INT NULL');
ensureInventoryColumn($pdo, 'asset_consumables', 'department_id', 'INT NULL');
ensureInventoryColumn($pdo, 'asset_components', 'company_id', 'INT NULL');
ensureInventoryColumn($pdo, 'asset_components', 'department_id', 'INT NULL');
ensureInventoryColumn($pdo, 'asset_accessories', 'department_id', 'INT NULL');
ensureInventoryColumn($pdo, 'asset_categories', 'image', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_categories', 'notes', 'TEXT NULL');
ensureInventoryColumn($pdo, 'asset_timeline', 'is_deleted', 'TINYINT DEFAULT 0'); // For log management
ensureInventoryColumn($pdo, 'asset_suppliers', 'address', 'TEXT NULL');
ensureInventoryColumn($pdo, 'asset_suppliers', 'phone', 'VARCHAR(50) NULL');
ensureInventoryColumn($pdo, 'asset_suppliers', 'email', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_suppliers', 'contact_person', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_suppliers', 'website', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_suppliers', 'city', 'VARCHAR(100) NULL');
ensureInventoryColumn($pdo, 'asset_suppliers', 'country', 'VARCHAR(100) NULL');
ensureInventoryColumn($pdo, 'asset_suppliers', 'zip', 'VARCHAR(20) NULL');
ensureInventoryColumn($pdo, 'asset_suppliers', 'image', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_companies', 'address', 'TEXT NULL');
ensureInventoryColumn($pdo, 'asset_companies', 'phone', 'VARCHAR(50) NULL');
ensureInventoryColumn($pdo, 'asset_companies', 'website', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'asset_companies', 'tax_number', 'VARCHAR(50) NULL');
ensureInventoryColumn($pdo, 'bolumler', 'responsible_person', 'VARCHAR(255) NULL');

function getSingularType(string $view): string
{
    return match ($view) {
        'assets' => 'asset',
        'licenses' => 'license',
        'accessories' => 'accessory',
        'consumables' => 'consumable',
        'components' => 'component',
        default => rtrim($view, 's')
    };
}

function normalizeDepartmentId(PDO $pdo, mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    if (is_float($value) && floor($value) == $value) {
        $value = (int) $value;
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $trimmed)) {
            return (int) $trimmed;
        }
    }

    $candidate = trim((string) $value);
    if ($candidate === '') {
        return null;
    }

    $id = filter_var($candidate, FILTER_VALIDATE_INT);
    if ($id !== false) {
        return $id > 0 ? $id : null;
    }

    $stmt = $pdo->prepare("SELECT id FROM bolumler WHERE LOWER(TRIM(bolum_adi)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute([$candidate]);
    $resolved = $stmt->fetchColumn();
    if ($resolved !== false && $resolved !== null && $resolved !== '') {
        return (int) $resolved;
    }

    $stmt = $pdo->prepare("SELECT id FROM bolumler WHERE LOWER(TRIM(bolum_adi)) LIKE LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute(['%' . $candidate . '%']);
    $resolved = $stmt->fetchColumn();
    if ($resolved !== false && $resolved !== null && $resolved !== '') {
        return (int) $resolved;
    }

    return null;
}

function currencySymbol(?string $currency): string
{
    return match (strtoupper((string) $currency)) {
        'USD' => '$',
        'EUR' => '&#8364;',
        default => '&#8378;',
    };
}

function inventoryTableMeta(string $type): array
{
    return match ($type) {
        'categories' => ['table' => 'asset_categories', 'name_column' => 'name', 'notes_column' => 'notes'],
        'models' => ['table' => 'asset_models', 'name_column' => 'name', 'notes_column' => 'notes'],
        'manufacturers' => ['table' => 'asset_manufacturers', 'name_column' => 'name', 'notes_column' => 'notes'],
        'suppliers' => ['table' => 'asset_suppliers', 'name_column' => 'name', 'notes_column' => 'notes'],
        'companies' => ['table' => 'asset_companies', 'name_column' => 'name', 'notes_column' => 'notes'],
        'departments' => ['table' => 'bolumler', 'name_column' => 'bolum_adi', 'notes_column' => 'notes'],
        'locations' => ['table' => 'asset_locations', 'name_column' => 'name', 'notes_column' => 'notes'],
        'status_labels' => ['table' => 'asset_status_labels', 'name_column' => 'name', 'notes_column' => 'description'],
        'depreciation' => ['table' => 'asset_depreciations', 'name_column' => 'name', 'notes_column' => 'description'],
        'custom_fields' => ['table' => 'inventory_custom_fields', 'name_column' => 'field_label', 'notes_column' => 'field_group'],
        'users' => ['table' => 'users', 'name_column' => 'fullname', 'notes_column' => null],
        'assets' => ['table' => 'assets', 'name_column' => 'name', 'notes_column' => 'asset_tag'],
        default => ['table' => 'asset_categories', 'name_column' => 'name', 'notes_column' => 'notes'],
    };
}

if (!in_array($current_user_role, [1, 2, 3])) {
    $_SESSION['mesaj'] = __("Hata") . ": " . __("no_permission_page");
    header("Location: anasayfa");
    exit;
}

$view = $_GET['view'] ?? 'assets';
if ($view === 'people') {
    header("Location: kullanici-listele");
    exit;
}
if (!in_array($view, ['assets', 'licenses', 'accessories', 'consumables', 'components', 'predefined', 'timeline', 'signatures']))
    $view = 'assets';

function getPersonnelCategoryCounts(PDO $pdo, int $uid): array {
    $uid = (int)$uid;
    $counts = [
        'assets' => 0,
        'licenses' => 0,
        'accessories' => 0,
        'consumables' => 0,
        'components' => 0
    ];
    try {
        $counts['assets'] = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE assigned_user_id = $uid AND deleted_at IS NULL")->fetchColumn();

        $counts['licenses'] = (int)$pdo->query("SELECT COUNT(*) FROM asset_licenses l WHERE l.deleted_at IS NULL AND (l.assigned_user_id = $uid OR l.id IN (SELECT alc.license_id FROM asset_license_checkouts alc LEFT JOIN assets a ON alc.asset_id = a.id WHERE alc.user_id = $uid OR alc.assigned_user_id = $uid OR a.assigned_user_id = $uid))")->fetchColumn();

        $counts['accessories'] = (int)$pdo->query("SELECT COUNT(*) FROM asset_accessories a WHERE a.deleted_at IS NULL AND (a.assigned_user_id = $uid OR a.id IN (SELECT aac.accessory_id FROM asset_accessory_checkouts aac LEFT JOIN assets ast ON aac.asset_id = ast.id WHERE aac.user_id = $uid OR aac.assigned_user_id = $uid OR ast.assigned_user_id = $uid))")->fetchColumn();

        $counts['consumables'] = (int)$pdo->query("SELECT COUNT(*) FROM asset_consumables c WHERE c.deleted_at IS NULL AND (c.assigned_user_id = $uid OR c.id IN (SELECT acc.consumable_id FROM asset_consumable_checkouts acc LEFT JOIN assets ast ON acc.asset_id = ast.id WHERE acc.user_id = $uid OR acc.assigned_user_id = $uid OR ast.assigned_user_id = $uid))")->fetchColumn();

        $counts['components'] = (int)$pdo->query("SELECT COUNT(*) FROM asset_components ca WHERE ca.deleted_at IS NULL AND (ca.assigned_user_id = $uid OR ca.asset_id IN (SELECT id FROM assets WHERE assigned_user_id = $uid) OR ca.id IN (SELECT acc.component_id FROM asset_component_checkouts acc LEFT JOIN assets ast ON acc.asset_id = ast.id WHERE acc.user_id = $uid OR acc.assigned_user_id = $uid OR acc.assigned_asset_id IN (SELECT id FROM assets WHERE assigned_user_id = $uid) OR ast.assigned_user_id = $uid))")->fetchColumn();
    } catch (Throwable $e) {}

    return $counts;
}



// Pagination setup
$current_page = max(1, intval($_GET['page'] ?? 1));
$limit = intval($_GET['limit'] ?? 50);
$offset = ($current_page - 1) * $limit;
$total_records = 0;
$total_pages = 0;

ensureInventoryColumn($pdo, 'asset_models', 'image', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'assets', 'image', 'VARCHAR(255) NULL');
ensureInventoryColumn($pdo, 'assets', 'purchase_currency', "VARCHAR(10) NOT NULL DEFAULT 'TRY'");
ensureInventoryColumn($pdo, 'asset_accessories', 'supplier_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_consumables', 'supplier_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_components', 'supplier_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_consumables', 'purchase_currency', "VARCHAR(10) NOT NULL DEFAULT 'TRY'");
ensureInventoryColumn($pdo, 'asset_consumables', 'asset_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_components', 'purchase_currency', "VARCHAR(10) NOT NULL DEFAULT 'TRY'");

ensureInventoryColumn($pdo, 'asset_licenses', 'license_email', "VARCHAR(255) NULL");
ensureInventoryColumn($pdo, 'asset_licenses', 'license_name', "VARCHAR(255) NULL");
ensureInventoryColumn($pdo, 'asset_licenses', 'purchase_cost', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
ensureInventoryColumn($pdo, 'asset_licenses', 'purchase_currency', "VARCHAR(10) NOT NULL DEFAULT 'TRY'");
ensureInventoryColumn($pdo, 'asset_licenses', 'notes', "TEXT NULL");
ensureInventoryColumn($pdo, 'bolumler', 'notes', "TEXT NULL");
ensureInventoryColumn($pdo, 'bolumler', 'responsible_person', "VARCHAR(255) NULL");
ensureInventoryColumn($pdo, 'asset_manufacturers', 'notes', "TEXT NULL");
ensureInventoryColumn($pdo, 'asset_manufacturers', 'image', "VARCHAR(255) NULL");
ensureInventoryColumn($pdo, 'asset_companies', 'image', "VARCHAR(255) NULL");
ensureInventoryColumn($pdo, 'asset_companies', 'tax_number', "VARCHAR(50) NULL");

ensureInventoryColumn($pdo, 'asset_licenses', 'company_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_licenses', 'location_id', "INT NULL");

ensureInventoryColumn($pdo, 'asset_licenses', 'company_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_licenses', 'location_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_licenses', 'asset_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_accessories', 'asset_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_components', 'asset_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_accessory_checkouts', 'quantity', "INT NOT NULL DEFAULT 1");
ensureInventoryColumn($pdo, 'asset_license_checkouts', 'quantity', "INT NOT NULL DEFAULT 1");
ensureInventoryColumn($pdo, 'asset_consumable_checkouts', 'transaction_type', "VARCHAR(20) NOT NULL DEFAULT 'consume'");

ensureInventoryColumn($pdo, 'asset_licenses', 'assigned_user_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_accessories', 'assigned_user_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_consumables', 'assigned_user_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_components', 'assigned_user_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_license_checkouts', 'assigned_user_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_accessory_checkouts', 'assigned_user_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_consumable_checkouts', 'assigned_user_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_component_checkouts', 'assigned_user_id', "INT NULL");
ensureInventoryColumn($pdo, 'asset_component_checkouts', 'assigned_asset_id', "INT NULL");

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        $uploadedImage = null;
        require_csrf_token();
        $action = $_POST['action'] ?? '';

    // ===== SARF MALZEME HAREKET GEÇMİŞİ SİLME =====
    if ($action === 'bulk_delete_consumable_logs') {
        $raw_ids = trim($_POST['ids'] ?? '');
        $raw_types = trim($_POST['types'] ?? ''); // 'checkout' or 'timeline' per id

        if ($raw_ids === 'all') {
            // Tüm kayıtları sil
            $pdo->exec("DELETE FROM asset_consumable_checkouts");
            $pdo->exec("DELETE FROM asset_timeline WHERE item_type = 'consumable'");
        } else {
            $ids = array_filter(array_map('intval', explode(',', $raw_ids)));
            $types = explode(',', $raw_types);

            if (!empty($ids)) {
                $checkout_ids = [];
                $timeline_ids = [];

                foreach ($ids as $i => $id) {
                    $t = trim($types[$i] ?? 'checkout');
                    if ($t === 'timeline') {
                        $timeline_ids[] = $id;
                    } else {
                        $checkout_ids[] = $id;
                    }
                }

                if (!empty($checkout_ids)) {
                    $ph = implode(',', array_fill(0, count($checkout_ids), '?'));
                    $pdo->prepare("DELETE FROM asset_consumable_checkouts WHERE id IN ($ph)")->execute($checkout_ids);
                }
                if (!empty($timeline_ids)) {
                    $ph = implode(',', array_fill(0, count($timeline_ids), '?'));
                    $pdo->prepare("DELETE FROM asset_timeline WHERE id IN ($ph)")->execute($timeline_ids);
                }
            }
        }

        $_SESSION['success'] = $isTr ? 'Seçilen hareket kayıtları silindi.' : 'Selected logs deleted.';
        header('Location: varliklar?view=consumables#history-card');
        exit;
    }

    if ($action == 'save_predefined') {
        $type = $_POST['type'];
        $id = $_POST['id'];
        $name = $_POST['name'];
        $notes = $_POST['notes'] ?? '';
        $uploadedImage = in_array($type, ['models', 'categories', 'suppliers', 'companies', 'manufacturers']) ? uploadInventoryImage($_FILES['image'] ?? [], $type) : null;

        $meta = inventoryTableMeta($type);
        $table = $meta['table'];
        $nameColumn = $meta['name_column'];
        $notesColumn = $meta['notes_column'];
        if ($notesColumn !== null && !tableHasColumn($pdo, $table, $notesColumn)) {
            $notesColumn = null;
        }

        if ($id) {
            $oldPredefined = $pdo->query("SELECT * FROM $table WHERE id = " . intval($id))->fetch(PDO::FETCH_ASSOC);
            $logDetailsArr = [];

            if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {
                if (tableHasColumn($pdo, $table, 'image')) {
                    $pdo->prepare("UPDATE $table SET image = NULL WHERE id = ?")->execute([$id]);
                    $logDetailsArr[] = $isTr ? "Görsel silindi" : "Image removed";
                }
            }

            if ($type === 'models') {
                $manufacturer_id = intval($_POST['manufacturer_id'] ?? 0);
                $category_id = intval($_POST['category_id'] ?? 0);
                $model_number = $_POST['model_number'] ?? '';
                $eol = intval($_POST['eol'] ?? 0);
                $field_group = $_POST['field_group'] ?? '';
                $depreciation_id = intval($_POST['depreciation_id'] ?? 0);
                $min_amt = intval($_POST['min_amt'] ?? 0);
                $show_serial = isset($_POST['show_serial']) ? 1 : 0;
                $notes = $_POST['notes'] ?? '';

                if ($uploadedImage !== null) {
                    $stmt = $pdo->prepare("UPDATE $table SET $nameColumn = ?, image = ?, manufacturer_id = ?, category_id = ?, model_number = ?, eol = ?, field_group = ?, depreciation_id = ?, min_amt = ?, show_serial = ?, notes = ? WHERE id = ?");
                    $stmt->execute([$name, $uploadedImage, $manufacturer_id, $category_id, $model_number, $eol, $field_group, $depreciation_id, $min_amt, $show_serial, $notes, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE $table SET $nameColumn = ?, manufacturer_id = ?, category_id = ?, model_number = ?, eol = ?, field_group = ?, depreciation_id = ?, min_amt = ?, show_serial = ?, notes = ? WHERE id = ?");
                    $stmt->execute([$name, $manufacturer_id, $category_id, $model_number, $eol, $field_group, $depreciation_id, $min_amt, $show_serial, $notes, $id]);
                }
            } elseif ($type === 'categories') {
                $parent_id = postNullableInt('parent_id');
                $name_en = $_POST['name_en'] ?? null;

                $sets = [];
                $params_update = [];
                $sets[] = "$nameColumn = ?";
                $params_update[] = $name;
                if ($notesColumn !== null) {
                    $sets[] = "$notesColumn = ?";
                    $params_update[] = $notes;
                }
                if (tableHasColumn($pdo, $table, 'name_en')) {
                    $sets[] = "name_en = ?";
                    $params_update[] = $name_en;
                }
                if (tableHasColumn($pdo, $table, 'parent_id')) {
                    $sets[] = "parent_id = ?";
                    $params_update[] = $parent_id;
                }
                if ($uploadedImage !== null) {
                    $sets[] = "image = ?";
                    $params_update[] = $uploadedImage;
                }

                $sql = "UPDATE $table SET " . implode(', ', $sets) . " WHERE id = ?";
                $params_update[] = $id;
                $pdo->prepare($sql)->execute($params_update);
                // update normalized type if inventory_type provided
                if (!empty($_POST['inventory_type'])) {
                    $invType = normalizeInventoryCategoryType($_POST['inventory_type']);
                    $pdo->prepare("UPDATE asset_categories SET type = ? WHERE id = ?")->execute([$invType, $id]);
                }
            } elseif ($type === 'suppliers') {
                $address = $_POST['address'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $email = $_POST['email'] ?? '';
                $contact = $_POST['contact_person'] ?? '';
                $website = $_POST['website'] ?? '';
                $city = $_POST['city'] ?? '';
                $country = $_POST['country'] ?? '';
                $zip = $_POST['zip'] ?? '';

                $sets = ["$nameColumn = ?", "$notesColumn = ?", "address = ?", "phone = ?", "email = ?", "contact_person = ?", "website = ?", "city = ?", "country = ?", "zip = ?"];
                $params = [$name, $notes, $address, $phone, $email, $contact, $website, $city, $country, $zip];
                if ($uploadedImage !== null) {
                    $sets[] = "image = ?";
                    $params[] = $uploadedImage;
                }
                $params[] = $id;
                $pdo->prepare("UPDATE asset_suppliers SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            } elseif ($type === 'companies') {
                $phone = $_POST['phone'] ?? '';
                $website = $_POST['website'] ?? '';
                $tax = $_POST['tax_number'] ?? '';
                $address = $_POST['address'] ?? '';
                $sets = ["name = ?", "phone = ?", "website = ?", "tax_number = ?", "address = ?", "notes = ?"];
                $params = [$name, $phone, $website, $tax, $address, $notes];
                if ($uploadedImage !== null) {
                    $sets[] = "image = ?";
                    $params[] = $uploadedImage;
                }
                $params[] = $id;
                $pdo->prepare("UPDATE asset_companies SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            } elseif ($type === 'departments') {
                $responsible = $_POST['responsible_person'] ?? '';
                $sets = ["bolum_adi = ?", "responsible_person = ?"];
                $params = [$name, $responsible];
                if (tableHasColumn($pdo, 'bolumler', 'notes')) {
                    $sets[] = "notes = ?";
                    $params[] = $notes;
                }
                $params[] = $id;
                $pdo->prepare("UPDATE bolumler SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            } elseif ($type === 'manufacturers') {
                $sets = ["$nameColumn = ?"];
                $params = [$name];
                if (tableHasColumn($pdo, 'asset_manufacturers', 'notes')) {
                    $sets[] = "notes = ?";
                    $params[] = $notes;
                }
                if ($uploadedImage !== null) {
                    $sets[] = "image = ?";
                    $params[] = $uploadedImage;
                }
                $params[] = $id;
                $pdo->prepare("UPDATE asset_manufacturers SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            } elseif ($notesColumn !== null) {
                $stmt = $pdo->prepare("UPDATE $table SET $nameColumn = ?, $notesColumn = ? WHERE id = ?");
                $stmt->execute([$name, $notes, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE $table SET $nameColumn = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
            }

            // Special handling for status labels
            if ($type === 'status_labels') {
                $statusType = $_POST['status_type'] ?? 'pending';
                $color = $_POST['color'] ?? '#3b82f6';
                $showNav = isset($_POST['show_in_nav']) ? 1 : 0;
                $isDefault = isset($_POST['is_default']) ? 1 : 0;
                if ($isDefault)
                    $pdo->exec("UPDATE asset_status_labels SET is_default = 0");
                $pdo->prepare("UPDATE asset_status_labels SET type = ?, color = ?, show_in_nav = ?, is_default = ? WHERE id = ?")
                    ->execute([$statusType, $color, $showNav, $isDefault, $id]);
            }

            if ($type === 'custom_fields') {
                $fieldName = $_POST['field_name'] ?? '';
                $fieldType = $_POST['field_type'] ?? 'text';
                $fieldGroup = $_POST['field_group_manual'] ?? $_POST['field_group'] ?? 'HardwareInfo';
                $cat_id = intval($_POST['category_id'] ?? 0);
                $options = $_POST['options'] ?? '';
                $status = isset($_POST['status']) ? 1 : 0;
                $pdo->prepare("UPDATE inventory_custom_fields SET field_name = ?, field_type = ?, field_group = ?, category_id = ?, options = ?, status = ? WHERE id = ?")
                    ->execute([$fieldName, $fieldType, $fieldGroup, $cat_id, $options, $status, $id]);
            }
            
            $typeLabelTr = ['models' => 'Model', 'categories' => 'Kategori', 'suppliers' => 'Tedarikçi', 'companies' => 'Şirket', 'departments' => 'Departman', 'manufacturers' => 'Üretici'][$type] ?? $type;
            $typeLabelEn = ['models' => 'Model', 'categories' => 'Category', 'suppliers' => 'Supplier', 'companies' => 'Company', 'departments' => 'Department', 'manufacturers' => 'Manufacturer'][$type] ?? $type;
            
            $logMsg = $isTr ? "{$typeLabelTr} güncellendi: $name" : "{$typeLabelEn} updated: $name";
            if ($uploadedImage !== null) {
                $logMsg = $isTr ? "{$typeLabelTr} güncellendi (Resim eklendi/değiştirildi): $name" : "{$typeLabelEn} updated (Image added/changed): $name";
            }
            $item_type_map = ['models' => 'model', 'categories' => 'category', 'suppliers' => 'supplier', 'companies' => 'company', 'departments' => 'department', 'manufacturers' => 'manufacturer'];
            $log_item_type = $item_type_map[$type] ?? 'predefined';
            
            addAssetLog($pdo, $id, $current_user_id, 'timeline_updated', $logMsg, null, $log_item_type);

            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => true, 'id' => $id, 'name' => $name]);
                exit;
            }

            $_SESSION['mesaj'] = $isTr ? "Başarıyla güncellendi." : "Updated successfully.";
            $redirType = urlencode($type);
            $redirCat = (isset($_POST['category_id']) && !empty($_POST['category_id'])) ? "&cat_id=" . intval($_POST['category_id']) : "";
            header("Location: varliklar?view=predefined&type=$redirType$redirCat");
            exit;
        } else {
            if ($type === 'models') {
                $manufacturer_id = intval($_POST['manufacturer_id'] ?? 0);
                $category_id = intval($_POST['category_id'] ?? 0);
                $model_number = $_POST['model_number'] ?? '';
                $eol = intval($_POST['eol'] ?? 0);
                $field_group = $_POST['field_group'] ?? '';
                $depreciation_id = intval($_POST['depreciation_id'] ?? 0);
                $min_amt = intval($_POST['min_amt'] ?? 0);
                $show_serial = isset($_POST['show_serial']) ? 1 : 0;
                $notes = $_POST['notes'] ?? '';

                $stmt = $pdo->prepare("INSERT INTO $table ($nameColumn, image, manufacturer_id, category_id, model_number, eol, field_group, depreciation_id, min_amt, show_serial, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $uploadedImage, $manufacturer_id, $category_id, $model_number, $eol, $field_group, $depreciation_id, $min_amt, $show_serial, $notes]);
            } elseif ($type === 'categories') {
                $parent_id = postNullableInt('parent_id');
                $name_en = $_POST['name_en'] ?? null;

                $cols = [$nameColumn];
                $placeholders = ['?'];
                $params_ins = [$name];
                if ($notesColumn !== null) {
                    $cols[] = $notesColumn;
                    $placeholders[] = '?';
                    $params_ins[] = $notes;
                }
                if (tableHasColumn($pdo, $table, 'name_en')) {
                    $cols[] = 'name_en';
                    $placeholders[] = '?';
                    $params_ins[] = $name_en;
                }
                if (tableHasColumn($pdo, $table, 'parent_id')) {
                    $cols[] = 'parent_id';
                    $placeholders[] = '?';
                    $params_ins[] = $parent_id;
                }
                if ($uploadedImage !== null) {
                    $cols[] = 'image';
                    $placeholders[] = '?';
                    $params_ins[] = $uploadedImage;
                }

                $sql = "INSERT INTO $table (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $pdo->prepare($sql)->execute($params_ins);
                $id = $pdo->lastInsertId();

                // save normalized type if provided
                if (!empty($_POST['inventory_type'])) {
                    $invType = normalizeInventoryCategoryType($_POST['inventory_type']);
                    $pdo->prepare("UPDATE asset_categories SET type = ? WHERE id = ?")->execute([$invType, $id]);
                }
            } elseif ($type === 'suppliers') {
                $address = $_POST['address'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $email = $_POST['email'] ?? '';
                $contact = $_POST['contact_person'] ?? '';
                $website = $_POST['website'] ?? '';
                $city = $_POST['city'] ?? '';
                $country = $_POST['country'] ?? '';
                $zip = $_POST['zip'] ?? '';

                $sql = "INSERT INTO asset_suppliers (name, notes, address, phone, email, contact_person, website, city, country, zip, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([$name, $notes, $address, $phone, $email, $contact, $website, $city, $country, $zip, $uploadedImage]);
            } elseif ($type === 'custom_fields') {
                $fieldName = $_POST['field_name'] ?? '';
                if (empty($fieldName)) {
                    $fieldName = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $name), '_'));
                }
                $fieldType = $_POST['field_type'] ?? 'text';
                $fieldGroup = $_POST['field_group_manual'] ?? $_POST['field_group'] ?? 'HardwareInfo';
                $cat_id = intval($_POST['category_id'] ?? 0);
                $options = $_POST['options'] ?? '';
                $status = isset($_POST['status']) ? 1 : 0;

                $stmt = $pdo->prepare("INSERT INTO inventory_custom_fields (field_label, field_name, field_type, field_group, category_id, options, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $fieldName, $fieldType, $fieldGroup, $cat_id, $options, $status]);
            } elseif ($type === 'companies') {
                $phone = $_POST['phone'] ?? '';
                $website = $_POST['website'] ?? '';
                $tax = $_POST['tax_number'] ?? '';
                $address = $_POST['address'] ?? '';
                $cols = ["name", "phone", "website", "tax_number", "address", "notes"];
                $placeholders = ["?", "?", "?", "?", "?", "?"];
                $params = [$name, $phone, $website, $tax, $address, $notes];
                if ($uploadedImage !== null) {
                    $cols[] = "image";
                    $placeholders[] = "?";
                    $params[] = $uploadedImage;
                }
                $pdo->prepare("INSERT INTO asset_companies (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")")
                    ->execute($params);
            } elseif ($type === 'departments') {
                $responsible = $_POST['responsible_person'] ?? '';
                $cols = ["bolum_adi", "responsible_person"];
                $placeholders = ["?", "?"];
                $params = [$name, $responsible];
                if (tableHasColumn($pdo, 'bolumler', 'notes')) {
                    $cols[] = "notes";
                    $placeholders[] = "?";
                    $params[] = $notes;
                }
                $pdo->prepare("INSERT INTO bolumler (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")")
                    ->execute($params);
            } elseif ($type === 'manufacturers') {
                $cols = ["name"];
                $placeholders = ["?"];
                $params = [$name];
                if (tableHasColumn($pdo, 'asset_manufacturers', 'notes')) {
                    $cols[] = "notes";
                    $placeholders[] = "?";
                    $params[] = $notes;
                }
                if ($uploadedImage !== null) {
                    $cols[] = "image";
                    $placeholders[] = "?";
                    $params[] = $uploadedImage;
                }
                $pdo->prepare("INSERT INTO asset_manufacturers (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")")
                    ->execute($params);
            } elseif ($notesColumn !== null) {
                $stmt = $pdo->prepare("INSERT INTO $table ($nameColumn, $notesColumn) VALUES (?, ?)");
                $stmt->execute([$name, $notes]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO $table ($nameColumn) VALUES (?)");
                $stmt->execute([$name]);
            }

            // Capture ID for special handling
            $id = $pdo->lastInsertId() ?: $id;

            // Special handling for status labels
            if ($type === 'status_labels') {
                $statusType = $_POST['status_type'] ?? 'pending';
                $color = $_POST['color'] ?? '#3b82f6';
                $showNav = isset($_POST['show_in_nav']) ? 1 : 0;
                $isDefault = isset($_POST['is_default']) ? 1 : 0;
                if ($isDefault)
                    $pdo->exec("UPDATE asset_status_labels SET is_default = 0");
                
                $pdo->prepare("UPDATE asset_status_labels SET type = ?, color = ?, show_in_nav = ?, is_default = ? WHERE id = ?")
                    ->execute([$statusType, $color, $showNav, $isDefault, $id]);
            }

            if ($type === 'custom_fields') {
                $fieldName = $_POST['field_name'] ?? '';
                $fieldType = $_POST['field_type'] ?? 'text';
                $fieldGroup = $_POST['field_group_manual'] ?? $_POST['field_group'] ?? 'HardwareInfo';
                $cat_id = intval($_POST['category_id'] ?? 0);
                $options = $_POST['options'] ?? '';
                $status = isset($_POST['status']) ? 1 : 0;
                $pdo->prepare("UPDATE inventory_custom_fields SET field_name = ?, field_type = ?, field_group = ?, category_id = ?, options = ?, status = ? WHERE id = ?")
                    ->execute([$fieldName, $fieldType, $fieldGroup, $cat_id, $options, $status, $id]);
            }
            
            $typeLabelTr = ['models' => 'Model', 'categories' => 'Kategori', 'suppliers' => 'Tedarikçi', 'companies' => 'Şirket', 'departments' => 'Departman', 'manufacturers' => 'Üretici'][$type] ?? $type;
            $typeLabelEn = ['models' => 'Model', 'categories' => 'Category', 'suppliers' => 'Supplier', 'companies' => 'Company', 'departments' => 'Department', 'manufacturers' => 'Manufacturer'][$type] ?? $type;
            
            $logMsg = $isTr ? "Yeni {$typeLabelTr} eklendi: $name" : "New {$typeLabelEn} added: $name";
            if ($uploadedImage !== null) {
                $logMsg = $isTr ? "Yeni {$typeLabelTr} eklendi (Resimli): $name" : "New {$typeLabelEn} added (With Image): $name";
            }
            $item_type_map = ['models' => 'model', 'categories' => 'category', 'suppliers' => 'supplier', 'companies' => 'company', 'departments' => 'department', 'manufacturers' => 'manufacturer'];
            $log_item_type = $item_type_map[$type] ?? 'predefined';
            
            addAssetLog($pdo, $id, $current_user_id, 'timeline_created', $logMsg, null, $log_item_type);

            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => true, 'id' => $id, 'name' => $name]);
                exit;
            }

            $_SESSION['mesaj'] = $isTr ? "Başarıyla eklendi." : "Added successfully.";
            $redirType = urlencode($type);
            $redirCat = (isset($_POST['category_id']) && !empty($_POST['category_id'])) ? "&cat_id=" . intval($_POST['category_id']) : "";
            
            if ($type === 'categories' && isset($_POST['tour']) && $_POST['tour'] === 'category') {
                header("Location: varliklar?view=predefined&type=models&tour=models");
                exit;
            }

            header("Location: varliklar?view=predefined&type=$redirType$redirCat");
            exit;
        }
    } elseif ($action == 'update_group_links') {
        $group = $_POST['group_name'] ?? '';
        $cat_ids = $_POST['cat_ids'] ?? [];
        if ($group !== '') {
            $pdo->prepare("DELETE FROM inventory_field_group_links WHERE field_group = ? AND field_group > ''")->execute([$group]);
            if (!empty($cat_ids)) {
                $stmt = $pdo->prepare("INSERT INTO inventory_field_group_links (category_id, field_group) VALUES (?, ?)");
                foreach ($cat_ids as $cid) {
                    $stmt->execute([intval($cid), $group]);
                }
            }
            $_SESSION['mesaj'] = $isTr ? "Kategori bağlantıları güncellendi." : "Category links updated.";
        }
        header("Location: varliklar?view=predefined&type=custom_fields");
        exit;
    } elseif ($_POST['action'] == 'rename_field_group') {
        $old_name = $_POST['old_group'] ?? '';
        $new_name = $_POST['new_group'] ?? '';
        if ($old_name !== '' && $new_name !== '') {
            $stmt = $pdo->prepare("UPDATE inventory_custom_fields SET field_group = ? WHERE field_group = ?");
            $stmt->execute([$new_name, $old_name]);
            $_SESSION['mesaj'] = $isTr ? "Alan grubu başarıyla güncellendi." : "Field group renamed successfully.";
        }
        header("Location: varliklar?view=predefined&type=custom_fields");
        exit;
    } elseif ($action == 'check_signature_type') {
        ob_clean();
        header('Content-Type: application/json');
        $asset_id = intval($_POST['asset_id'] ?? 0);
        $res = ['requires_digital' => false, 'assigned' => false];
        if ($asset_id > 0) {
            $ast = $pdo->query("SELECT assigned_user_id, asset_id FROM assets WHERE id = $asset_id")->fetch();
            if ($ast && ($ast['assigned_user_id'] > 0 || $ast['asset_id'] > 0)) {
                $res['assigned'] = true;
                if ($ast['assigned_user_id'] > 0) {
                    $sig = $pdo->prepare("SELECT status, bypass_user_signature FROM asset_signatures WHERE asset_id = ? AND user_id = ? AND action_type = 'checkout' ORDER BY id DESC LIMIT 1");
                    $sig->execute([$asset_id, $ast['assigned_user_id']]);
                    $sigRow = $sig->fetch();
                    if ($sigRow) {
                        if ($sigRow['status'] === 'pending_user' || $sigRow['status'] === 'pending_admin' || ($sigRow['status'] === 'approved' && !$sigRow['bypass_user_signature'])) {
                            $res['requires_digital'] = true;
                        }
                    }
                }
            }
        }
        echo json_encode($res);
        exit;
    } elseif ($_POST['action'] == 'save') {
        $view_submit = $_POST['view'] ?? 'assets';
        $id = postInt('asset_id');
        $name = postValue('name', '');
        $asset_tag = postValue('asset_tag', '');
        $serial = postValue('serial_no', '');

        if ($view_submit == 'assets') {
            $uploadedImage = uploadInventoryImage($_FILES['image'] ?? [], 'assets');
            $model_id = postNullableInt('model_id');
            $cat_id = postNullableInt('category_id');

            if (empty($name)) {
                $_SESSION['hata'] = $isTr ? "Demirbaş Adı alanı zorunludur." : "Asset Name is required.";
                header("Location: varliklar?view=assets");
                exit;
            }

            $ip = postValue('ip_address', '');
            $mac = postValue('mac_address', '');
            $location = postValue('location', '');
            $old = null;
            if ($id > 0) {
                $old = $pdo->query("SELECT * FROM assets WHERE id = $id")->fetch();
            }

            $assigned = postNullableInt('assigned_user_id');
            if ($assigned === null && $old) {
                $assigned = $old['assigned_user_id'];
            }

            $notes = postValue('notes', '');
            $statusId = postNullableInt('status_id');
            $status = postInt('status', 1); // fallback
            $cost = postFloat('purchase_cost', 0);
            $purchaseCurrency = strtoupper(postValue('purchase_currency', 'TRY') ?: 'TRY');
            $companyId = postNullableInt('company_id');
            $defaultLocation = postValue('default_location', '');
            $requestable = isset($_POST['requestable']) ? 1 : 0;
            $ipSecondary = postValue('ip_secondary', '');
            $departmentId = postNullableInt('department_id');

            $targetAssetId = postNullableInt('asset_id_assigned');
            if ($targetAssetId === null && $old) {
                $targetAssetId = $old['asset_id'];
            }

            // Enforce that we cannot assign a device while it's Scrap or Faulty
            if ($assigned > 0 || $targetAssetId > 0) {
                if ($statusId) {
                    $statusTypeCheck = $pdo->query("SELECT type, name FROM asset_status_labels WHERE id = $statusId")->fetch();
                    if ($statusTypeCheck && ($statusTypeCheck['type'] === 'undeployable' || $statusTypeCheck['type'] === 'archived')) {
                        $statusNameNorm = normalize_turkish_mojibake($statusTypeCheck['name'] ?? '');
                        $_SESSION['hata'] = $isTr 
                            ? "Bu cihaz '{$statusNameNorm}' durumundayken atama yapılamaz. Önce durumunu Hazır yapmalısınız." 
                            : "Cannot assign this device while its status is '{$statusNameNorm}'. You must change it to Ready first.";
                        header("Location: varliklar?view=assets");
                        exit;
                    }
                }
            }

            // --- STATUS CHECK FOR ASSIGNMENT ---
            // If status is undeployable (like 'Arızalı'), we must ensure it's not assigned.
            if ($statusId) {
                $statusType = $pdo->query("SELECT type, name FROM asset_status_labels WHERE id = $statusId")->fetch();
                $statusTypeName = $statusType['type'] ?? 'deployable';
                $statusNameNorm = normalize_turkish_mojibake($statusType['name'] ?? '');

                if ($statusTypeName !== 'deployable') {
                    // Check if it is currently assigned in the database
                    if ($old && ($old['assigned_user_id'] > 0 || $old['asset_id'] > 0)) {
                            // Check if it requires digital signature
                            $requiresDigitalSignature = false;
                            if ($old['assigned_user_id'] > 0) {
                                $sig = $pdo->prepare("SELECT status, bypass_user_signature FROM asset_signatures WHERE asset_id = ? AND user_id = ? AND action_type = 'checkout' ORDER BY id DESC LIMIT 1");
                                $sig->execute([$id, $old['assigned_user_id']]);
                                $sigRow = $sig->fetch();
                                if ($sigRow) {
                                    if ($sigRow['status'] === 'pending_user' || $sigRow['status'] === 'pending_admin' || ($sigRow['status'] === 'approved' && !$sigRow['bypass_user_signature'])) {
                                        $requiresDigitalSignature = true;
                                    }
                                }
                            }
                            
                            if ($requiresDigitalSignature) {
                                $_SESSION['hata'] = $isTr 
                                    ? "Bu cihaz dijital onaylı/imzalı zimmet altındadır. Durumunu '{$statusNameNorm}' yapmadan önce lütfen 'Geri Al' (İade) işlemini başlatarak personelin dijital imzasını tamamlayın." 
                                    : "This device is under digitally signed assignment. Before changing status to '{$statusNameNorm}', please first initiate 'Check In' to complete the digital signature.";
                                header("Location: varliklar?view=assets");
                                exit;
                            } else {
                                // Auto-checkin for paper/wet signature
                                $logMsg = $isTr 
                                    ? "Durum '{$statusNameNorm}' yapıldığı için zimmet otomatik olarak geri alındı (Islak İmza)." 
                                    : "Automatic check-in because status changed to '{$statusNameNorm}' (Wet Signature).";
                                
                                $pdo->prepare("INSERT INTO asset_timeline (asset_id, item_type, user_id, event_type, event_description) VALUES (?, 'asset', ?, 'checkin', ?)")
                                    ->execute([$id, $_SESSION['user_id'], $logMsg]);

                                if ($old['assigned_user_id'] > 0) {
                                    $notesData = json_encode([
                                        'return_reason' => $isTr ? 'Durum Değişikliği (Arızalı/Hurda)' : 'Status Change (Faulty/Scrap)',
                                        'return_status' => $statusTypeName === 'undeployable' ? 'hasarli' : 'hasarsiz',
                                        'damage_note' => $isTr ? 'Otomatik Geri Alma' : 'Automatic Check-in'
                                    ]);
                                    $stmtIns = $pdo->prepare("INSERT INTO asset_signatures (asset_id, user_id, status, signed_at, notes, action_type, admin_id, bypass_user_signature) VALUES (?, ?, 'approved', NOW(), ?, 'checkin', ?, 1)");
                                    $stmtIns->execute([$id, $old['assigned_user_id'], $notesData, $current_user_id]);
                                }
                            }
                        }
                    $assigned = null;
                    $targetAssetId = null;
                }
            }


            // Force status to "Atanmış" (2) if assigned, or revert to "Hazır" (3) if not assigned
            if ($assigned > 0 || $targetAssetId > 0) {
                $statusId = 2;
                if ($assigned > 0 && function_exists('ensureUserApiKey')) {
                    ensureUserApiKey($pdo, $assigned);
                }
            } elseif ($statusId == 2) {
                $statusId = 3;
            }

            $disk = postValue('disk', '');
            $hddSize = mb_substr(!empty($disk) ? $disk : postValue('hdd_size', ''), 0, 500);
            $hddType = postValue('hdd_type', '');
            $mainboard = postValue('mainboard', '');
            $cpu = postValue('cpu', '');
            $ram = postValue('ram', '');
            $gpu = postValue('gpu', '');
            $monitor = postValue('monitor', '');
            $warrantyMonths = postInt('warranty_months', 0);
            $expectedCheckin = postValue('expected_checkin', '') ?: null;
            $nextAudit = postValue('next_audit', '') ?: null;
            $byod = isset($_POST['byod']) ? 1 : 0;
            $orderNumber = postValue('order_number', '');
            $eolDate = postValue('eol_date', '') ?: null;
            $supplierId = postNullableInt('supplier_id');
            $manufacturerId = postNullableInt('manufacturer_id');

            $specsArr = [];
            foreach ($_POST['spec'] ?? [] as $k => $v) {
                if ($v !== '') {
                    $specsArr[$k] = $v;
                }
            }
            foreach (($_POST['spec_key'] ?? []) as $idx => $key) {
                $key = trim((string) $key);
                $value = trim((string) (($_POST['spec_val'] ?? [])[$idx] ?? ''));
                if ($key !== '' && $value !== '') {
                    $specsArr[$key] = $value;
                }
            }
            $specsJSON = json_encode($specsArr, JSON_UNESCAPED_UNICODE);

            if (!empty($name)) {
                if ($id > 0) {
                    if ($uploadedImage) {
                        $sql = "UPDATE assets SET 
                            name=?, asset_tag=?, serial_no=?, model_id=?, category_id=?, 
                            ip_address=?, mac_address=?, " . (isset($_POST['location']) ? "location=?," : "") . " assigned_user_id=?, 
                            asset_id=?, notes=?, status=?, status_id=?, purchase_cost=?, purchase_currency=?,
                            company_id=?, default_location=?, requestable=?, ip_secondary=?, 
                            department_id=?, hdd_size=?, hdd_type=?, mainboard=?, 
                            cpu=?, ram=?, gpu=?, monitor=?, disk=?, warranty_months=?, 
                            expected_checkin=?, next_audit=?, byod=?, order_number=?, 
                            eol_date=?, supplier_id=?, manufacturer_id=?, purchase_date=?, image=? 
                            WHERE id=?";
                    } else {
                        $sql = "UPDATE assets SET 
                            name=?, asset_tag=?, serial_no=?, model_id=?, category_id=?, 
                            ip_address=?, mac_address=?, " . (isset($_POST['location']) ? "location=?," : "") . " assigned_user_id=?, 
                            asset_id=?, notes=?, status=?, status_id=?, purchase_cost=?, purchase_currency=?,
                            company_id=?, default_location=?, requestable=?, ip_secondary=?, 
                            department_id=?, hdd_size=?, hdd_type=?, mainboard=?, 
                            cpu=?, ram=?, gpu=?, monitor=?, disk=?, warranty_months=?, 
                            expected_checkin=?, next_audit=?, byod=?, order_number=?, 
                            eol_date=?, supplier_id=?, manufacturer_id=?, purchase_date=? 
                            WHERE id=?";
                    }
                    $params = [
                        $name,
                        $asset_tag,
                        $serial,
                        $model_id,
                        $cat_id,
                        $ip,
                        $mac
                    ];
                    if (isset($_POST['location']))
                        $params[] = $location;
                    array_push(
                        $params,
                        $assigned,
                        $targetAssetId,
                        $notes,
                        $status,
                        $statusId,
                        $cost,
                        $purchaseCurrency,
                        $companyId,
                        $defaultLocation,
                        $requestable,
                        $ipSecondary,
                        $departmentId,
                        $hddSize,
                        $hddType,
                        $mainboard,
                        $cpu,
                        $ram,
                        $gpu,
                        $monitor,
                        $disk,
                        $warrantyMonths,
                        ($expectedCheckin ?: null),
                        ($nextAudit ?: null),
                        $byod,
                        $orderNumber,
                        ($eolDate ?: null),
                        $supplierId,
                        $manufacturerId,
                        (!empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null)
                    );
                    if ($uploadedImage)
                        $params[] = $uploadedImage;
                    $params[] = $id;

                    $pdo->prepare($sql)->execute($params);
                    if (tableHasColumn($pdo, 'assets', 'specs')) {
                        $pdo->prepare("UPDATE assets SET specs = ? WHERE id = ?")->execute([$specsJSON, $id]);
                    }

                    // --- CHANGE DETECTION FOR LOGGING (STANDARD FIELDS) ---
                    $changesTr = [];
                    $changesEn = [];
                    $f_diff = function ($lblTr, $lblEn, $o, $n) use (&$changesTr, &$changesEn) {
                        $o = trim((string) $o);
                        $n = trim((string) $n);
                        if ($o === $n)
                            return;
                        if (empty($o)) {
                            $changesTr[] = "$lblTr eklendi: $n";
                            $changesEn[] = "$lblEn added: $n";
                        } elseif (empty($n)) {
                            $changesTr[] = "$lblTr silindi";
                            $changesEn[] = "$lblEn removed";
                        } else {
                            $changesTr[] = "$lblTr: $o -> $n";
                            $changesEn[] = "$lblEn: $o -> $n";
                        }
                    };

                    $f_diff('İsim', 'Name', $old['name'] ?? '', $name);
                    $f_diff('Etiket', 'Asset Tag', $old['asset_tag'] ?? '', $asset_tag);
                    $f_diff('Seri No', 'Serial No', $old['serial_no'] ?? '', $serial);
                    $f_diff('IP Birincil', 'Primary IP', $old['ip_address'] ?? '', $ip);
                    $f_diff('IP Yedek', 'Secondary IP', $old['ip_secondary'] ?? '', $ipSecondary);
                    $f_diff('MAC', 'MAC', $old['mac_address'] ?? '', $mac);
                    $f_diff('İşlemci', 'CPU', $old['cpu'] ?? '', $cpu);
                    $f_diff('RAM', 'RAM', $old['ram'] ?? '', $ram);
                    $f_diff('GPU', 'GPU', $old['gpu'] ?? '', $gpu);
                    $f_diff('Disk', 'Disk', !empty($old['disk']) ? $old['disk'] : ($old['hdd_size'] ?? ''), $hddSize);
                    $f_diff('Cihaz Adı', 'Device Name', $old['device_name'] ?? '', $name);
                    $f_diff('Monitör', 'Monitor', $old['monitor'] ?? '', $monitor);

                    $oldNotes = trim((string)($old['notes'] ?? ''));
                    $newNotes = trim((string)$notes);
                    if ($oldNotes !== $newNotes) {
                        if ($oldNotes === '' && $newNotes !== '') {
                            $changesTr[] = "Notlar eklendi";
                            $changesEn[] = "Notes added";
                        } elseif ($oldNotes !== '' && $newNotes === '') {
                            $changesTr[] = "Notlar silindi";
                            $changesEn[] = "Notes removed";
                        } else {
                            $changesTr[] = "Notlar güncellendi";
                            $changesEn[] = "Notes updated";
                        }
                    }

                    if (($old['model_id'] ?? 0) != ($model_id ?: 0)) {
                        $oldM = ($old['model_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_models WHERE id = " . intval($old['model_id']))->fetchColumn() ?: '') : '';
                        $newM = ($model_id ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_models WHERE id = " . intval($model_id))->fetchColumn() ?: '') : '';
                        if ($oldM !== '' && $newM !== '') {
                            $changesTr[] = "Model: $oldM → $newM";
                            $changesEn[] = "Model: $oldM → $newM";
                        } elseif ($newM !== '') {
                            $changesTr[] = "Model: $newM";
                            $changesEn[] = "Model: $newM";
                        } elseif ($oldM !== '') {
                            $changesTr[] = "Model kaldırıldı ($oldM)";
                            $changesEn[] = "Model removed ($oldM)";
                        }
                    }
                    if (($old['category_id'] ?? 0) != ($cat_id ?: 0)) {
                        $oldCat = ($old['category_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_categories WHERE id = " . intval($old['category_id']))->fetchColumn() ?: '') : '';
                        $newCat = ($cat_id ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_categories WHERE id = " . intval($cat_id))->fetchColumn() ?: '') : '';
                        if ($oldCat !== '' && $newCat !== '') {
                            $changesTr[] = "Kategori: $oldCat → $newCat";
                            $changesEn[] = "Category: $oldCat → $newCat";
                        } elseif ($newCat !== '') {
                            $changesTr[] = "Kategori: $newCat";
                            $changesEn[] = "Category: $newCat";
                        } elseif ($oldCat !== '') {
                            $changesTr[] = "Kategori kaldırıldı ($oldCat)";
                            $changesEn[] = "Category removed ($oldCat)";
                        }
                    }
                    if (($old['manufacturer_id'] ?? 0) != ($manufacturerId ?: 0)) {
                        $oldMan = ($old['manufacturer_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_manufacturers WHERE id = " . intval($old['manufacturer_id']))->fetchColumn() ?: '') : '';
                        $newMan = ($manufacturerId ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_manufacturers WHERE id = " . intval($manufacturerId))->fetchColumn() ?: '') : '';
                        if ($oldMan !== '' && $newMan !== '') {
                            $changesTr[] = "Üretici: $oldMan → $newMan";
                            $changesEn[] = "Manufacturer: $oldMan → $newMan";
                        } elseif ($newMan !== '') {
                            $changesTr[] = "Üretici: $newMan";
                            $changesEn[] = "Manufacturer: $newMan";
                        } elseif ($oldMan !== '') {
                            $changesTr[] = "Üretici kaldırıldı ($oldMan)";
                            $changesEn[] = "Manufacturer removed ($oldMan)";
                        }
                    }
                    if (($old['department_id'] ?? 0) != ($departmentId ?: 0)) {
                        $oldDep = ($old['department_id'] ?? 0) > 0 ? ($pdo->query("SELECT bolum_adi FROM bolumler WHERE id = " . intval($old['department_id']))->fetchColumn() ?: '') : '';
                        $newDep = ($departmentId ?: 0) > 0 ? ($pdo->query("SELECT bolum_adi FROM bolumler WHERE id = " . intval($departmentId))->fetchColumn() ?: '') : '';
                        if ($oldDep !== '' && $newDep !== '') {
                            $changesTr[] = "Bölüm: $oldDep → $newDep";
                            $changesEn[] = "Department: $oldDep → $newDep";
                        } elseif ($newDep !== '') {
                            $changesTr[] = "Bölüm: $newDep";
                            $changesEn[] = "Department: $newDep";
                        } elseif ($oldDep !== '') {
                            $changesTr[] = "Bölüm kaldırıldı ($oldDep)";
                            $changesEn[] = "Department removed ($oldDep)";
                        }
                    }

                    if (($old['supplier_id'] ?? 0) != ($supplierId ?: 0)) {
                        $oldS = ($old['supplier_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_suppliers WHERE id = " . intval($old['supplier_id']))->fetchColumn() ?: '') : '';
                        $newS = ($supplierId ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_suppliers WHERE id = " . intval($supplierId))->fetchColumn() ?: '') : '';
                        if ($oldS !== '' && $newS !== '') {
                            $changesTr[] = "Tedarikçi: $oldS → $newS";
                            $changesEn[] = "Supplier: $oldS → $newS";
                        } elseif ($newS !== '') {
                            $changesTr[] = "Tedarikçi: $newS";
                            $changesEn[] = "Supplier: $newS";
                        } elseif ($oldS !== '') {
                            $changesTr[] = "Tedarikçi kaldırıldı ($oldS)";
                            $changesEn[] = "Supplier removed ($oldS)";
                        }
                    }
                    if (($old['company_id'] ?? 0) != ($companyId ?: 0)) {
                        $oldC = ($old['company_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_companies WHERE id = " . intval($old['company_id']))->fetchColumn() ?: '') : '';
                        $newC = ($companyId ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_companies WHERE id = " . intval($companyId))->fetchColumn() ?: '') : '';
                        if ($oldC !== '' && $newC !== '') {
                            $changesTr[] = "Şirket: $oldC → $newC";
                            $changesEn[] = "Company: $oldC → $newC";
                        } elseif ($newC !== '') {
                            $changesTr[] = "Şirket: $newC";
                            $changesEn[] = "Company: $newC";
                        } elseif ($oldC !== '') {
                            $changesTr[] = "Şirket kaldırıldı ($oldC)";
                            $changesEn[] = "Company removed ($oldC)";
                        }
                    }
                    if (($old['purchase_cost'] ?? 0) != $cost) {
                        $changesTr[] = "Maliyet: " . ($old['purchase_cost'] ?? 0) . " -> " . $cost;
                        $changesEn[] = "Cost: " . ($old['purchase_cost'] ?? 0) . " -> " . $cost;
                    }
                    if (($old['purchase_date'] ?? '') != ($_POST['purchase_date'] ?? '')) {
                        $changesTr[] = "Alım Tarihi: " . ($old['purchase_date'] ?: '-') . " -> " . ($_POST['purchase_date'] ?: '-');
                        $changesEn[] = "Purchase Date: " . ($old['purchase_date'] ?: '-') . " -> " . ($_POST['purchase_date'] ?: '-');
                    }
                    if (($old['order_number'] ?? '') != $orderNumber) {
                        $changesTr[] = "Sipariş No: " . ($old['order_number'] ?: '-') . " -> " . ($orderNumber ?: '-');
                        $changesEn[] = "Order No: " . ($old['order_number'] ?: '-') . " -> " . ($orderNumber ?: '-');
                    }

                    if (($old['assigned_user_id'] ?? 0) != ($assigned ?: 0)) {
                        $oldUser = ($old['assigned_user_id'] ?? 0) > 0 ? ($pdo->query("SELECT fullname FROM users WHERE id = " . $old['assigned_user_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newUser = ($assigned ?: 0) > 0 ? ($pdo->query("SELECT fullname FROM users WHERE id = $assigned")->fetchColumn() ?: 'Geri Alındı / Returned') : 'Yok / None';
                        $changesTr[] = "Personel: $oldUser -> $newUser";
                        $changesEn[] = "User: $oldUser -> $newUser";
                        
                        if ($assigned > 0) {
                            handleSignature($pdo, $assigned, $id);
                            $uInfoAssetAssign = $pdo->query("SELECT fullname, mail FROM users WHERE id = $assigned")->fetch();
                            if ($uInfoAssetAssign && !empty($uInfoAssetAssign['mail'])) {
                                sendTemplatedMail($uInfoAssetAssign['mail'], $uInfoAssetAssign['fullname'], 'asset_assigned', [
                                    'fullname' => $uInfoAssetAssign['fullname'],
                                    'ITEM_NAME' => $name,
                                    'DATE' => date('d.m.Y H:i'),
                                    'ITEM_TYPE' => 'assets'
                                ], '', $_SESSION['lang'] ?? 'tr');
                            }
                        }
                    }

                    $translateStatus = function($statName, $toTr) {
                        $statName = trim((string)$statName);
                        $map = [
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
                            'Pending' => ['tr' => 'Beklemede', 'en' => 'Pending'],
                            'Yok' => ['tr' => 'Yok', 'en' => 'None']
                        ];
                        if (isset($map[$statName])) {
                            return $toTr ? $map[$statName]['tr'] : $map[$statName]['en'];
                        }
                        return $statName;
                    };

                    if (($old['status_id'] ?? 0) != ($statusId ?: 0)) {
                        $oldStatRaw = ($old['status_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_status_labels WHERE id = " . $old['status_id'])->fetchColumn() ?: 'Yok') : 'Yok';
                        $newStatRaw = ($statusId ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_status_labels WHERE id = $statusId")->fetchColumn() ?: 'Yok') : 'Yok';
                        
                        $oldStatTr = $translateStatus($oldStatRaw, true);
                        $oldStatEn = $translateStatus($oldStatRaw, false);
                        $newStatTr = $translateStatus($newStatRaw, true);
                        $newStatEn = $translateStatus($newStatRaw, false);
                        $changesTr[] = "Durum: $oldStatTr -> $newStatTr";
                        $changesEn[] = "Status: $oldStatEn -> $newStatEn";
                    }

                    if (($old['asset_id'] ?? 0) != ($targetAssetId ?: 0)) {
                        $oldStat = ($old['asset_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM assets WHERE id = " . $old['asset_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newStat = ($targetAssetId ?: 0) > 0 ? ($pdo->query("SELECT name FROM assets WHERE id = $targetAssetId")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Bağlı Cihaz: $oldStat -> $newStat";
                        $changesEn[] = "Assigned Device: $oldStat -> $newStat";
                    }

                    // --- DYNAMIC CUSTOM FIELDS SAVING ---
                    if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
                        foreach ($_POST['custom_fields'] as $fldId => $fldVal) {
                            $fldId = intval($fldId);
                            // Get field label for logging
                            $fieldMeta = $pdo->query("SELECT field_label FROM inventory_custom_fields WHERE id = $fldId")->fetch();
                            $fLabel = $fieldMeta['field_label'] ?? "Field #$fldId";

                            // Get old value
                            $chk = $pdo->prepare("SELECT value FROM inventory_asset_field_values WHERE asset_id = ? AND field_id = ?");
                            $chk->execute([$id, $fldId]);
                            $oldValRow = $chk->fetch();
                            $oldVal = $oldValRow['value'] ?? '';

                            if ($oldVal != $fldVal) {
                                if ($oldValRow) {
                                    $pdo->prepare("UPDATE inventory_asset_field_values SET value = ? WHERE asset_id = ? AND field_id = ?")->execute([$fldVal, $id, $fldId]);
                                } else {
                                    $pdo->prepare("INSERT INTO inventory_asset_field_values (asset_id, field_id, value) VALUES (?, ?, ?)")->execute([$id, $fldId, $fldVal]);
                                }
                                $changesTr[] = "$fLabel: $oldVal -> $fldVal";
                                $changesEn[] = "$fLabel: $oldVal -> $fldVal";
                            }
                        }
                    }

                    if ($uploadedImage) {
                        if (empty($old['image'])) {
                            $changesTr[] = "Yeni fotoğraf eklendi";
                            $changesEn[] = "New photo added";
                        } else {
                            $changesTr[] = "Fotoğraf güncellendi";
                            $changesEn[] = "Photo updated";
                        }
                    }

                    if (!empty($changesTr)) {
                        $logDetail = "Bilgi güncellendi: " . implode(", ", $changesTr) . " / Info updated: " . implode(", ", $changesEn);
                        addAssetLog($pdo, $id, $current_user_id, 'updated', $logDetail);
                        $_SESSION['mesaj'] = ($isTr ? "Başarıyla güncellendi: " : "Updated: ") . (count($changesTr) > 3 ? count($changesTr) . ($isTr ? " alan değişti." : " fields changed.") : implode(", ", $isTr ? $changesTr : $changesEn));
                    } else {
                        $_SESSION['mesaj'] = $isTr ? "Herhangi bir değişiklik yapılmadı." : "No changes made.";
                    }
                } else {
                    // --- INSERT ---
                    $cols = ["name", "asset_tag", "serial_no", "model_id", "category_id", "ip_address", "mac_address"];
                    $vals = [$name, $asset_tag, $serial, $model_id, $cat_id, $ip, $mac];

                    if (isset($_POST['location'])) {
                        $cols[] = "location";
                        $vals[] = $location;
                    }

                    array_push($cols, "assigned_user_id", "asset_id", "notes", "status", "status_id", "purchase_cost", "purchase_currency", "company_id", "default_location", "requestable", "ip_secondary", "department_id", "hdd_size", "hdd_type", "mainboard", "cpu", "ram", "gpu", "monitor", "disk", "warranty_months", "expected_checkin", "next_audit", "byod", "order_number", "eol_date", "supplier_id", "manufacturer_id", "device_name", "purchase_date", "image");
                    array_push($vals, $assigned, $targetAssetId, $notes, $status, $statusId, $cost, $purchaseCurrency, $companyId, $defaultLocation, $requestable, $ipSecondary, $departmentId, $hddSize, $hddType, $mainboard, $cpu, $ram, $gpu, $monitor, $disk, $warrantyMonths, $expectedCheckin, $nextAudit, $byod, $orderNumber, $eolDate, $supplierId, $manufacturerId, $name, (!empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null), $uploadedImage);

                    if (tableHasColumn($pdo, 'assets', 'specs')) {
                        $cols[] = "specs";
                        $vals[] = $specsJSON;
                    }

                    $sql = "INSERT INTO assets (" . implode(", ", $cols) . ") VALUES (" . implode(", ", array_fill(0, count($cols), "?")) . ")";
                    $pdo->prepare($sql)->execute($vals);
                    $id = $pdo->lastInsertId();
                    addAssetLog($pdo, $id, $current_user_id, 'created', ($isTr ? "Yeni varlık oluşturuldu." : "New asset created."));

                    if ($assigned > 0) {
                        handleSignature($pdo, $assigned, $id);
                        $uInfoAssetAssign = $pdo->query("SELECT fullname, mail FROM users WHERE id = $assigned")->fetch();
                        if ($uInfoAssetAssign && !empty($uInfoAssetAssign['mail'])) {
                            sendTemplatedMail($uInfoAssetAssign['mail'], $uInfoAssetAssign['fullname'], 'asset_assigned', [
                                'fullname' => $uInfoAssetAssign['fullname'],
                                'ITEM_NAME' => $name,
                                'DATE' => date('d.m.Y H:i'),
                                'ITEM_TYPE' => 'assets'
                            ], '', $_SESSION['lang'] ?? 'tr');
                        }
                    }

                    // --- SAVE CUSTOM FIELDS ON INSERT ---
                    if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
                        foreach ($_POST['custom_fields'] as $fldId => $fldVal) {
                            if ($fldVal !== '') {
                                $pdo->prepare("INSERT INTO inventory_asset_field_values (asset_id, field_id, value) VALUES (?, ?, ?)")->execute([$id, intval($fldId), $fldVal]);
                            }
                        }
                    }

                    $_SESSION['mesaj'] = $isTr ? "Yeni varlık başarıyla eklendi." : "New asset added successfully.";
                }
            }
        } elseif ($view_submit == 'licenses') {
            $uploadedImage = uploadInventoryImage($_FILES['image'] ?? [], 'licenses');
            $name = $_POST['name'] ?? '';
            $key = $_POST['license_key'] ?? '';
            $seats = intval($_POST['seats'] ?? 1);
            $expire = $_POST['expire_date'] ?: null;
            $cat_id = $_POST['category_id'] ?: null;
            $man_id = $_POST['manufacturer_id'] ?: null;
            $sup_id = $_POST['supplier_id'] ?: null;
            $lic_email = $_POST['license_email'] ?? '';
            $lic_name = $_POST['license_name'] ?? '';
            $cost = $_POST['purchase_cost'] ?: 0;
            $purchaseCurrency = strtoupper(trim((string) ($_POST['purchase_currency'] ?? 'TRY'))) ?: 'TRY';
            $min_qty = intval($_POST['min_qty'] ?? 0);
            $notes = $_POST['notes'] ?? '';
            $link_asset_id = $_POST['asset_id_link'] ?: null;
            $sup_id = $_POST['supplier_id'] ?: null;

            if ($id > 0) {
                // Get old data for detailed logging
                $old = $pdo->query("SELECT * FROM asset_licenses WHERE id = $id")->fetch(PDO::FETCH_ASSOC);

                if ($uploadedImage) {
                    $sql = "UPDATE asset_licenses SET software_name=?, license_key=?, seats=?, expire_date=?, category_id=?, manufacturer_id=?, asset_id=?, license_email=?, license_name=?, purchase_cost=?, purchase_currency=?, min_qty=?, supplier_id=?, notes=?, purchase_date=?, company_id=?, department_id=?, order_no=?, image=? WHERE id=?";
                    $pdo->prepare($sql)->execute([$name, $key, $seats, $expire, $cat_id, $man_id, $link_asset_id, $lic_email, $lic_name, $cost, $purchaseCurrency, $min_qty, $sup_id, $notes, $_POST['purchase_date'] ?: null, $_POST['company_id'] ?: null, $_POST['department_id'] ?: null, $_POST['order_number'] ?? '', $uploadedImage, $id]);
                } else {
                    $sql = "UPDATE asset_licenses SET software_name=?, license_key=?, seats=?, expire_date=?, category_id=?, manufacturer_id=?, asset_id=?, license_email=?, license_name=?, purchase_cost=?, purchase_currency=?, min_qty=?, supplier_id=?, notes=?, purchase_date=?, company_id=?, department_id=?, order_no=? WHERE id=?";
                    $pdo->prepare($sql)->execute([$name, $key, $seats, $expire, $cat_id, $man_id, $link_asset_id, $lic_email, $lic_name, $cost, $purchaseCurrency, $min_qty, $sup_id, $notes, $_POST['purchase_date'] ?: null, $_POST['company_id'] ?: null, $_POST['department_id'] ?: null, $_POST['order_number'] ?? '', $id]);
                }
                $changesTr = [];
                $changesEn = [];
                if ($old['software_name'] != $name) {
                    $changesTr[] = "İsim: " . $old['software_name'] . " -> " . $name;
                    $changesEn[] = "Name: " . $old['software_name'] . " -> " . $name;
                }
                if ($old['license_key'] != $key) {
                    $changesTr[] = "Lisans Anahtarı: " . (empty($old['license_key']) ? '-' : $old['license_key']) . " -> " . (empty($key) ? '-' : $key);
                    $changesEn[] = "License Key: " . (empty($old['license_key']) ? '-' : $old['license_key']) . " -> " . (empty($key) ? '-' : $key);
                }
                if ($old['seats'] != $seats) {
                    $changesTr[] = "Miktar: " . $old['seats'] . " -> " . $seats;
                    $changesEn[] = "Seats/Qty: " . $old['seats'] . " -> " . $seats;
                }
                if ($old['purchase_currency'] != $purchaseCurrency) {
                    $changesTr[] = "Para Birimi: " . $old['purchase_currency'] . " -> " . $purchaseCurrency;
                    $changesEn[] = "Currency: " . $old['purchase_currency'] . " -> " . $purchaseCurrency;
                }
                if ($old['purchase_cost'] != $cost) {
                    $changesTr[] = "Satın Alma Ücreti: " . $old['purchase_cost'] . " -> " . $cost;
                    $changesEn[] = "Purchase Cost: " . $old['purchase_cost'] . " -> " . $cost;
                }
                if ($old['order_no'] != ($_POST['order_number'] ?? '')) {
                    $changesTr[] = "Sipariş No: " . ($old['order_no'] ?: '-') . " -> " . ($_POST['order_number'] ?: '-');
                    $changesEn[] = "Order No: " . ($old['order_no'] ?: '-') . " -> " . ($_POST['order_number'] ?: '-');
                }
                if ($old['license_name'] != $lic_name) {
                    $changesTr[] = "Lisans Sahibi: " . ($old['license_name'] ?: '-') . " -> " . ($lic_name ?: '-');
                    $changesEn[] = "License Owner: " . ($old['license_name'] ?: '-') . " -> " . ($lic_name ?: '-');
                }
                if ($old['license_email'] != $lic_email) {
                    $changesTr[] = "Lisans Maili: " . ($old['license_email'] ?: '-') . " -> " . ($lic_email ?: '-');
                    $changesEn[] = "License Email: " . ($old['license_email'] ?: '-') . " -> " . ($lic_email ?: '-');
                }
                if ($old['expire_date'] != $expire) {
                    $changesTr[] = "Bitiş Tarihi: " . ($old['expire_date'] ?: '-') . " -> " . ($expire ?: '-');
                    $changesEn[] = "Expiration Date: " . ($old['expire_date'] ?: '-') . " -> " . ($expire ?: '-');
                }
                // Notes change: detect add/remove/update
                $oldNotes = trim((string) ($old['notes'] ?? ''));
                $newNotes = trim((string) $notes);
                if ($oldNotes !== $newNotes) {
                    if ($oldNotes === '' && $newNotes !== '') {
                        $changesTr[] = 'Not eklendi';
                        $changesEn[] = 'Note added';
                    } elseif ($oldNotes !== '' && $newNotes === '') {
                        $changesTr[] = 'Not silindi';
                        $changesEn[] = 'Note removed';
                    } else {
                        $changesTr[] = 'Not değiştirildi';
                        $changesEn[] = 'Note updated';
                    }
                }

                if ($link_asset_id != $old['asset_id']) {
                    $oldTarget = $old['asset_id'] ? ($pdo->query("SELECT name FROM assets WHERE id = " . $old['asset_id'])->fetchColumn() ?: '#' . $old['asset_id']) : 'Yok / None';
                    $newTarget = $link_asset_id ? ($pdo->query("SELECT name FROM assets WHERE id = $link_asset_id")->fetchColumn() ?: '#' . $link_asset_id) : 'Geri Alındı / Returned';
                    $changesTr[] = "Cihaz Ataması: $oldTarget -> $newTarget";
                    $changesEn[] = "Asset Assignment: $oldTarget -> $newTarget";
                }

                if ($uploadedImage) {
                    if (empty($old['image'])) {
                        $changesTr[] = "Yeni fotoğraf eklendi";
                        $changesEn[] = "New photo added";
                    } else {
                        $changesTr[] = "Fotoğraf güncellendi";
                        $changesEn[] = "Photo updated";
                    }
                }

                if (!empty($changesTr)) {
                    $logMsg = "Bilgi güncellendi: " . implode(", ", $changesTr) . " / Info updated: " . implode(", ", $changesEn);
                } else {
                    $logMsg = "Lisans bilgileri güncellendi. / License info updated.";
                }
                addAssetLog($pdo, $id, $current_user_id, 'updated', $logMsg, null, 'license');
            } else {
                $sql = "INSERT INTO asset_licenses (software_name, license_key, seats, expire_date, category_id, manufacturer_id, asset_id, license_email, license_name, purchase_cost, purchase_currency, min_qty, supplier_id, notes, purchase_date, company_id, department_id, order_no, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([$name, $key, $seats, $expire, $cat_id, $man_id, $link_asset_id, $lic_email, $lic_name, $cost, $purchaseCurrency, $min_qty, $sup_id, $notes, $_POST['purchase_date'] ?: null, $_POST['company_id'] ?: null, $_POST['department_id'] ?: null, $_POST['order_number'] ?? '', $uploadedImage]);
                $id = $pdo->lastInsertId();

                $logMsgTr = "Lisans sisteme eklendi.";
                $logMsgEn = "License added to system.";
                if ($link_asset_id) {
                    $targetName = $pdo->query("SELECT name FROM assets WHERE id = $link_asset_id")->fetchColumn() ?: '#' . $link_asset_id;
                    $logMsgTr .= " (Cihaza atandı: $targetName)";
                    $logMsgEn .= " (Assigned to device: $targetName)";
                }
                $logMsg = "$logMsgTr / $logMsgEn";
                addAssetLog($pdo, $id, $current_user_id, 'created', $logMsg, null, 'license');
            }
        } elseif (in_array($view_submit, ['accessories', 'consumables', 'components'])) {
            $table = "asset_" . $view_submit;
            $qty = intval($_POST['quantity'] ?? 1);
            $remaining_qty = isset($_POST['remaining_qty']) ? intval($_POST['remaining_qty']) : $qty;
            $cat_id = $_POST['category_id'] ?: null;
            $man_id = $_POST['manufacturer_id'] ?: null;
            $cost = $_POST['purchase_cost'] ?: 0;
            $purchaseCurrency = strtoupper(trim((string) ($_POST['purchase_currency'] ?? 'TRY'))) ?: 'TRY';
            $date = $_POST['purchase_date'] ?: null;
            $mod = $_POST['model_no'] ?? '';
            $ser = $_POST['serial_no'] ?? '';
            $item_no = $_POST['item_no'] ?? '';
            $min_qty = intval($_POST['min_qty'] ?? 0);
            $notes = $_POST['notes'] ?? '';
            $sup_id = $_POST['supplier_id'] ?: null;
            $uploadedImage = uploadInventoryImage($_FILES['image'] ?? [], $view_submit);

            if ($id > 0) {
                if ($view_submit == 'consumables') {
                    $consumedQty = (int) $pdo->query("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'checkin' THEN -quantity ELSE quantity END), 0) FROM asset_consumable_checkouts WHERE consumable_id = " . $id . " AND transaction_type IN ('consume', 'checkin')")->fetchColumn();
                    $remaining_qty = max(0, $qty - $consumedQty);
                }

                if ($view_submit == 'components') {
                    $old = $pdo->query("SELECT * FROM asset_components WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
                    if (!$old) {
                        $_SESSION['hata'] = "Component not found.";
                        header("Location: varliklar?view=components");
                        exit;
                    }

                    // Define group identity based on current grouping logic (Name, Category, Company)
                    $oldName = $old['name'];
                    $oldCat = (int) $old['category_id'];
                    $oldComp = $old['company_id'] ? (int) $old['company_id'] : null;
                    $whereOldGroup = "name = " . $pdo->quote($oldName) . " AND category_id = $oldCat AND " . ($oldComp === null ? "company_id IS NULL" : "company_id = $oldComp");
                    $currentCount = (int) $pdo->query("SELECT COUNT(*) FROM asset_components WHERE $whereOldGroup AND deleted_at IS NULL")->fetchColumn();
                    $targetQty = intval($_POST['quantity'] ?? 1);
                    $addedCount = 0;
                    $deletedCount = 0;

                    // 1. Handle Increase: Add to old group first
                    if ($targetQty > $currentCount) {
                        $diff = $targetQty - $currentCount;
                        for ($i = 0; $i < $diff; $i++) {
                            // Insert minimal record into the group; metadata update below will fill the rest
                            $sqlIns = "INSERT INTO $table (name, total_qty, category_id, company_id) VALUES (?, ?, ?, ?)";
                            $pdo->prepare($sqlIns)->execute([$oldName, 1, $oldCat, $oldComp]);
                        }
                        $addedCount = $diff;
                    }
                    // 2. Handle Decrease: Soft-delete free instances from the group
                    elseif ($targetQty < $currentCount) {
                        $diff = $currentCount - $targetQty;
                        $freeItems = $pdo->query("SELECT c.id FROM asset_components c LEFT JOIN asset_component_checkouts acc ON c.id = acc.component_id WHERE c.$whereOldGroup AND acc.id IS NULL AND c.deleted_at IS NULL LIMIT $diff")->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($freeItems)) {
                            $deletedCount = count($freeItems);
                            $idsToDel = implode(',', $freeItems);
                            $pdo->exec("UPDATE asset_components SET deleted_at = NOW() WHERE id IN ($idsToDel)");
                        }
                    }

                    // 3. Update Metadata for all remaining items in this group
                    $newComp = $_POST['company_id'] ?: null;
                    $updateParams = [$name, $cat_id, $date, $cost, $purchaseCurrency, $min_qty, $notes, $sup_id, $_POST['order_number'] ?? '', $newComp, $_POST['department_id'] ?: null, $_POST['manufacturer_id'] ?: null];
                    $imgSql = "";
                    if ($uploadedImage) {
                        $imgSql = ", image=?";
                        $updateParams[] = $uploadedImage;
                    }

                    $sql = "UPDATE $table SET name=?, category_id=?, purchase_date=?, purchase_cost=?, purchase_currency=?, min_qty=?, notes=?, supplier_id=?, order_no=?, company_id=?, department_id=?, manufacturer_id=? $imgSql WHERE $whereOldGroup AND deleted_at IS NULL";
                    $pdo->prepare($sql)->execute($updateParams);

                    // 4. Update specific instance serial number
                    if (isset($_POST['serial_no'])) {
                        $pdo->prepare("UPDATE asset_components SET serial_no = ? WHERE id = ?")->execute([$_POST['serial_no'], $id]);
                    }

                    // 5. Construct Log Message
                    $changesTr = [];
                    $changesEn = [];
                    $f_diff = function ($lblTr, $lblEn, $o, $n) use (&$changesTr, &$changesEn) {
                        $o = trim((string) $o);
                        $n = trim((string) $n);
                        if ($o === $n)
                            return;
                        if (empty($o)) {
                            $changesTr[] = "$lblTr eklendi: $n";
                            $changesEn[] = "$lblEn added: $n";
                        } elseif (empty($n)) {
                            $changesTr[] = "$lblTr silindi";
                            $changesEn[] = "$lblEn removed";
                        } else {
                            $changesTr[] = "$lblTr: $o -> $n";
                            $changesEn[] = "$lblEn: $o -> $n";
                        }
                    };

                    $f_diff('İsim', 'Name', $old['name'] ?? '', $name);
                    $f_diff('Miktar', 'Quantity', $currentCount, $targetQty);
                    $f_diff('Seri No', 'Serial No', $old['serial_no'] ?? '', $_POST['serial_no'] ?? '');
                    $f_diff('Alım Tarihi', 'Purchase Date', $old['purchase_date'] ?? '', $date);
                    $f_diff('Maliyet', 'Cost', $old['purchase_cost'] ?? '', $cost);
                    $f_diff('Para Birimi', 'Currency', $old['purchase_currency'] ?? '', $purchaseCurrency);
                    $f_diff('Minimum Miktar', 'Min Qty', $old['min_qty'] ?? '', $min_qty);
                    $f_diff('Sipariş No', 'Order No', $old['order_no'] ?? '', $_POST['order_number'] ?? '');

                    $oldNotes = trim((string)($old['notes'] ?? ''));
                    $newNotes = trim((string)$notes);
                    if ($oldNotes !== $newNotes) {
                        if ($oldNotes === '' && $newNotes !== '') {
                            $changesTr[] = "Not eklendi";
                            $changesEn[] = "Note added";
                        } elseif ($oldNotes !== '' && $newNotes === '') {
                            $changesTr[] = "Not silindi";
                            $changesEn[] = "Note removed";
                        } else {
                            $changesTr[] = "Not değiştirildi";
                            $changesEn[] = "Note updated";
                        }
                    }

                    if ($uploadedImage) {
                        if (empty($old['image'])) {
                            $changesTr[] = "Yeni fotoğraf eklendi";
                            $changesEn[] = "New photo added";
                        } else {
                            $changesTr[] = "Fotoğraf güncellendi";
                            $changesEn[] = "Photo updated";
                        }
                    }

                    if (($old['category_id'] ?? 0) != ($cat_id ?: 0)) {
                        $oldCatName = ($old['category_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_categories WHERE id = " . $old['category_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newCatName = ($cat_id ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_categories WHERE id = $cat_id")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Kategori: $oldCatName -> $newCatName";
                        $changesEn[] = "Category: $oldCatName -> $newCatName";
                    }

                    if (($old['manufacturer_id'] ?? 0) != ($man_id ?: 0)) {
                        $oldManName = ($old['manufacturer_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_manufacturers WHERE id = " . $old['manufacturer_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newManName = ($man_id ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_manufacturers WHERE id = $man_id")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Üretici: $oldManName -> $newManName";
                        $changesEn[] = "Manufacturer: $oldManName -> $newManName";
                    }

                    if (($old['supplier_id'] ?? 0) != ($sup_id ?: 0)) {
                        $oldSupName = ($old['supplier_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_suppliers WHERE id = " . $old['supplier_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newSupName = ($sup_id ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_suppliers WHERE id = $sup_id")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Tedarikçi: $oldSupName -> $newSupName";
                        $changesEn[] = "Supplier: $oldSupName -> $newSupName";
                    }

                    $oldCompany = $_POST['company_id'] ?: null;
                    if (($old['company_id'] ?? 0) != ($oldCompany ?: 0)) {
                        $oldCompName = ($old['company_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_companies WHERE id = " . $old['company_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newCompName = ($oldCompany ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_companies WHERE id = $oldCompany")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Şirket: $oldCompName -> $newCompName";
                        $changesEn[] = "Company: $oldCompName -> $newCompName";
                    }

                    $oldDept = $_POST['department_id'] ?: null;
                    if (($old['department_id'] ?? 0) != ($oldDept ?: 0)) {
                        $oldDeptName = ($old['department_id'] ?? 0) > 0 ? ($pdo->query("SELECT bolum_adi FROM bolumler WHERE id = " . $old['department_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newDeptName = ($oldDept ?: 0) > 0 ? ($pdo->query("SELECT bolum_adi FROM bolumler WHERE id = $oldDept")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Bölüm: $oldDeptName -> $newDeptName";
                        $changesEn[] = "Department: $oldDeptName -> $newDeptName";
                    }

                    if (!empty($changesTr)) {
                        $logMsg = "Bilgi güncellendi: " . implode(", ", $changesTr) . " / Info updated: " . implode(", ", $changesEn);
                    } else {
                        if ($addedCount > 0) {
                            $logMsg = "Bileşen grubu güncellendi ve $addedCount yeni adet eklendi. / Component group updated and $addedCount new instances added.";
                        } elseif ($deletedCount > 0) {
                            $logMsg = "Bileşen grubu güncellendi ve $deletedCount boşta parça söküldü. / Component group updated and $deletedCount free instances removed.";
                        } else {
                            $logMsg = "Bileşen grup bilgileri güncellendi. / Component group info updated.";
                        }
                    }
                    addAssetLog($pdo, $id, $current_user_id, 'updated', $logMsg, null, 'component');
                } elseif ($view_submit == 'accessories') {
                    $old = $pdo->query("SELECT * FROM asset_accessories WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
                    if ($uploadedImage) {
                        $sql = "UPDATE $table SET name=?, total_qty=?, category_id=?, manufacturer_id=?, model_no=?, purchase_date=?, purchase_cost=?, purchase_currency=?, min_qty=?, notes=?, company_id=?, department_id=?, supplier_id=?, order_no=?, warranty_months=?, image=?, serial_no=? WHERE id=?";
                        $pdo->prepare($sql)->execute([$name, $qty, $cat_id, $man_id, $mod, $date, $cost, $purchaseCurrency, $min_qty, $notes, $_POST['company_id'] ?: null, $_POST['department_id'] ?: null, $sup_id, $_POST['order_number'] ?? '', intval($_POST['warranty_months'] ?? 0), $uploadedImage, $ser, $id]);
                    } else {
                        $sql = "UPDATE $table SET name=?, total_qty=?, category_id=?, manufacturer_id=?, model_no=?, purchase_date=?, purchase_cost=?, purchase_currency=?, min_qty=?, notes=?, company_id=?, department_id=?, supplier_id=?, order_no=?, warranty_months=?, serial_no=? WHERE id=?";
                        $pdo->prepare($sql)->execute([$name, $qty, $cat_id, $man_id, $mod, $date, $cost, $purchaseCurrency, $min_qty, $notes, $_POST['company_id'] ?: null, $_POST['department_id'] ?: null, $sup_id, $_POST['order_number'] ?? '', intval($_POST['warranty_months'] ?? 0), $ser, $id]);
                    }
                    
                    $changesTr = [];
                    $changesEn = [];
                    $f_diff = function ($lblTr, $lblEn, $o, $n) use (&$changesTr, &$changesEn) {
                        $o = trim((string) $o);
                        $n = trim((string) $n);
                        if ($o === $n) return;
                        if (empty($o)) {
                            $changesTr[] = "$lblTr eklendi: $n";
                            $changesEn[] = "$lblEn added: $n";
                        } elseif (empty($n)) {
                            $changesTr[] = "$lblTr silindi";
                            $changesEn[] = "$lblEn removed";
                        } else {
                            $changesTr[] = "$lblTr: $o -> $n";
                            $changesEn[] = "$lblEn: $o -> $n";
                        }
                    };
                    
                    $f_diff('İsim', 'Name', $old['name'] ?? '', $name);
                    $f_diff('Miktar', 'Quantity', $old['total_qty'] ?? '', $qty);
                    $f_diff('Model No', 'Model No', $old['model_no'] ?? '', $mod);
                    $f_diff('Seri No', 'Serial No', $old['serial_no'] ?? '', $ser);
                    $f_diff('Alım Tarihi', 'Purchase Date', $old['purchase_date'] ?? '', $date);
                    $f_diff('Maliyet', 'Cost', $old['purchase_cost'] ?? '', $cost);
                    $f_diff('Para Birimi', 'Currency', $old['purchase_currency'] ?? '', $purchaseCurrency);
                    $f_diff('Minimum Miktar', 'Min Qty', $old['min_qty'] ?? '', $min_qty);
                    $f_diff('Sipariş No', 'Order No', $old['order_no'] ?? '', $_POST['order_number'] ?? '');
                    $f_diff('Garanti Süresi (Ay)', 'Warranty (Months)', $old['warranty_months'] ?? '', intval($_POST['warranty_months'] ?? 0));
                    
                    $oldNotes = trim((string)($old['notes'] ?? ''));
                    $newNotes = trim((string)$notes);
                    if ($oldNotes !== $newNotes) {
                        if ($oldNotes === '' && $newNotes !== '') {
                            $changesTr[] = "Not eklendi";
                            $changesEn[] = "Note added";
                        } elseif ($oldNotes !== '' && $newNotes === '') {
                            $changesTr[] = "Not silindi";
                            $changesEn[] = "Note removed";
                        } else {
                            $changesTr[] = "Not değiştirildi";
                            $changesEn[] = "Note updated";
                        }
                    }
                    
                    if ($uploadedImage) {
                        if (empty($old['image'])) {
                            $changesTr[] = "Yeni fotoğraf eklendi";
                            $changesEn[] = "New photo added";
                        } else {
                            $changesTr[] = "Fotoğraf güncellendi";
                            $changesEn[] = "Photo updated";
                        }
                    }
                    
                    if (($old['category_id'] ?? 0) != ($cat_id ?: 0)) {
                        $oldCatName = ($old['category_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_categories WHERE id = " . $old['category_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newCatName = ($cat_id ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_categories WHERE id = $cat_id")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Kategori: $oldCatName -> $newCatName";
                        $changesEn[] = "Category: $oldCatName -> $newCatName";
                    }
                    
                    if (($old['manufacturer_id'] ?? 0) != ($man_id ?: 0)) {
                        $oldManName = ($old['manufacturer_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_manufacturers WHERE id = " . $old['manufacturer_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newManName = ($man_id ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_manufacturers WHERE id = $man_id")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Üretici: $oldManName -> $newManName";
                        $changesEn[] = "Manufacturer: $oldManName -> $newManName";
                    }
                    
                    if (($old['supplier_id'] ?? 0) != ($sup_id ?: 0)) {
                        $oldSupName = ($old['supplier_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_suppliers WHERE id = " . $old['supplier_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newSupName = ($sup_id ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_suppliers WHERE id = $sup_id")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Tedarikçi: $oldSupName -> $newSupName";
                        $changesEn[] = "Supplier: $oldSupName -> $newSupName";
                    }
                    
                    $oldCompany = $_POST['company_id'] ?: null;
                    if (($old['company_id'] ?? 0) != ($oldCompany ?: 0)) {
                        $oldCompName = ($old['company_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_companies WHERE id = " . $old['company_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newCompName = ($oldCompany ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_companies WHERE id = $oldCompany")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Şirket: $oldCompName -> $newCompName";
                        $changesEn[] = "Company: $oldCompName -> $newCompName";
                    }
                    
                    $oldDept = $_POST['department_id'] ?: null;
                    if (($old['department_id'] ?? 0) != ($oldDept ?: 0)) {
                        $oldDeptName = ($old['department_id'] ?? 0) > 0 ? ($pdo->query("SELECT bolum_adi FROM bolumler WHERE id = " . $old['department_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newDeptName = ($oldDept ?: 0) > 0 ? ($pdo->query("SELECT bolum_adi FROM bolumler WHERE id = $oldDept")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Bölüm: $oldDeptName -> $newDeptName";
                        $changesEn[] = "Department: $oldDeptName -> $newDeptName";
                    }
                    
                    if (!empty($changesTr)) {
                        $logMsg = "Bilgi güncellendi: " . implode(", ", $changesTr) . " / Info updated: " . implode(", ", $changesEn);
                    } else {
                        $logMsg = "Aksesuar bilgileri güncellendi. / Accessory info updated.";
                    }
                    addAssetLog($pdo, $id, $current_user_id, 'updated', $logMsg, null, 'accessory');
                } else { // consumables
                    $old = $pdo->query("SELECT * FROM asset_consumables WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
                    if ($uploadedImage) {
                        $sql = "UPDATE $table SET name=?, total_qty=?, remaining_qty=?, category_id=?, manufacturer_id=?, item_no=?, purchase_date=?, purchase_cost=?, purchase_currency=?, min_qty=?, notes=?, model_no=?, serial_no=?, supplier_id=?, order_no=?, company_id=?, department_id=?, image=? WHERE id=?";
                        $pdo->prepare($sql)->execute([$name, $qty, $remaining_qty, $cat_id, $man_id, $item_no, $date, $cost, $purchaseCurrency, $min_qty, $notes, $mod, $ser, $sup_id, $_POST['order_number'] ?? '', $_POST['company_id'] ?: null, $_POST['department_id'] ?: null, $uploadedImage, $id]);
                    } else {
                        $sql = "UPDATE $table SET name=?, total_qty=?, remaining_qty=?, category_id=?, manufacturer_id=?, item_no=?, purchase_date=?, purchase_cost=?, purchase_currency=?, min_qty=?, notes=?, model_no=?, serial_no=?, supplier_id=?, order_no=?, company_id=?, department_id=? WHERE id=?";
                        $pdo->prepare($sql)->execute([$name, $qty, $remaining_qty, $cat_id, $man_id, $item_no, $date, $cost, $purchaseCurrency, $min_qty, $notes, $mod, $ser, $sup_id, $_POST['order_number'] ?? '', $_POST['company_id'] ?: null, $_POST['department_id'] ?: null, $id]);
                    }
                    
                    $changesTr = [];
                    $changesEn = [];
                    $f_diff = function ($lblTr, $lblEn, $o, $n) use (&$changesTr, &$changesEn) {
                        $o = trim((string) $o);
                        $n = trim((string) $n);
                        if ($o === $n)
                            return;
                        if (empty($o)) {
                            $changesTr[] = "$lblTr eklendi: $n";
                            $changesEn[] = "$lblEn added: $n";
                        } elseif (empty($n)) {
                            $changesTr[] = "$lblTr silindi";
                            $changesEn[] = "$lblEn removed";
                        } else {
                            $changesTr[] = "$lblTr: $o -> $n";
                            $changesEn[] = "$lblEn: $o -> $n";
                        }
                    };

                    $f_diff('İsim', 'Name', $old['name'] ?? '', $name);
                    $f_diff('Miktar', 'Quantity', $old['total_qty'] ?? '', $qty);
                    $f_diff('Kalan Miktar', 'Remaining Qty', $old['remaining_qty'] ?? '', $remaining_qty);
                    $f_diff('Ürün No', 'Item No', $old['item_no'] ?? '', $item_no);
                    $f_diff('Model No', 'Model No', $old['model_no'] ?? '', $mod);
                    $f_diff('Seri No', 'Serial No', $old['serial_no'] ?? '', $ser);
                    $f_diff('Alım Tarihi', 'Purchase Date', $old['purchase_date'] ?? '', $date);
                    $f_diff('Maliyet', 'Cost', $old['purchase_cost'] ?? '', $cost);
                    $f_diff('Para Birimi', 'Currency', $old['purchase_currency'] ?? '', $purchaseCurrency);
                    $f_diff('Minimum Miktar', 'Min Qty', $old['min_qty'] ?? '', $min_qty);
                    $f_diff('Sipariş No', 'Order No', $old['order_no'] ?? '', $_POST['order_number'] ?? '');

                    $oldNotes = trim((string)($old['notes'] ?? ''));
                    $newNotes = trim((string)$notes);
                    if ($oldNotes !== $newNotes) {
                        if ($oldNotes === '' && $newNotes !== '') {
                            $changesTr[] = "Not eklendi";
                            $changesEn[] = "Note added";
                        } elseif ($oldNotes !== '' && $newNotes === '') {
                            $changesTr[] = "Not silindi";
                            $changesEn[] = "Note removed";
                        } else {
                            $changesTr[] = "Not değiştirildi";
                            $changesEn[] = "Note updated";
                        }
                    }

                    if ($uploadedImage) {
                        if (empty($old['image'])) {
                            $changesTr[] = "Yeni fotoğraf eklendi";
                            $changesEn[] = "New photo added";
                        } else {
                            $changesTr[] = "Fotoğraf güncellendi";
                            $changesEn[] = "Photo updated";
                        }
                    }

                    if (($old['category_id'] ?? 0) != ($cat_id ?: 0)) {
                        $oldCatName = ($old['category_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_categories WHERE id = " . $old['category_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newCatName = ($cat_id ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_categories WHERE id = $cat_id")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Kategori: $oldCatName -> $newCatName";
                        $changesEn[] = "Category: $oldCatName -> $newCatName";
                    }

                    if (($old['manufacturer_id'] ?? 0) != ($man_id ?: 0)) {
                        $oldManName = ($old['manufacturer_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_manufacturers WHERE id = " . $old['manufacturer_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newManName = ($man_id ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_manufacturers WHERE id = $man_id")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Üretici: $oldManName -> $newManName";
                        $changesEn[] = "Manufacturer: $oldManName -> $newManName";
                    }

                    if (($old['supplier_id'] ?? 0) != ($sup_id ?: 0)) {
                        $oldSupName = ($old['supplier_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_suppliers WHERE id = " . $old['supplier_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newSupName = ($sup_id ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_suppliers WHERE id = $sup_id")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Tedarikçi: $oldSupName -> $newSupName";
                        $changesEn[] = "Supplier: $oldSupName -> $newSupName";
                    }

                    $oldCompany = $_POST['company_id'] ?: null;
                    if (($old['company_id'] ?? 0) != ($oldCompany ?: 0)) {
                        $oldCompName = ($old['company_id'] ?? 0) > 0 ? ($pdo->query("SELECT name FROM asset_companies WHERE id = " . $old['company_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newCompName = ($oldCompany ?: 0) > 0 ? ($pdo->query("SELECT name FROM asset_companies WHERE id = $oldCompany")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Şirket: $oldCompName -> $newCompName";
                        $changesEn[] = "Company: $oldCompName -> $newCompName";
                    }

                    $oldDept = $_POST['department_id'] ?: null;
                    if (($old['department_id'] ?? 0) != ($oldDept ?: 0)) {
                        $oldDeptName = ($old['department_id'] ?? 0) > 0 ? ($pdo->query("SELECT bolum_adi FROM bolumler WHERE id = " . $old['department_id'])->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $newDeptName = ($oldDept ?: 0) > 0 ? ($pdo->query("SELECT bolum_adi FROM bolumler WHERE id = $oldDept")->fetchColumn() ?: 'Bilinmeyen / Unknown') : 'Yok / None';
                        $changesTr[] = "Bölüm: $oldDeptName -> $newDeptName";
                        $changesEn[] = "Department: $oldDeptName -> $newDeptName";
                    }

                    if (!empty($changesTr)) {
                        $logMsg = "Bilgi güncellendi: " . implode(", ", $changesTr) . " / Info updated: " . implode(", ", $changesEn);
                    } else {
                        $logMsg = "Sarf malzemesi bilgileri güncellendi. / Consumable info updated.";
                    }
                    addAssetLog($pdo, $id, $current_user_id, 'updated', $logMsg, null, 'consumable');
                }
            } else {
                if ($view_submit == 'components') {
                    $insertQty = max(1, $qty);
                    $firstId = 0;
                    for ($i = 0; $i < $insertQty; $i++) {
                        $sql = "INSERT INTO $table (name, total_qty, category_id, serial_no, purchase_date, purchase_cost, purchase_currency, min_qty, notes, supplier_id, order_no, company_id, department_id, manufacturer_id, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $pdo->prepare($sql)->execute([$name, 1, $cat_id, $ser, $date, $cost, $purchaseCurrency, $min_qty, $notes, $sup_id, $_POST['order_number'] ?? '', $_POST['company_id'] ?: null, $_POST['department_id'] ?: null, $_POST['manufacturer_id'] ?: null, $uploadedImage]);
                        $newId = $pdo->lastInsertId();
                        if ($i === 0)
                            $firstId = $newId;
                        addAssetLog($pdo, $newId, $current_user_id, 'created', ($isTr ? "Bileşen sisteme eklendi (Instance-based)." : "Component added to system (Instance-based)."), null, 'component');
                    }
                    $id = $firstId;
                } elseif ($view_submit == 'accessories') {
                    $sql = "INSERT INTO $table (name, total_qty, category_id, manufacturer_id, model_no, purchase_date, purchase_cost, purchase_currency, min_qty, notes, company_id, department_id, supplier_id, order_no, warranty_months, image, serial_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $pdo->prepare($sql)->execute([$name, $qty, $cat_id, $man_id, $mod, $date, $cost, $purchaseCurrency, $min_qty, $notes, $_POST['company_id'] ?: null, $_POST['department_id'] ?: null, $sup_id, $_POST['order_number'] ?? '', intval($_POST['warranty_months'] ?? 0), $uploadedImage, $ser]);
                    $id = $pdo->lastInsertId();
                    addAssetLog($pdo, $id, $current_user_id, 'created', ($isTr ? "Aksesuar sisteme eklendi. (Başlangıç Miktarı: $qty)" : "Accessory added to system. (Initial Quantity: $qty)"), null, 'accessory');
                } else { // consumables
                    $sql = "INSERT INTO $table (name, total_qty, remaining_qty, category_id, manufacturer_id, item_no, purchase_date, purchase_cost, purchase_currency, min_qty, notes, model_no, serial_no, supplier_id, order_no, company_id, department_id, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $pdo->prepare($sql)->execute([$name, $qty, $remaining_qty, $cat_id, $man_id, $item_no, $date, $cost, $purchaseCurrency, $min_qty, $notes, $mod, $ser, $sup_id, $_POST['order_number'] ?? '', $_POST['company_id'] ?: null, $_POST['department_id'] ?: null, $uploadedImage]);
                    $id = $pdo->lastInsertId();
                }
            }
        }

        // Save Dynamic Custom Fields (Category Based)
        if ($id > 0 && !empty($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            foreach ($_POST['custom_fields'] as $fId => $fVal) {
                $fId = intval($fId);
                if ($fId <= 0)
                    continue;
                $pdo->prepare("INSERT INTO inventory_asset_field_values (asset_id, field_id, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([$id, $fId, $fVal]);
            }
        }

        $red = "varliklar?view=" . $view_submit;
        if (isset($_POST['return_to']) && $_POST['return_to'] == 'detail') {
            $red = "varlik-detay/" . ($id ?: ($_POST['asset_id'] ?? 0)) . "?view=" . $view_submit;
        }
        header("Location: $red");
        exit;
    } elseif ($action == 'replenish_stock' || $action == 'add_stock') {
        $id = intval($_POST['consumable_id'] ?? ($_POST['asset_id'] ?? 0));
        $qtyChange = intval($_POST['quantity'] ?? ($_POST['add_qty'] ?? 0));
        $view_submit = $_POST['view'] ?? 'consumables';
        if ($qtyChange == 0) {
            header("Location: varliklar?view=$view_submit");
            exit;
        }
        if ($view_submit == 'consumables') {
            $oldData = $pdo->query("SELECT total_qty, remaining_qty, name FROM asset_consumables WHERE id = $id")->fetch();
            if ($oldData) {
                $newQty = (int) $oldData['total_qty'] + $qtyChange;
                $newRem = (int) $oldData['remaining_qty'] + $qtyChange;
                if ($newQty < 0)
                    $newQty = 0;
                if ($newRem < 0)
                    $newRem = 0;

                $pdo->prepare("UPDATE asset_consumables SET total_qty = ?, remaining_qty = ? WHERE id = ?")->execute([$newQty, $newRem, $id]);

                $msg = $qtyChange > 0 ? ($isTr ? "Stok eklendi: $qtyChange" : "Stock added: $qtyChange") : ($isTr ? "Stok düşüldü: " . abs($qtyChange) : "Stock removed: " . abs($qtyChange));
                addAssetLog($pdo, $id, $current_user_id, 'updated', $msg, null, 'consumable');

                // NEW: Record as 'add' transaction
                $pdo->prepare("INSERT INTO asset_consumable_checkouts (consumable_id, quantity, transaction_type, performer_id, notes) VALUES (?, ?, ?, ?, ?)")->execute([$id, abs($qtyChange), ($qtyChange > 0 ? 'add' : 'consume'), $current_user_id, $msg]);

                $_SESSION['mesaj'] = $isTr ? "Stok başarıyla güncellendi." : "Stock updated successfully.";
            }
        } elseif ($view_submit == 'accessories') {
            $oldData = $pdo->query("SELECT total_qty, name FROM asset_accessories WHERE id = $id")->fetch();
            if ($oldData) {
                $newQty = (int) $oldData['total_qty'] + $qtyChange;
                if ($newQty < 0) $newQty = 0;
                $pdo->prepare("UPDATE asset_accessories SET total_qty = ? WHERE id = ?")->execute([$newQty, $id]);
                $msg = $qtyChange > 0 ? ($isTr ? "Stok eklendi: $qtyChange" : "Stock added: $qtyChange") : ($isTr ? "Stok düşüldü: " . abs($qtyChange) : "Stock removed: " . abs($qtyChange));
                addAssetLog($pdo, $id, $current_user_id, 'updated', $msg, null, 'accessory');
                // Record transaction
                $pdo->prepare("INSERT INTO asset_accessory_checkouts (accessory_id, quantity, transaction_type, notes) VALUES (?, ?, ?, ?)")->execute([$id, abs($qtyChange), ($qtyChange > 0 ? 'add' : 'consume'), $msg]);
                $_SESSION['mesaj'] = $isTr ? "Stok başarıyla güncellendi." : "Stock updated successfully.";
            }
        } elseif ($view_submit == 'licenses') {
            $oldData = $pdo->query("SELECT seats, software_name FROM asset_licenses WHERE id = $id")->fetch();
            if ($oldData) {
                $newSeats = (int) $oldData['seats'] + $qtyChange;
                if ($newSeats < 0) $newSeats = 0;
                $pdo->prepare("UPDATE asset_licenses SET seats = ? WHERE id = ?")->execute([$newSeats, $id]);
                $msg = $qtyChange > 0 ? ($isTr ? "Koltuk sayısı artırıldı: $qtyChange" : "Seats increased: $qtyChange") : ($isTr ? "Koltuk sayısı düşüldü: " . abs($qtyChange) : "Seats decreased: " . abs($qtyChange));
                addAssetLog($pdo, $id, $current_user_id, 'updated', $msg, null, 'license');
                // Record transaction
                $pdo->prepare("INSERT INTO asset_license_checkouts (license_id, quantity, transaction_type, notes) VALUES (?, ?, ?, ?)")->execute([$id, abs($qtyChange), ($qtyChange > 0 ? 'add' : 'consume'), $msg]);
                $_SESSION['mesaj'] = $isTr ? "Lisans koltuk sayısı güncellendi." : "License seats updated successfully.";
            }
        } elseif ($view_submit == 'components') {
            $old = $pdo->query("SELECT * FROM asset_components WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                $oldName = $old['name'];
                $oldCat = (int) $old['category_id'];
                $oldComp = $old['company_id'] ? (int) $old['company_id'] : null;
                $whereGroup = "name = " . $pdo->quote($oldName) . " AND category_id = $oldCat AND " . ($oldComp === null ? "company_id IS NULL" : "company_id = $oldComp");

                if ($qtyChange > 0) {
                    $serial = $_POST['serial_no'] ?? '';
                    for ($i = 0; $i < $qtyChange; $i++) {
                        $sqlIns = "INSERT INTO asset_components (name, total_qty, category_id, company_id, supplier_id, manufacturer_id, purchase_date, purchase_cost, purchase_currency, order_no, department_id, image, serial_no) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $pdo->prepare($sqlIns)->execute([$oldName, 1, $oldCat, $oldComp, $old['supplier_id'], $old['manufacturer_id'], $old['purchase_date'], $old['purchase_cost'], $old['purchase_currency'], $old['order_no'], $old['department_id'], $old['image'], $serial]);
                        $newId = $pdo->lastInsertId();
                        addAssetLog($pdo, $newId, $current_user_id, 'created', ($isTr ? "Hızlı stok girişi ile eklendi." : "Added via quick stock adjustment.") . ($serial ? " (SN: $serial)" : ""), null, 'component');
                    }
                    $_SESSION['mesaj'] = $isTr ? "$qtyChange adet yeni parça eklendi." : "$qtyChange new items added.";
                } else {
                    $diff = abs($qtyChange);
                    $freeItems = $pdo->query("SELECT c.id FROM asset_components c LEFT JOIN asset_component_checkouts acc ON c.id = acc.component_id WHERE c.$whereGroup AND acc.id IS NULL AND c.deleted_at IS NULL LIMIT $diff")->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($freeItems)) {
                        $deletedCount = count($freeItems);
                        $idsToDel = implode(',', $freeItems);
                        $pdo->exec("UPDATE asset_components SET deleted_at = NOW() WHERE id IN ($idsToDel)");
                        foreach ($freeItems as $fid)
                            addAssetLog($pdo, $fid, $current_user_id, 'deleted', ($isTr ? "Hızlı stok çıkışı ile silindi." : "Removed via quick stock adjustment."), null, 'component');
                        $_SESSION['mesaj'] = $isTr ? "$deletedCount adet boşta parça stoktan düşüldü." : "$deletedCount free items removed from stock.";
                    } else {
                        $_SESSION['hata'] = $isTr ? "Düşülecek boşta parça bulunamadı!" : "No free items found to remove!";
                    }
                }
            }
        }
        header("Location: varliklar?view=$view_submit");
        exit;
    } elseif ($action == 'scrap') {
        $id = intval($_POST['asset_id'] ?? 0);
        $view_submit = $_POST['view'] ?? 'assets';
        $qtyToScrap = intval($_POST['quantity'] ?? 1);
        if ($id > 0) {
            if ($view_submit == 'assets') {
                $old = $pdo->query("SELECT * FROM assets WHERE id = $id")->fetch();
                if ($old && ($old['assigned_user_id'] > 0 || $old['asset_id'] > 0)) {
                    $requiresDigitalSignature = false;
                    if ($old['assigned_user_id'] > 0) {
                        $sig = $pdo->prepare("SELECT status, bypass_user_signature FROM asset_signatures WHERE asset_id = ? AND user_id = ? AND action_type = 'checkout' ORDER BY id DESC LIMIT 1");
                        $sig->execute([$id, $old['assigned_user_id']]);
                        $sigRow = $sig->fetch();
                        if ($sigRow) {
                            if ($sigRow['status'] === 'pending_user' || $sigRow['status'] === 'pending_admin' || ($sigRow['status'] === 'approved' && !$sigRow['bypass_user_signature'])) {
                                $requiresDigitalSignature = true;
                            }
                        }
                    }
                    
                    if ($requiresDigitalSignature) {
                        $_SESSION['hata'] = $isTr 
                            ? "Bu cihaz dijital onaylı/imzalı zimmet altındadır. Hurdaya ayırmadan önce lütfen 'Geri Al' (İade) işlemini tamamlayın." 
                            : "This device is under digitally signed assignment. Before scrapping, please complete the digital signature return.";
                        header("Location: varliklar?view=assets");
                        exit;
                    } else {
                        // Auto-checkin for paper/wet signature
                        $logMsg = $isTr 
                            ? "Durum 'Hurda' yapıldığı için zimmet otomatik olarak geri alındı (Islak İmza)." 
                            : "Automatic check-in because status changed to 'Scrap' (Wet Signature).";
                        
                        $pdo->prepare("INSERT INTO asset_timeline (asset_id, item_type, user_id, event_type, event_description) VALUES (?, 'asset', ?, 'checkin', ?)")
                            ->execute([$id, $_SESSION['user_id'], $logMsg]);

                        if ($old['assigned_user_id'] > 0) {
                            $notesData = json_encode([
                                'return_reason' => $isTr ? 'Durum Değişikliği (Hurdaya Ayırma)' : 'Status Change (Scrap)',
                                'return_status' => 'hasarli',
                                'damage_note' => $isTr ? 'Otomatik Geri Alma' : 'Automatic Check-in'
                            ]);
                            $stmtIns = $pdo->prepare("INSERT INTO asset_signatures (asset_id, user_id, status, signed_at, notes, action_type, admin_id, bypass_user_signature) VALUES (?, ?, 'approved', NOW(), ?, 'checkin', ?, 1)");
                            $stmtIns->execute([$id, $old['assigned_user_id'], $notesData, $current_user_id]);
                        }
                    }
                }
                $pdo->prepare("UPDATE assets SET status_id = 6, assigned_user_id = NULL, asset_id = NULL WHERE id = ?")->execute([$id]);
                addAssetLog($pdo, $id, $current_user_id, 'scrap', $isTr ? "Varlık hurdaya ayrıldı." : "Asset scrapped.", null, 'asset');
            } elseif ($view_submit == 'accessories' || $view_submit == 'components') {
                $table = "asset_" . $view_submit;
                $old = $pdo->query("SELECT total_qty, status FROM $table WHERE id = $id")->fetch();
                if ($old) {
                    $total = (int) $old['total_qty'];
                    if ($qtyToScrap > $total)
                        $qtyToScrap = $total;

                    if ($total > $qtyToScrap && $qtyToScrap > 0) {
                        // Split item
                        $pdo->prepare("UPDATE $table SET total_qty = total_qty - ? WHERE id = ?")->execute([$qtyToScrap, $id]);

                        $pdo->exec("CREATE TEMPORARY TABLE temp_scrap SELECT * FROM $table WHERE id = $id");
                        $pdo->exec("UPDATE temp_scrap SET id = NULL, total_qty = $qtyToScrap, status = 'Hurda'");
                        $pdo->prepare("INSERT INTO $table SELECT * FROM temp_scrap")->execute();
                        $newId = $pdo->lastInsertId();
                        $pdo->exec("DROP TEMPORARY TABLE temp_scrap");

                        addAssetLog($pdo, $id, $current_user_id, 'scrap', $isTr ? "$qtyToScrap adet hurdaya ayrıldı. (Toplamdan düşüldü)" : "$qtyToScrap units scrapped. (Reduced from total)", $newId, getSingularType($view_submit));
                    } else {
                        $pdo->prepare("UPDATE $table SET status = 'Hurda' WHERE id = ?")->execute([$id]);
                        addAssetLog($pdo, $id, $current_user_id, 'scrap', $isTr ? "Öğe hurdaya ayrıldı." : "Item scrapped.", null, getSingularType($view_submit));
                    }
                }
            }
            $_SESSION['mesaj'] = $isTr ? "Başarıyla hurdaya ayrıldı." : "Successfully scrapped.";
        }
        header("Location: varliklar?view=$view_submit");
        exit;
    } elseif ($action == 'delete') {
        $view_submit = $_POST['view'] ?? 'assets';
        $id = intval($_POST['asset_id']);

        if ($view_submit == 'predefined') {
            $p_type = $_POST['type'] ?? '';
            $table = inventoryTableMeta($p_type)['table'];
        } else {
            $table = ($view_submit == 'assets') ? 'assets' : "asset_" . $view_submit;
        }

        // Extra logic for assets: if assigned or has linked items, return them before delete/trash
        if ($table === 'assets') {
            // 0. User & Asset Assignment Cleanup
            $ast = $pdo->query("SELECT a.assigned_user_id, a.asset_id, u.fullname as user_name, pa.name as asset_name FROM assets a LEFT JOIN users u ON a.assigned_user_id = u.id LEFT JOIN assets pa ON a.asset_id = pa.id WHERE a.id = $id")->fetch();
            if ($ast) {
                if ($ast['assigned_user_id']) {
                    $uName = $ast['user_name'] ?: ($isTr ? 'Bilinmeyen Kullanıcı' : 'Unknown');
                    addAssetLog($pdo, $id, $current_user_id, 'checkin', $isTr ? "Zimmetli personelden ($uName) geri alındı (Varlık silinmesi/çöpe atılması nedeniyle)." : "Checked in from user: $uName (Asset deleted).", $ast['assigned_user_id'], 'asset', 'user');
                }
                if ($ast['asset_id']) {
                    $paName = $ast['asset_name'] ?: ($isTr ? 'Cihaz' : 'Device');
                    addAssetLog($pdo, $id, $current_user_id, 'checkin', $isTr ? "Bağlı olduğu cihazdan ($paName) geri alındı (Varlık silinmesi/çöpe atılması nedeniyle)." : "Checked in from device: $paName (Asset deleted).", $ast['asset_id'], 'asset', 'asset');
                }
                $pdo->prepare("UPDATE assets SET assigned_user_id = NULL, asset_id = NULL WHERE id = ?")->execute([$id]);
            }

            // 1. Linked Licenses (Both direct and checkout entries)
            // Handle direct links in main table
            $pdo->prepare("UPDATE asset_licenses SET asset_id = NULL WHERE asset_id = ?")->execute([$id]);

            // Handle multi-seat checkouts
            $assignedLicenses = $pdo->query("SELECT license_id FROM asset_license_checkouts WHERE asset_id = $id")->fetchAll();
            foreach ($assignedLicenses as $lic) {
                $pdo->prepare("DELETE FROM asset_license_checkouts WHERE asset_id = ? AND license_id = ?")->execute([$id, $lic['license_id']]);
                addAssetLog($pdo, $lic['license_id'], $current_user_id, 'checkin', $isTr ? "Lisans cihaz üzerinden otomatik olarak boşa çıkarıldı (Cihaz silinmesi nedeniyle)." : "License automatically unassigned (Device deleted/trashed).", $id, 'license');
            }

            // 2. Linked Accessories (Both direct and checkout entries)
            // Handle direct links in main table
            $pdo->prepare("UPDATE asset_accessories SET asset_id = NULL WHERE asset_id = ?")->execute([$id]);

            // Handle multi-item checkouts
            $assignedAccessories = $pdo->query("SELECT accessory_id FROM asset_accessory_checkouts WHERE asset_id = $id")->fetchAll();
            foreach ($assignedAccessories as $acc) {
                $pdo->prepare("DELETE FROM asset_accessory_checkouts WHERE asset_id = ? AND accessory_id = ?")->execute([$id, $acc['accessory_id']]);
                addAssetLog($pdo, $acc['accessory_id'], $current_user_id, 'checkin', $isTr ? "Aksesuar cihaz üzerinden otomatik olarak iade alındı (Cihaz silinmesi nedeniyle)." : "Accessory automatically returned (Device deleted/trashed).", $id, 'accessory');
            }

            // Linked Components
            $pdo->prepare("UPDATE asset_components SET asset_id = NULL WHERE asset_id = ?")->execute([$id]);

            // Consumables link (if any)
            $pdo->prepare("UPDATE asset_consumables SET asset_id = NULL WHERE asset_id = ?")->execute([$id]);
        }

        // Check if already deleted for permanent deletion
        $isAlreadyDeleted = false;
        if (tableHasColumn($pdo, $table, 'deleted_at')) {
            $check = $pdo->prepare("SELECT deleted_at FROM $table WHERE id = ?");
            $check->execute([$id]);
            $isAlreadyDeleted = !empty($check->fetchColumn());
        }

        // --- ASSIGNMENT CLEANUP BEFORE DELETE ---
        if ($table === 'asset_licenses' || $table === 'asset_accessories' || $table === 'asset_components' || $table === 'asset_consumables') {
            $singular = getSingularType($view_submit);
            $checkoutTable = "asset_" . $singular . "_checkouts";
            $itmName = $pdo->query("SELECT " . ($table === 'asset_licenses' ? 'software_name' : 'name') . " FROM $table WHERE id = $id")->fetchColumn() ?: $singular;

            // For pool items: automatically return assignments
            if ($table === 'asset_licenses' || $table === 'asset_accessories' || $table === 'asset_consumables') {
                $checkAssigned = $pdo->query("SELECT SUM(quantity) FROM $checkoutTable WHERE " . $singular . "_id = $id")->fetchColumn() ?: 0;
                if ($checkAssigned > 0) {
                    $pdo->prepare("DELETE FROM $checkoutTable WHERE " . $singular . "_id = ?")->execute([$id]);
                    addAssetLog($pdo, $id, $current_user_id, 'checkin', $isTr ? "Zimmetler otomatik olarak geri alındı (Öğe silinmesi nedeniyle)." : "Assignments automatically returned (Item deleted).", 0, $singular);
                }
            }

            // Legacy/Direct check cleanup
            $pdo->prepare("UPDATE $table SET assigned_user_id = NULL, asset_id = NULL WHERE id = ?")->execute([$id]);
        }

        if ($isAlreadyDeleted) {
            // Already soft-deleted, now PERMANENTLY DELETE
            if ($table === 'asset_components') {
                // For components, we group in UI, so permanent delete should apply to the whole group of deleted items
                $itemInfo = $pdo->query("SELECT name, category_id, company_id FROM asset_components WHERE id = $id")->fetch();
                if ($itemInfo) {
                    $cName = $itemInfo['name'];
                    $cCat = (int) $itemInfo['category_id'];
                    $cComp = $itemInfo['company_id'] ? (int) $itemInfo['company_id'] : null;
                    $whereGrp = "name = " . $pdo->quote($cName) . " AND category_id = $cCat AND " . ($cComp === null ? "company_id IS NULL" : "company_id = $cComp");

                    $stmt = $pdo->prepare("DELETE FROM asset_components WHERE $whereGrp AND deleted_at IS NOT NULL");
                    $stmt->execute();
                } else {
                    $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
                }
            } else {
                $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
            }
            $_SESSION['mesaj'] = $isTr ? "Kalıcı olarak başarıyla silindi." : __("Permanently deleted successfully.");
        } elseif (tableHasColumn($pdo, $table, 'deleted_at')) {
            // Soft delete
            $singular = getSingularType($view_submit);
            $transl = match ($singular) {
                'asset' => ($isTr ? 'Demirbaş' : 'Asset'),
                'accessory' => ($isTr ? 'Aksesuar' : 'Accessory'),
                'consumable' => ($isTr ? 'Sarf malzeme' : 'Consumable'),
                'component' => ($isTr ? 'Bileşen' : 'Component'),
                'license' => ($isTr ? 'Lisans' : 'License'),
                default => $singular
            };

            $pdo->prepare("UPDATE $table SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
            addAssetLog($pdo, $id, $current_user_id, 'deleted', $isTr ? "$transl çöp kutusuna taşındı." : "$transl moved to trash.", null, $singular);
            $_SESSION['mesaj'] = $isTr ? "Kayıt çöp kutusuna taşındı." : __("Moved to trash successfully.");
        } else {
            // Table doesn't support soft delete, so hard delete
            $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
            $_SESSION['mesaj'] = $isTr ? "Başarıyla silindi." : __("Deleted successfully.");
        }

        $is_trash = (($_GET['view_deleted'] ?? $_POST['view_deleted'] ?? '0') == '1');
        $type_param = ($_GET['type'] ?? $_POST['type'] ?? '');
        $redirect = getCleanInventoryRedirectUrl($view_submit, $is_trash, $type_param);
        header("Location: $redirect");
        exit;
    }
    } catch (PDOException $e) {
        $view_submit = $_POST['view'] ?? 'assets';
        $_SESSION['mesaj'] = ($isTr ? "Hata: Bir veritabanı sorunu oluştu: " : "Error: A database issue occurred: ") . $e->getMessage();
        
        if (!empty($uploadedImage)) {
            $cleanupFolder = ($_POST['action'] ?? '') === 'save_predefined' ? ($_POST['type'] ?? 'categories') : $view_submit;
            $filePath = __DIR__ . '/../../public/uploads/' . trim($cleanupFolder, '/') . '/' . $uploadedImage;
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        
        if (isset($_POST['action']) && $_POST['action'] === 'save') {
            $_SESSION['invalid_post_data'] = $_POST;
            $postLabels = [];
            if (!empty($_POST['assigned_user_id'])) {
                $postLabels['assigned_user_id'] = $pdo->query("SELECT fullname FROM users WHERE id = " . intval($_POST['assigned_user_id']))->fetchColumn();
            }
            if (!empty($_POST['model_id'])) {
                $postLabels['model_id'] = $pdo->query("SELECT name FROM asset_models WHERE id = " . intval($_POST['model_id']))->fetchColumn();
            }
            if (!empty($_POST['department_id'])) {
                $postLabels['department_id'] = $pdo->query("SELECT bolum_adi FROM bolumler WHERE id = " . intval($_POST['department_id']))->fetchColumn();
            }
            if (!empty($_POST['manufacturer_id'])) {
                $postLabels['manufacturer_id'] = $pdo->query("SELECT name FROM asset_manufacturers WHERE id = " . intval($_POST['manufacturer_id']))->fetchColumn();
            }
            if (!empty($_POST['supplier_id'])) {
                $postLabels['supplier_id'] = $pdo->query("SELECT name FROM asset_suppliers WHERE id = " . intval($_POST['supplier_id']))->fetchColumn();
            }
            if (!empty($_POST['asset_id_assigned'])) {
                $postLabels['asset_id_assigned'] = $pdo->query("SELECT name FROM assets WHERE id = " . intval($_POST['asset_id_assigned']))->fetchColumn();
            }
            if (!empty($_POST['asset_id_link'])) {
                $postLabels['asset_id_link'] = $pdo->query("SELECT name FROM assets WHERE id = " . intval($_POST['asset_id_link']))->fetchColumn();
            }
            $_SESSION['invalid_post_labels'] = $postLabels;
        }
        
        $is_trash = (($_GET['view_deleted'] ?? $_POST['view_deleted'] ?? '0') == '1');
        $type_param = ($_GET['type'] ?? $_POST['type'] ?? '');
        $redirect = getCleanInventoryRedirectUrl($view_submit, $is_trash, $type_param);
        header("Location: $redirect");
        exit;
    } catch (Exception $e) {
        $view_submit = $_POST['view'] ?? 'assets';
        $_SESSION['mesaj'] = ($isTr ? "Hata: Bir sorun oluştu: " : "Error: An issue occurred: ") . $e->getMessage();
        
        if (!empty($uploadedImage)) {
            $cleanupFolder = ($_POST['action'] ?? '') === 'save_predefined' ? ($_POST['type'] ?? 'categories') : $view_submit;
            $filePath = __DIR__ . '/../../public/uploads/' . trim($cleanupFolder, '/') . '/' . $uploadedImage;
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        
        if (isset($_POST['action']) && $_POST['action'] === 'save') {
            $_SESSION['invalid_post_data'] = $_POST;
            $postLabels = [];
            if (!empty($_POST['assigned_user_id'])) {
                $postLabels['assigned_user_id'] = $pdo->query("SELECT fullname FROM users WHERE id = " . intval($_POST['assigned_user_id']))->fetchColumn();
            }
            if (!empty($_POST['model_id'])) {
                $postLabels['model_id'] = $pdo->query("SELECT name FROM asset_models WHERE id = " . intval($_POST['model_id']))->fetchColumn();
            }
            if (!empty($_POST['department_id'])) {
                $postLabels['department_id'] = $pdo->query("SELECT bolum_adi FROM bolumler WHERE id = " . intval($_POST['department_id']))->fetchColumn();
            }
            if (!empty($_POST['manufacturer_id'])) {
                $postLabels['manufacturer_id'] = $pdo->query("SELECT name FROM asset_manufacturers WHERE id = " . intval($_POST['manufacturer_id']))->fetchColumn();
            }
            if (!empty($_POST['supplier_id'])) {
                $postLabels['supplier_id'] = $pdo->query("SELECT name FROM asset_suppliers WHERE id = " . intval($_POST['supplier_id']))->fetchColumn();
            }
            if (!empty($_POST['asset_id_assigned'])) {
                $postLabels['asset_id_assigned'] = $pdo->query("SELECT name FROM assets WHERE id = " . intval($_POST['asset_id_assigned']))->fetchColumn();
            }
            if (!empty($_POST['asset_id_link'])) {
                $postLabels['asset_id_link'] = $pdo->query("SELECT name FROM assets WHERE id = " . intval($_POST['asset_id_link']))->fetchColumn();
            }
            $_SESSION['invalid_post_labels'] = $postLabels;
        }
        
        $is_trash = (($_GET['view_deleted'] ?? $_POST['view_deleted'] ?? '0') == '1');
        $type_param = ($_GET['type'] ?? $_POST['type'] ?? '');
        $redirect = getCleanInventoryRedirectUrl($view_submit, $is_trash, $type_param);
        header("Location: $redirect");
        exit;
    }
}

if (isset($_POST['action'])) {
    try {
        require_csrf_token();
    require_once __DIR__ . '/../includes/mailer.php';
    $action = $_POST['action'] ?? '';
    $view_submit = $_POST['view'] ?? 'assets';
    // Support both 'asset_id' (modern) and 'id' (from some detail page forms)
    $item_id = intval($_POST['asset_id'] ?? ($_POST['id'] ?? 0));
    $target_id = intval($_POST['target_id'] ?? 0);
    $target_type = $_POST['target_type'] ?? 'user';
    $requested_qty = intval($_POST['quantity'] ?? 1);
    $notes = trim((string) ($_POST['assignment_notes'] ?? ''));
    $noteSuffix = $notes !== '' ? ' | Notlar: ' . $notes : '';
    $current_user_id = $_SESSION['user_id'] ?? 0;

    if ($action == 'restore') {
        $table = ($view_submit == 'predefined') ? inventoryTableMeta($_POST['type'] ?? 'categories')['table'] : (($view_submit == 'assets') ? 'assets' : "asset_" . $view_submit);
        if (tableHasColumn($pdo, $table, 'deleted_at')) {
            $pdo->prepare("UPDATE $table SET deleted_at = NULL WHERE id = ?")->execute([$item_id]);
            if ($view_submit == 'assets') {
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
                $pdo->prepare("UPDATE assets SET status_id = ? WHERE id = ?")->execute([$readyStatusId, $item_id]);
            }
        }
        $_SESSION['mesaj'] = __("Restored successfully.");
    } elseif ($action == 'delete_log') {
        $log_id = intval($_POST['log_id']);
        $pdo->prepare("UPDATE asset_timeline SET is_deleted = 1 WHERE id = ?")->execute([$log_id]);
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action == 'delete_multiple_logs') {
        $ids = array_map('intval', explode(',', $_POST['ids'] ?? ''));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE asset_timeline SET is_deleted = 1 WHERE id IN ($placeholders)")->execute($ids);
        }
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action == 'update_instance_serial') {
        if (!verify_csrf_token()) {
            echo json_encode(['success' => false, 'message' => ($isTr ? 'Güvenlik doğrulaması başarısız (CSRF). Lütfen sayfayı yenileyin.' : 'Security validation failed (CSRF). Please refresh.')]);
            exit;
        }
        $serial = trim($_POST['serial_no'] ?? '');

        // Log the change
        $oldInfo = $pdo->query("SELECT name, serial_no FROM asset_components WHERE id = $item_id")->fetch();
        $pdo->prepare("UPDATE asset_components SET serial_no = ? WHERE id = ?")->execute([$serial, $item_id]);

        $compName = $oldInfo['name'] ?? 'Component';
        $oldSerial = $oldInfo['serial_no'] ?: ($isTr ? 'Yok' : 'None');
        $logMsg = $isTr ? "$compName seri numarası güncellendi: $oldSerial -> $serial" : "$compName serial number updated: $oldSerial -> $serial";
        addAssetLog($pdo, $item_id, $current_user_id, 'updated', $logMsg, null, 'component');

        echo json_encode(['success' => true]);
        exit;
    } elseif ($action == 'bulk_delete') {
        $ids = array_map('intval', explode(',', $_POST['ids'] ?? ''));
        $is_trash = (($_GET['view_deleted'] ?? $_POST['view_deleted'] ?? '0') == '1');
        $table = ($view_submit == 'predefined') ? inventoryTableMeta($_POST['type'] ?? 'categories')['table'] : (($view_submit == 'assets') ? 'assets' : "asset_" . $view_submit);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // --- LOGGING DELETION ---
            $nameCol = ($view_submit == 'licenses') ? 'software_name' : 'name';
            try {
                $stmtFetch = $pdo->prepare("SELECT id, $nameCol FROM $table WHERE id IN ($placeholders)");
                $stmtFetch->execute($ids);
                $toDelete = $stmtFetch->fetchAll();

                if ($is_trash || !tableHasColumn($pdo, $table, 'deleted_at')) {
                    $pdo->prepare("DELETE FROM $table WHERE id IN ($placeholders)")->execute($ids);
                    $eventDesc = $isTr ? "Sistemden kalıcı olarak silindi." : "Permanently deleted from system.";
                    $eventType = 'deleted';
                } else {
                    $pdo->prepare("UPDATE $table SET deleted_at = NOW() WHERE id IN ($placeholders)")->execute($ids);
                    $eventDesc = $isTr ? "Çöp kutusuna taşındı." : "Moved to trash.";
                    $eventType = 'deleted';
                }

                $singular = getSingularType($view_submit);
                foreach ($toDelete as $item) {
                    addAssetLog($pdo, $item['id'], $current_user_id, $eventType, $eventDesc . " (" . $item[$nameCol] . ")", null, $singular);
                }
            } catch (Exception $e) {
                error_log("Bulk delete log error: " . $e->getMessage());
            }
        }
        $_SESSION['mesaj'] = __("Selected items processed successfully.");
    } elseif ($action == 'bulk_delete_consumable_logs') {
        $ids_raw = $_POST['ids'] ?? '';
        if ($ids_raw === 'all') {
            $pdo->exec("DELETE FROM asset_consumable_checkouts");
            $_SESSION['mesaj'] = $isTr ? "Tüm sarf malzeme hareket geçmişi temizlendi." : "All consumable history cleared.";
        } else {
            $ids = array_map('intval', explode(',', $ids_raw));
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM asset_consumable_checkouts WHERE id IN ($placeholders)")->execute($ids);
            }
            $_SESSION['mesaj'] = $isTr ? "Seçilen hareket kayıtları silindi." : "Selected logs deleted.";
        }
        header("Location: varliklar?view=consumables");
        exit;
    } elseif ($action == 'upload_attachment') {
        header('Content-Type: application/json');
        try {
            $role = $_SESSION['role'] ?? 3;
            if ($role != 1 && !hasPermission('varliklar_edit')) {
                throw new Exception($isTr ? "Dosya yüklemek için yetkiniz bulunmamaktadır." : "You do not have permission to upload documents.");
            }
            $entity_id   = (int)($_POST['entity_id'] ?? 0);
            // Whitelist: sadece izin verilen entity tiplerini kabul et
            $allowed_types = ['asset', 'accessory', 'license', 'consumable', 'component'];
            $entity_type = in_array($_POST['entity_type'] ?? '', $allowed_types)
                ? $_POST['entity_type']
                : 'asset';
            $doc_type    = $_POST['document_type'] ?? 'handover';

            if (!$entity_id) throw new Exception($isTr ? "ID bulunamadı." : "ID not found.");
            if (!isset($_FILES['attachment_file']) || $_FILES['attachment_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception($isTr ? "Dosya yükleme hatası!" : "File upload error!");
            }

            $file         = $_FILES['attachment_file'];
            $originalName = basename($file['name']);                              // DB'de arama için orijinal isim
            $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowed      = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

            if (!in_array($ext, $allowed)) {
                throw new Exception($isTr ? "Desteklenmeyen dosya formatı!" : "Unsupported file format!");
            }

            // Gerçek MIME tipi kontrolü (uzantı sahteciliğine karşı)
            $allowedMimes = [
                'application/pdf', 'image/jpeg', 'image/png', 'image/gif',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
            $realMime = mime_content_type($file['tmp_name']);
            if (!in_array($realMime, $allowedMimes)) {
                throw new Exception($isTr ? "Desteklenmeyen dosya içeriği!" : "Unsupported file content type!");
            }

            $uploadDir = __DIR__ . '/../storage/attachments/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0775, true);

            // Diskteki isim: rastgele — orijinal isim DB'de saklanır
            $safeFileName = $entity_type . '_' . $entity_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $fullPath     = $uploadDir . $safeFileName;
            $dbPath       = 'app/storage/attachments/' . $safeFileName;

            if (move_uploaded_file($file['tmp_name'], $fullPath)) {
                $stmt = $pdo->prepare("INSERT INTO attachments (entity_type, entity_id, document_type, file_name, file_path, file_type, file_size, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([ $entity_type, $entity_id, $doc_type, $originalName, $dbPath, $realMime, $file['size'], $_SESSION['user_id'] ?? 1 ]);
                echo json_encode(['success' => true]);
            } else {
                throw new Exception($isTr ? "Dosya kaydedilemedi!" : "Could not save file!");
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    } elseif ($action == 'delete_attachment') {
        header('Content-Type: application/json');
        try {
            $role = $_SESSION['role'] ?? 3;
            if ($role != 1 && !hasPermission('varliklar_edit')) {
                throw new Exception($isTr ? "Belge silmek için yetkiniz bulunmamaktadır." : "You do not have permission to delete documents.");
            }
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception("ID not found.");

            $stmt = $pdo->prepare("SELECT file_path FROM attachments WHERE id = ?");
            $stmt->execute([$id]);
            $atch = $stmt->fetch();

            if ($atch) {
                $fullPath = __DIR__ . '/../../' . $atch['file_path'];
                if (file_exists($fullPath)) unlink($fullPath);
                $pdo->prepare("DELETE FROM attachments WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true]);
            } else {
                throw new Exception("Attachment not found.");
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    } elseif ($action == 'approve_signature') {
        ob_clean();
        header('Content-Type: application/json');
        try {
            $sig_notes = trim($_POST['notes'] ?? '');
            $signature = $_POST['signature'] ?? '';
            $admin_signature = $_POST['admin_signature'] ?? '';
            $admin_name = postValue('admin_name', '');
            $item_type = $_POST['item_type'] ?? 'asset';
            if (!in_array($item_type, ['asset', 'accessory', 'component', 'license', 'consumable'])) {
                throw new Exception($isTr ? "Geçersiz varlık türü." : "Invalid item type.");
            }
            $item_id = intval($_POST['asset_id'] ?? 0);
            $signature_id = intval($_POST['signature_id'] ?? 0);
            
            if (!$item_id) throw new Exception($isTr ? "Varlık ID bulunamadı." : "Asset ID not found.");
            
            if ($signature_id > 0) {
                $stmtSig = $pdo->prepare("SELECT * FROM asset_signatures WHERE id = ?");
                $stmtSig->execute([$signature_id]);
                $sigRecord = $stmtSig->fetch();
            } else {
                $whereClause = "";
                $whereParams = [];
                if ($item_type === 'asset') {
                    $whereClause = "WHERE asset_id = ?";
                    $whereParams[] = $item_id;
                } elseif ($item_type === 'accessory') {
                    $whereClause = "WHERE accessory_id = ?";
                    $whereParams[] = $item_id;
                } elseif ($item_type === 'component') {
                    $whereClause = "WHERE component_id = ?";
                    $whereParams[] = $item_id;
                } elseif ($item_type === 'license') {
                    $whereClause = "WHERE license_id = ?";
                    $whereParams[] = $item_id;
                }
                
                $stmtSig = $pdo->prepare("SELECT * FROM asset_signatures $whereClause ORDER BY id DESC LIMIT 1");
                $stmtSig->execute($whereParams);
                $sigRecord = $stmtSig->fetch();
            }
            
            if (!$sigRecord) throw new Exception($isTr ? "İmza kaydı bulunamadı." : "Signature record not found.");
            
            $status = $sigRecord['status'];
            $actionType = $sigRecord['action_type'];
            $target_user_id = $sigRecord['user_id'];
            
            $isAdmin = in_array($current_user_role, [1, 3]);
            $isBypass = isset($_POST['bypass']) && $_POST['bypass'] == '1';
            
            if ($isAdmin && ($isBypass || (!empty($signature) && !empty($admin_signature)))) {
                // İki imza birden geldiyse veya Bypass seçildiyse (Admin Panelinden)
                $stmt = $pdo->prepare("UPDATE asset_signatures SET status = 'approved', signed_at = NOW(), signature_image = ?, admin_signature_image = ?, admin_id = ?, admin_signed_at = NOW(), bypass_user_signature = ?, notes = ?, admin_name = ? WHERE id = ?");
                $stmt->execute([$signature, $admin_signature, $current_user_id, $isBypass ? 1 : 0, $sig_notes, $admin_name, $sigRecord['id']]);
                $status = 'approved';
            } else {
                $status = $sigRecord['status'];
                if ($status === 'pending') $status = 'pending_user'; // FIX FOR OLD RECORDS
                
                if ($status === 'pending_user') {
                    // 1. Aşama: Personel imzalıyor
                    if (empty($signature)) throw new Exception("İmza verisi eksik!");
                    
                    $existingNotes = $sigRecord['notes'] ?? '';
                    $finalNotes = $sig_notes;
                    $decoded = json_decode($existingNotes, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        if (!empty($sig_notes)) {
                            $decoded['user_note'] = $sig_notes;
                        }
                        $finalNotes = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                    } else {
                        if (!empty($existingNotes) && !empty($sig_notes)) {
                            $finalNotes = $existingNotes . "\n" . $sig_notes;
                        } elseif (!empty($existingNotes)) {
                            $finalNotes = $existingNotes;
                        }
                    }
                    
                    $itemIdForLog = $sigRecord['asset_id'] ?? $sigRecord['accessory_id'] ?? $sigRecord['component_id'] ?? $sigRecord['license_id'] ?? 0;
                    $itemTypeForLog = $sigRecord['asset_id'] ? 'asset' : ($sigRecord['accessory_id'] ? 'accessory' : ($sigRecord['component_id'] ? 'component' : 'license'));
                    $assigner_id = (int)$pdo->query("SELECT user_id FROM asset_timeline WHERE asset_id = " . intval($itemIdForLog) . " AND item_type = '$itemTypeForLog' AND event_type IN ('checkout', 'checkin') ORDER BY id DESC LIMIT 1")->fetchColumn();
                    
                    // Eğer admin kendine bir şey zimmetliyorsa (ve kendi başlattıysa), tek imza ile işlemi tamamla (Kaldırıldı - Her zaman çift imza gerekir)
                    // if ($isAdmin && $target_user_id == $current_user_id && $assigner_id == $current_user_id) {
                    //     $stmt = $pdo->prepare("UPDATE asset_signatures SET status = 'approved', signed_at = NOW(), signature_image = ?, admin_signature_image = ?, admin_id = ?, admin_signed_at = NOW(), notes = ? WHERE id = ?");
                    //     $stmt->execute([$signature, $signature, $current_user_id, $finalNotes, $sigRecord['id']]);
                    //     $status = 'approved';
                    //     $admin_signature = $signature;
                    // } else {
                        $stmt = $pdo->prepare("UPDATE asset_signatures SET status = 'pending_admin', signed_at = NOW(), signature_image = ?, notes = ? WHERE id = ?");
                        $stmt->execute([$signature, $finalNotes, $sigRecord['id']]);
                        echo json_encode(['success' => true, 'message' => ($isTr ? "İmzanız kaydedildi. Yönetici onayı bekleniyor." : "Signature saved. Waiting for admin approval.")]);
                        exit;
                    // }
                } elseif ($status === 'pending_admin') {
                // 2. Aşama: Admin imzalıyor
                if (!$isAdmin) throw new Exception("Bu işlemi sadece yetkili yapabilir!");
                // Herhangi bir yönetici/admin imzalayabilir (Kısıtlama kaldırıldı)
                $stmt = $pdo->prepare("UPDATE asset_signatures SET status = 'approved', admin_signature_image = ?, admin_id = ?, admin_signed_at = NOW(), admin_name = ? WHERE id = ?");
                $stmt->execute([$admin_signature ?: null, $current_user_id, $admin_name, $sigRecord['id']]);
                $status = 'approved';
                
                // Admin kendi ekranında imzayı onlarken, personel imzası daha önce veritabanına kaydedildiği için $signature boş gelebilir, db'den okuyalım:
                if (empty($signature)) $signature = $sigRecord['signature_image'];
            }
            }
            
            if ($status === 'approved') {
                // HER İKİ İMZA DA TAMAMLANDI - İşlemleri gerçekleştir
                require_once __DIR__ . '/../includes/asset_helpers.php';
                
                if ($actionType === 'checkin') {
                    // İade işlemini şimdi gerçekten gerçekleştir
                    $notesData = json_decode($sigRecord['notes'], true);
                    $returnReason = $notesData['return_reason'] ?? '';
                    $returnStatus = $notesData['return_status'] ?? '';
                    $damageNote = $notesData['damage_note'] ?? '';
                    $proxyName = $notesData['proxy_name'] ?? '';
                    $checkout_id = $notesData['checkout_id'] ?? 0;
                    $fromAssetId = $notesData['from_asset_id'] ?? 0;
                    
                    // Singular name for checkin helper
                    $view_submit_mapped = match($item_type) {
                        'license' => 'licenses',
                        'accessory' => 'accessories',
                        'consumable' => 'consumables',
                        'component' => 'components',
                        default => 'assets'
                    };
                    
                    finalizeAssetCheckin($pdo, $item_id, $view_submit_mapped, $current_user_id, $isTr, $checkout_id, $fromAssetId, $returnReason, $returnStatus, $damageNote);
                    
                    try {
                        generateReturnPDF($pdo, $item_id, $item_type, $target_user_id, $returnReason, $returnStatus, $signature, $admin_signature, $isTr, $current_user_id, $proxyName, $checkout_id);
                    } catch (Exception $e) {
                        error_log("Return PDF Generation Failed: " . $e->getMessage());
                    }
                    addAssetLog($pdo, $item_id, $current_user_id, 'approved', "İade onaylandı ve imzalandı (Resmi Tutanak Oluşturuldu). / Return approved and signed (Official Protocol Generated).", $target_user_id, $item_type, 'user');
                } else {
                    if ($item_type === 'asset') {
                        $pdo->prepare("UPDATE assets SET status_id = 2 WHERE id = ?")->execute([$item_id]);
                    }
                    // Checkout PDF Generation
                    try {
                        generateHandoverPDF($pdo, $item_id, $item_type, $target_user_id, $signature, $sig_notes, $isTr, $current_user_id, $admin_signature);
                    } catch (Exception $e) {
                        error_log("Checkout PDF Generation Failed: " . $e->getMessage());
                    }
                    
                    addAssetLog($pdo, $item_id, $current_user_id, 'approved', "Zimmet onaylandı ve imzalandı (Resmi Tutanak Oluşturuldu). / Assignment approved and signed (Official Protocol Generated).", $target_user_id, $item_type, 'user');
                    
                    // Send Assignment Notification Email after digital signature approval
                    if ($target_user_id > 0) {
                        $uInfo = $pdo->query("SELECT fullname, mail FROM users WHERE id = $target_user_id")->fetch();
                        if ($uInfo && !empty($uInfo['mail'])) {
                            $view_submit_mapped = match($item_type) {
                                'license' => 'licenses',
                                'accessory' => 'accessories',
                                'consumable' => 'consumables',
                                'component' => 'components',
                                default => 'assets'
                            };
                            $tableMail = ($view_submit_mapped == 'assets') ? 'assets' : "asset_" . $view_submit_mapped;
                            $nameColMail = ($view_submit_mapped == 'licenses') ? 'software_name' : 'name';
                            $itmNameMail = $pdo->query("SELECT $nameColMail FROM $tableMail WHERE id = $item_id")->fetchColumn() ?: $item_type;
                            $lang = $_SESSION['lang'] ?? 'tr';
                            if ($lang !== 'en') $lang = 'tr';
                            sendTemplatedMail($uInfo['mail'], $uInfo['fullname'], 'asset_assigned', [
                                'fullname' => $uInfo['fullname'],
                                'ITEM_NAME' => $itmNameMail,
                                'DATE' => date('d.m.Y H:i'),
                                'ITEM_TYPE' => $view_submit_mapped
                            ], '', $lang);
                        }
                    }
                }
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("APPROVE SIGNATURE ERROR: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    } elseif ($action == 'quick_assign') {
        $isAjax = isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1';
        if ($isAjax) {
            ob_clean();
            header('Content-Type: application/json');
        }
        try {
            $isPaperOnly = isset($_POST['paper_only']) && $_POST['paper_only'] == '1';
            $singular = getSingularType($view_submit);
            $deduct_stock = isset($_POST['deduct_stock']) && $_POST['deduct_stock'] == "1";
            $transaction_type = 'consume';

            // If it's a consumable and they uncheck 'deduct_stock', mark as 'info' so it doesn't count against stock
            if (($view_submit == 'consumables') && !$deduct_stock) {
                $transaction_type = 'info';
            }

            $table = ($view_submit == 'assets') ? 'assets' : "asset_" . $view_submit;
            $targetName = "Unknown";
            $department_id = NULL;

            if ($target_type == 'user') {
                $uData = $pdo->query("SELECT fullname, bolum FROM users WHERE id = $target_id")->fetch();
                $targetName = $uData['fullname'] ?? "Unknown";
                $department_id = normalizeDepartmentId($pdo, $uData['bolum'] ?? null);
            } else {
                $aData = $pdo->query("SELECT name, department_id FROM assets WHERE id = $target_id")->fetch();
                $targetName = $aData['name'] ?? "Unknown";
                $department_id = normalizeDepartmentId($pdo, $aData['department_id'] ?? null);
            }

            if ($view_submit == 'assets') {
                // Check current status
                $currentStatus = $pdo->query("SELECT s.type, s.name FROM assets a LEFT JOIN asset_status_labels s ON a.status_id = s.id WHERE a.id = $item_id")->fetch();
                if ($currentStatus) {
                    $currentType = $currentStatus['type'] ?? '';
                    if ($currentType === 'undeployable' || $currentType === 'archived') {
                        $statusNameNorm = normalize_turkish_mojibake($currentStatus['name'] ?? '');
                        throw new Exception($isTr ? "Bu cihaz '{$statusNameNorm}' durumundayken atama yapılamaz. Önce durumunu Hazır yapmalısınız." : "Cannot assign this device while its status is '{$statusNameNorm}'. You must change it to Ready first.");
                    }
                }

                if ($target_type == 'user') {
                    if ($target_id > 0 && function_exists('ensureUserApiKey')) {
                        $uKey = ensureUserApiKey($pdo, $target_id);
                        if (!empty($uKey) && !empty($item_id)) {
                            $stmtMac = $pdo->prepare("SELECT mac_address FROM assets WHERE id = ?");
                            $stmtMac->execute([$item_id]);
                            $mac = $stmtMac->fetchColumn();
                            if (!empty($mac)) {
                                $pdo->prepare("UPDATE agent_keys SET registered_by_client_id = ? WHERE mac_address = ?")->execute([$uKey, $mac]);
                            }
                        }
                    }
                    if ($isPaperOnly) {
                        $pdo->prepare("UPDATE assets SET assigned_user_id = ?, asset_id = NULL, status_id = 2 WHERE id = ?")->execute([$target_id, $item_id]);
                    } else {
                        $pending_status_id = null;
                        $stmtPending = $pdo->query("SELECT id FROM asset_status_labels WHERE type = 'pending' AND deleted_at IS NULL LIMIT 1");
                        $pending_status_id = $stmtPending->fetchColumn();
                        if (!$pending_status_id) {
                            $stmtPendingName = $pdo->prepare("SELECT id FROM asset_status_labels WHERE name LIKE ? AND deleted_at IS NULL LIMIT 1");
                            $stmtPendingName->execute(['%İmza Bekliyor%']);
                            $pending_status_id = $stmtPendingName->fetchColumn();
                        }
                        if (!$pending_status_id) {
                            $pdo->prepare("INSERT INTO asset_status_labels (name, type, color, show_in_nav, is_default) VALUES (?, 'pending', '#f59e0b', 0, 0)")
                                ->execute([$isTr ? 'İmza Bekliyor' : 'Pending Signature']);
                            $pending_status_id = $pdo->lastInsertId();
                        }
                        $pdo->prepare("UPDATE assets SET assigned_user_id = ?, asset_id = NULL, status_id = ? WHERE id = ?")->execute([$target_id, $pending_status_id, $item_id]);
                    }
                    if ($isPaperOnly) {
                        // Validate IDs before generating PDF
                        if (empty($item_id) || empty($target_id)) {
                            throw new Exception($isTr ? "Hata: Geçerli bir varlık ve kullanıcı seçilmelidir." : "Error: Valid asset and user must be selected.");
                        }
                        $pdo->prepare("INSERT INTO asset_signatures (asset_id, user_id, status, signed_at, notes, bypass_user_signature) 
                                       VALUES (?, ?, 'approved', NOW(), ?, 1) 
                                       ON DUPLICATE KEY UPDATE status = 'approved', signed_at = NOW(), notes = VALUES(notes),
                                                               signature_image = NULL, admin_signature_image = NULL, action_type = NULL, bypass_user_signature = 1")
                            ->execute([$item_id, $target_id, ($isTr ? 'Kağıt Zimmet / Islak İmza' : 'Paper Handover / Wet Signature')]);
                        // Paper assignments generate PDF handover documents
                        require_once __DIR__ . '/../includes/asset_helpers.php';
                        try {
                            $attachment_id = generateHandoverPDF($pdo, $item_id, 'asset', $target_id, null, ($isTr ? 'Kağıt Zimmet / Islak İmza' : 'Paper Handover / Wet Signature'), $isTr, $current_user_id, null);
                        } catch (Exception $e) {
                            error_log("Paper Handover PDF Generation Failed: " . $e->getMessage());
                        }
                    } else {
                        handleSignature($pdo, $target_id, $item_id); // Zimmet Onayı tetikle
                    }

                    if ($target_id == $current_user_id && !$isPaperOnly) {
                        $_SESSION['mesaj'] = $isTr ? "Varlık başarıyla kendinize zimmetlendi. Lütfen aşağıdan onaylayın." : "Asset assigned to yourself. Please approve below.";
                        if ($isAjax) {
                            echo json_encode(['success' => true, 'redirect' => 'varliklar?view=signatures', 'message' => $_SESSION['mesaj']]);
                            exit;
                        }
                        header("Location: varliklar?view=signatures");
                        exit;
                    }
                } else {
                    $pdo->prepare("UPDATE assets SET asset_id = ?, assigned_user_id = NULL, status_id = 2 WHERE id = ?")->execute([$target_id, $item_id]);
                }
            $isAssetTarget = ($target_type === 'asset' && $target_id > 0);
            $trMsg = $isAssetTarget ? "Cihaz $targetName cihazına zimmetlendi." : "Cihaz $targetName personeline zimmetlendi.";
            $enMsg = $isAssetTarget ? "Device assigned to device: $targetName." : "Device assigned to user: $targetName.";
            $finalLogMsg = "$trMsg$noteSuffix / $enMsg$noteSuffix";
            addAssetLog($pdo, $item_id, $current_user_id, 'checkout', $finalLogMsg, $target_id, 'asset', $target_type);
                $_SESSION['mesaj'] = $isTr ? ("Başarıyla $targetName " . ($target_type === 'user' ? "personeline" : "cihazına") . " zimmetlendi.") : "Successfully assigned to $targetName.";
            } else {
                if ($view_submit == 'consumables') {
                    // Logic for consumables with variable transaction type
                    $pdo->prepare("INSERT INTO asset_consumable_checkouts (consumable_id, " . ($target_type == 'user' ? 'user_id' : 'asset_id') . ", quantity, transaction_type, department_id, performer_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$item_id, $target_id, $requested_qty, $transaction_type, $department_id, $current_user_id, $notes]);

                    $consName = $pdo->query("SELECT name FROM asset_consumables WHERE id = $item_id")->fetchColumn() ?: 'Sarf Malzeme';
                    $isAssetTarget = ($target_type === 'asset' && $target_id > 0);







                    $isAssetTarget = ($target_type === 'asset' && $target_id > 0);
                    if ($isAssetTarget) {
                        $trMsg = "Sarf malzemesi $consName, cihaz üzerine ($targetName) zimmetlendi ($requested_qty Adet).";
                        $enMsg = "Consumable $consName assigned to device: $targetName ($requested_qty Qty).";
                    } else {
                        $trMsg = "Sarf malzemesi $consName, personele ($targetName) zimmetlendi ($requested_qty Adet).";
                        $enMsg = "Consumable $consName assigned to user: $targetName ($requested_qty Qty).";
                    }
                    if ($transaction_type === 'info') {
                        $trMsg .= " - (Stoktan düşülmedi)";
                        $enMsg .= " - (Stock not deducted)";
                    }
                    $logMsg = "$trMsg$noteSuffix / $enMsg$noteSuffix";
                    addAssetLog($pdo, $item_id, $current_user_id, 'checkout', $logMsg, $target_id, 'consumable', $target_type);
                    $_SESSION['mesaj'] = $isTr ? ("$requested_qty Adet $consName, $targetName " . ($target_type === 'user' ? "personeline" : "cihazına") . " başarıyla zimmetlendi.") : "$requested_qty units of $consName successfully assigned to $targetName.";

                    // FORCE UPDATE remaining_qty in DB
                    if ($transaction_type === 'consume') {
                        $pdo->prepare("UPDATE asset_consumables SET remaining_qty = remaining_qty - ? WHERE id = ?")->execute([$requested_qty, $item_id]);
                    }
                } else if ($view_submit === 'components') {
                    // Instance-based tracking for components
                    $itemData = $pdo->query("SELECT asset_id, assigned_user_id, name FROM asset_components WHERE id = $item_id")->fetch();
                    
                    // Clear old assignments (both table and checkout records)
                    $pdo->prepare("DELETE FROM asset_component_checkouts WHERE component_id = ?")->execute([$item_id]);
                    $pdo->prepare("UPDATE asset_components SET asset_id = NULL, assigned_user_id = NULL WHERE id = ?")->execute([$item_id]);

                    $col = ($target_type == 'user') ? 'user_id' : 'asset_id';
                    $checkoutTable = "asset_component_checkouts";
                    $insSql = "INSERT INTO $checkoutTable (component_id, $col, quantity, notes) VALUES (?, ?, ?, ?)";
                    $pdo->prepare($insSql)->execute([$item_id, $target_id, 1, $notes]);

                    // Sync main table fields
                    if ($target_type === 'asset') {
                        $pdo->prepare("UPDATE asset_components SET asset_id = ? WHERE id = ?")->execute([$target_id, $item_id]);
                    } else if ($target_type === 'user') {
                        $pdo->prepare("UPDATE asset_components SET assigned_user_id = ? WHERE id = ?")->execute([$target_id, $item_id]);
                        if ($isPaperOnly) {
                            // Validate IDs before generating PDF
                            if (empty($item_id) || empty($target_id)) {
                                throw new Exception($isTr ? "Hata: Geçerli bir bileşen ve kullanıcı seçilmelidir." : "Error: Valid component and user must be selected.");
                            }
                            $pdo->prepare("INSERT INTO asset_signatures (component_id, user_id, status, signed_at, notes, bypass_user_signature) 
                                           VALUES (?, ?, 'approved', NOW(), ?, 1) 
                                           ON DUPLICATE KEY UPDATE status = 'approved', signed_at = NOW(), notes = VALUES(notes),
                                                                   signature_image = NULL, admin_signature_image = NULL, action_type = NULL, bypass_user_signature = 1")
                                ->execute([$item_id, $target_id, ($isTr ? 'Kağıt Zimmet / Islak İmza' : 'Paper Handover / Wet Signature')]);
                            // Paper assignments generate PDF handover documents
                            require_once __DIR__ . '/../includes/asset_helpers.php';
                            try {
                                $attachment_id = generateHandoverPDF($pdo, $item_id, 'component', $target_id, null, ($isTr ? 'Kağıt Zimmet / Islak İmza' : 'Paper Handover / Wet Signature'), $isTr, $current_user_id, null);
                            } catch (Exception $e) {
                                error_log("Paper Handover PDF Generation Failed: " . $e->getMessage());
                            }
                        } else {
                            handleSignature($pdo, $target_id, $item_id, 'component'); // Zimmet Onayı tetikle
                        }
                        if ($target_id == $current_user_id && !$isPaperOnly) {
                            $_SESSION['mesaj'] = $isTr ? "Bileşen başarıyla kendinize zimmetlendi. Lütfen aşağıdan onaylayın." : "Component assigned to yourself. Please approve below.";
                            if ($isAjax) {
                                echo json_encode(['success' => true, 'redirect' => 'varliklar?view=signatures', 'message' => $_SESSION['mesaj']]);
                                exit;
                            }
                            header("Location: varliklar?view=signatures");
                            exit;
                        }
                    }

                    $itmName = $itemData['name'] ?? 'Component';

                    $isAssetTarget = ($target_type === 'asset' && $target_id > 0);
                    if ($isAssetTarget) {
                        $trMsg = "Bileşen $itmName, cihaz üzerine ($targetName) takıldı.";
                        $enMsg = "Component $itmName attached to device: $targetName.";
                    } else {
                        $trMsg = "Bileşen $itmName, personele ($targetName) zimmetlendi.";
                        $enMsg = "Component $itmName assigned to user: $targetName.";
                    }
                    $logMsg = "$trMsg$noteSuffix / $enMsg$noteSuffix";
                    addAssetLog($pdo, $item_id, $current_user_id, 'checkout', $logMsg, $target_id, 'component', $target_type);
                    
                    $_SESSION['mesaj'] = $isTr ? "$itmName, $targetName personeline başarıyla zimmetlendi." : "$itmName successfully assigned to $targetName.";
                } else {
                    // Handle other types (accessories, licenses)
                    $checkoutTable = "asset_" . $singular . "_checkouts";
                    $col = ($target_type == 'user') ? 'user_id' : 'asset_id';
                    
                    $sourceCheckoutId = $_POST['source_checkout_id'] ?? 'free';
                    $isTransfer = (in_array($view_submit, ['licenses', 'accessories', 'consumables']) && $sourceCheckoutId !== 'free' && $sourceCheckoutId !== 'stock' && intval($sourceCheckoutId) > 0);
                    $prevOwner = "";

                    if ($isTransfer) {
                        $srcId = intval($sourceCheckoutId);
                        $srcInfo = $pdo->query("SELECT c.*, u.fullname, a.name as asset_name FROM $checkoutTable c LEFT JOIN users u ON c.user_id = u.id LEFT JOIN assets a ON c.asset_id = a.id WHERE c.id = $srcId AND c.$singular" . "_id = $item_id")->fetch();
                        if ($srcInfo) {
                            $prevOwner = $srcInfo['fullname'] ?: ($srcInfo['asset_name'] ?: 'Unknown');
                            $oldQty = intval($srcInfo['quantity'] ?? 1);
                            if ($oldQty > $requested_qty) {
                                $pdo->prepare("UPDATE $checkoutTable SET quantity = quantity - ? WHERE id = ?")->execute([$requested_qty, $srcId]);
                            } else {
                                $pdo->prepare("DELETE FROM $checkoutTable WHERE id = ?")->execute([$srcId]);
                            }
                        }
                    }

                    $insSql = "INSERT INTO $checkoutTable ($singular" . "_id, $col, quantity, notes, transaction_type) VALUES (?, ?, ?, ?, 'assign')";
                    $pdo->prepare($insSql)->execute([$item_id, $target_id, $requested_qty, $notes]);

                    // Handle paper receipt for licenses and accessories when assigning to users
                    if (in_array($view_submit, ['licenses', 'accessories']) && $target_type === 'user') {
                        if ($isPaperOnly) {
                            // Validate IDs before generating PDF
                            if (empty($item_id) || empty($target_id)) {
                                throw new Exception($isTr ? "Hata: Geçerli bir ". ($view_submit === 'licenses' ? 'lisans' : 'aksesuar') . " ve kullanıcı seçilmelidir." : "Error: Valid item and user must be selected.");
                            }
                            $sigFieldName = ($view_submit === 'licenses') ? 'license_id' : 'accessory_id';
                            $pdo->prepare("INSERT INTO asset_signatures ($sigFieldName, user_id, status, signed_at, notes, bypass_user_signature) 
                                            VALUES (?, ?, 'approved', NOW(), ?, 1) 
                                            ON DUPLICATE KEY UPDATE status = 'approved', signed_at = NOW(), notes = VALUES(notes),
                                                                    signature_image = NULL, admin_signature_image = NULL, action_type = NULL, bypass_user_signature = 1")
                                ->execute([$item_id, $target_id, ($isTr ? 'Kağıt Zimmet / Islak İmza' : 'Paper Handover / Wet Signature')]);
                            // Paper assignments generate PDF handover documents
                            require_once __DIR__ . '/../includes/asset_helpers.php';
                            try {
                                $attachment_id = generateHandoverPDF($pdo, $item_id, ($view_submit === 'licenses' ? 'license' : 'accessory'), $target_id, null, ($isTr ? 'Kağıt Zimmet / Islak İmza' : 'Paper Handover / Wet Signature'), $isTr, $current_user_id, null);
                            } catch (Exception $e) {
                                error_log("Paper Handover PDF Generation Failed: " . $e->getMessage());
                            }
                        } else {
                            if ($view_submit === 'accessories') {
                                handleSignature($pdo, $target_id, $item_id, 'accessory'); // Zimmet Onayı tetikle
                            } elseif ($view_submit === 'licenses') {
                                handleSignature($pdo, $target_id, $item_id, 'license'); // Zimmet Onayı tetikle
                            }
                        }
                        if ($target_id == $current_user_id && !$isPaperOnly) {
                            $_SESSION['mesaj'] = $isTr ? ($view_submit === 'licenses' ? "Lisans başarıyla kendinize zimmetlendi. Lütfen aşağıdan onaylayın." : "Aksesuar başarıyla kendinize zimmetlendi. Lütfen aşağıdan onaylayın.") : "Item assigned to yourself. Please approve below.";
                            if ($isAjax) {
                                echo json_encode(['success' => true, 'redirect' => 'varliklar?view=signatures', 'message' => $_SESSION['mesaj']]);
                                exit;
                            }
                            header("Location: varliklar?view=signatures");
                            exit;
                        }
                    }

                    $nameCol = ($view_submit == 'licenses') ? 'software_name' : 'name';
                    $mainTable = "asset_" . $view_submit;








                    $nameCol = ($view_submit == 'licenses') ? 'software_name' : 'name';
                    $mainTable = "asset_" . $view_submit;
                    $itmName = $pdo->query("SELECT $nameCol FROM $mainTable WHERE id = $item_id")->fetchColumn() ?: $singular;
                    
                    $isAssetTarget = ($target_type === 'asset' && $target_id > 0);
                    if ($view_submit === 'licenses') {
                        if ($isAssetTarget) {
                            $trMsg = "Lisans $itmName, cihaz üzerine ($targetName) atandı.";
                            $enMsg = "License $itmName assigned to device: $targetName.";
                        } else {
                            $trMsg = "Lisans $itmName, personele ($targetName) atandı.";
                            $enMsg = "License $itmName assigned to user: $targetName.";
                        }
                    } else { // accessories
                        if ($isAssetTarget) {
                            $trMsg = "Aksesuar $itmName, cihaz üzerine ($targetName) zimmetlendi ($requested_qty Adet).";
                            $enMsg = "Accessory $itmName assigned to device: $targetName ($requested_qty Qty).";
                        } else {
                            $trMsg = "Aksesuar $itmName, personele ($targetName) zimmetlendi ($requested_qty Adet).";
                            $enMsg = "Accessory $itmName assigned to user: $targetName ($requested_qty Qty).";
                        }
                    }
                    if ($isTransfer && $prevOwner) {
                        $trMsg .= " (Devir: $prevOwner üzerinden)";
                        $enMsg .= " (Transfer from $prevOwner)";
                    }
                    $logDetails = "$trMsg / $enMsg";
                    addAssetLog($pdo, $item_id, $current_user_id, 'checkout', $logDetails . $noteSuffix, $target_id, $singular, $target_type);
                    $_SESSION['mesaj'] = $isTr ? ("$requested_qty Adet $itmName, $targetName " . ($target_type === 'user' ? "personeline" : "cihazına") . " başarıyla zimmetlendi.") : "$requested_qty units of $itmName successfully assigned to $targetName.";
                }
            }

            // Send Assignment Notification Email
            if ($target_type === 'user' && !empty($target_id)) {
                $uInfo = $pdo->query("SELECT fullname, mail FROM users WHERE id = $target_id")->fetch();
                if ($uInfo && !empty($uInfo['mail'])) {
                    $tableMail = match ($view_submit) {
                        'assets' => 'assets',
                        'licenses' => 'asset_licenses',
                        'accessories' => 'asset_accessories',
                        'consumables' => 'asset_consumables',
                        'components' => 'asset_components',
                        default => 'assets'
                    };
                    $nameColMail = ($view_submit == 'licenses') ? 'software_name' : 'name';
                    $itmNameMail = $pdo->query("SELECT $nameColMail FROM $tableMail WHERE id = $item_id")->fetchColumn() ?: $singular;
                    $lang = $_SESSION['lang'] ?? 'tr';
                    if ($lang !== 'en') $lang = 'tr';
                    sendTemplatedMail($uInfo['mail'], $uInfo['fullname'], 'asset_assigned', [
                        'fullname' => $uInfo['fullname'],
                        'ITEM_NAME' => $itmNameMail,
                        'DATE' => date('d.m.Y H:i'),
                        'ITEM_TYPE' => $view_submit
                    ], '', $lang);
                }
            }

            if ($isAjax) {
                $response = ['success' => true];
                if (isset($attachment_id) && $attachment_id > 0) {
                    $response['download_url'] = 'dashboard?route=view_attachment&id=' . $attachment_id;
                    $response['excel_url'] = 'varliklar?checkout_excel_template=' . $attachment_id;
                }
                
                // Mail Warning Check
                $mailWarning = '';
                if (!empty($_SESSION['send_warnings'])) {
                    $mailWarning = "\n\n⚠️ " . implode("\n⚠️ ", $_SESSION['send_warnings']);
                    unset($_SESSION['send_warnings']);
                }
                
                if (isset($_SESSION['mesaj'])) {
                    $response['message'] = $_SESSION['mesaj'] . $mailWarning;
                    unset($_SESSION['mesaj']);
                } else {
                    $response['message'] = ($isTr ? "Zimmet işlemi başarıyla tamamlandı." : "Assignment completed successfully.") . $mailWarning;
                }
                echo json_encode($response);
                exit;
            }
            header("Location: varliklar?view=$view_submit");
            exit;
        } catch (Exception $e) {
            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            } else {
                $_SESSION['hata'] = $e->getMessage();
                header("Location: varliklar?view=$view_submit");
                exit;
            }
        }
    } elseif ($action == 'assign' || $action == 'checkout') {
        $stmtN = ($target_type == 'user') ? $pdo->query("SELECT fullname FROM users WHERE id = $target_id") : $pdo->query("SELECT name FROM assets WHERE id = $target_id");
        $targetName = $stmtN->fetchColumn() ?: 'Unknown';

        if ($view_submit == 'assets') {
            $col = ($target_type == 'user') ? 'assigned_user_id' : 'asset_id';
            $pdo->prepare("UPDATE assets SET $col = ?, status_id = 2 WHERE id = ?")->execute([$target_id, $item_id]);
            addAssetLog($pdo, $item_id, $current_user_id, 'checkout', __("asset_assigned_to", ["user" => $targetName]) . $noteSuffix, $target_id, 'asset', $target_type);
            $_SESSION['mesaj'] = $isTr ? ("Başarıyla $targetName " . ($target_type === 'user' ? "personeline" : "cihazına") . " zimmetlendi.") : "Successfully assigned to $targetName.";
        } elseif ($view_submit == 'consumables') {
            $stockItems = $pdo->query("SELECT total_qty, 
                (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'checkin' THEN -quantity ELSE quantity END), 0) 
                 FROM asset_consumable_checkouts 
                 WHERE consumable_id = ac.id AND transaction_type IN ('consume', 'checkin')) as assigned 
                FROM asset_consumables ac WHERE id = $item_id")->fetch();
            $stock = (int) $stockItems['total_qty'];
            $assigned = (int) $stockItems['assigned'];
            $available = $stock - $assigned;

            if ($available >= $requested_qty) {
                $deptId = null;
                if ($target_id > 0) {
                    if ($target_type == 'user') {
                        $deptId = normalizeDepartmentId($pdo, $pdo->query("SELECT bolum FROM users WHERE id = $target_id")->fetchColumn());
                    } else {
                        $deptId = normalizeDepartmentId($pdo, $pdo->query("SELECT department_id FROM assets WHERE id = $target_id")->fetchColumn());
                    }
                }
                $deptSuffix = "";
                if (!empty($deptId)) {
                    $dName = $pdo->query("SELECT bolum_adi FROM bolumler WHERE id = " . (int)$deptId)->fetchColumn();
                    if ($dName)
                        $deptSuffix = " ($dName)";
                }
                $insSql = "INSERT INTO asset_consumable_checkouts (consumable_id, " . ($target_type == 'user' ? 'user_id' : 'asset_id') . ", quantity, notes, transaction_type, performer_id, department_id) VALUES (?, ?, ?, ?, 'consume', ?, ?)";
                $pdo->prepare($insSql)->execute([$item_id, $target_id, $requested_qty, $notes, $current_user_id, $deptId]);

                $consName = $pdo->query("SELECT name FROM asset_consumables WHERE id = $item_id")->fetchColumn() ?: 'Sarf Malzeme';
                $langKey = ($target_type === 'asset' && $target_id > 0) ? "consumable_assigned_to_asset" : "consumable_assigned_to";
            $isAssetTarget = ($target_type === 'asset' && $target_id > 0);
            if ($isAssetTarget) {
                $trMsg = "Sarf malzemesi $consName, cihaz üzerine ($targetName$deptSuffix) zimmetlendi ($requested_qty Adet).";
                $enMsg = "Consumable $consName assigned to device: $targetName$deptSuffix ($requested_qty Qty).";
            } else {
                $trMsg = "Sarf malzemesi $consName, personele ($targetName$deptSuffix) zimmetlendi ($requested_qty Adet).";
                $enMsg = "Consumable $consName assigned to user: $targetName$deptSuffix ($requested_qty Qty).";
            }
            $finalLogMsg = "$trMsg$noteSuffix / $enMsg$noteSuffix";
            addAssetLog($pdo, $item_id, $current_user_id, 'checkout', $finalLogMsg, $target_id, 'consumable', $target_type);
                $_SESSION['mesaj'] = $isTr ? ("$requested_qty Adet $consName, $targetName " . ($target_type === 'user' ? "personeline" : "cihazına") . " başarıyla zimmetlendi.") : "$requested_qty units of $consName successfully assigned to $targetName.";
            } else {
                $_SESSION['hata'] = "Yetersiz stok!";
            }
        } elseif (in_array($view_submit, ['accessories', 'licenses', 'components'])) {
            $table = ($view_submit == 'licenses') ? 'asset_licenses' : (($view_submit == 'accessories') ? 'asset_accessories' : 'asset_components');

            $singular = rtrim($view_submit, 's');
            if (substr($view_submit, -3) === 'ies')
                $singular = substr($view_submit, 0, -3) . 'y';

            if ($view_submit === 'licenses' || $view_submit === 'accessories') {
                // Check pool availability and Trash status
                $itemData = $pdo->query("SELECT " . ($view_submit === 'licenses' ? 'seats' : 'total_qty') . " as total, deleted_at FROM $table WHERE id = $item_id")->fetch();

                if ($itemData['deleted_at'] !== NULL) {
                    $_SESSION['hata'] = ($isTr ? "Sildikten sonra önce geri yüklenmeli lisans sonra atanmalı!" : "Item must be restored before assignment!");
                    header("Location: varliklar?view=$view");
                    exit;
                }

                $total = (int) $itemData['total'];
                $checkoutTable = "asset_" . $singular . "_checkouts";
                $assigned = $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM $checkoutTable WHERE " . $singular . "_id = $item_id AND (transaction_type = 'assign' OR transaction_type IS NULL)")->fetchColumn();

                if (($assigned + $requested_qty) <= $total) {
                    $col = ($target_type == 'user') ? 'user_id' : 'asset_id';
                    $insSql = "INSERT INTO $checkoutTable ($singular" . "_id, $col, quantity, notes, transaction_type) VALUES (?, ?, ?, ?, 'assign')";
                    $pdo->prepare($insSql)->execute([$item_id, $target_id, $requested_qty, $notes]);

                    // For legacy compatibility, also update the main table if it's the first assignment
                    if ($assigned == 0 && $requested_qty == 1) {
                        $mainCol = ($target_type == 'user') ? 'assigned_user_id' : 'asset_id';
                        $pdo->prepare("UPDATE $table SET $mainCol = ? WHERE id = ?")->execute([$target_id, $item_id]);
                    }
                    $_SESSION['mesaj'] = $isTr ? ("$requested_qty Adet $itmName, $targetName " . ($target_type === 'user' ? "personeline" : "cihazına") . " başarıyla zimmetlendi.") : "$requested_qty units of $itmName successfully assigned to $targetName.";
                } else {
                    $remaining = $total - $assigned;
                    $_SESSION['hata'] = ($isTr ? "Hata: Yeterli stok yok! (Eldeki: $remaining, Atanmak İstenen: $requested_qty)" : __("Not enough capacity! Available: $remaining"));
                    header("Location: varliklar?view=$view_submit");
                    exit;
                }
            }

            // COMPONENTS: ensure server-side same-device prevention and stock checks
            if ($view_submit === 'components') {
                $itemData = $pdo->query("SELECT total_qty, asset_id, assigned_user_id, name, deleted_at FROM asset_components WHERE id = $item_id")->fetch();
                if (!$itemData) {
                    $_SESSION['hata'] = ($isTr ? 'Bileşen bulunamadı.' : 'Component not found.');
                    header("Location: varliklar?view=components");
                    exit;
                }
                if ($itemData['deleted_at'] !== NULL) {
                    $_SESSION['hata'] = ($isTr ? "Sildikten sonra önce geri yüklenmeli, sonra atanmalı!" : "Item must be restored before assignment!");
                    header("Location: varliklar?view=components");
                    exit;
                }

                // NEW: Capture OLD assignment for "Detached" log
                $oldTargetName = '';
                $oldTargetType = '';
                $oldTargetId = 0;
                if (!empty($itemData['asset_id'])) {
                    $oldTargetId = (int) $itemData['asset_id'];
                    $oldTargetType = 'asset';
                    $oldTargetName = $pdo->query("SELECT name FROM assets WHERE id = $oldTargetId")->fetchColumn();
                } elseif (!empty($itemData['assigned_user_id'])) {
                    $oldTargetId = (int) $itemData['assigned_user_id'];
                    $oldTargetType = 'user';
                    $oldTargetName = $pdo->query("SELECT fullname FROM users WHERE id = $oldTargetId")->fetchColumn();
                }

                if ($oldTargetName) {
                    $itmNameForLog = $itemData['name'] ?: 'Component';
                    $itmSerial = $itemData['serial_no'] ? " (SN: " . $itemData['serial_no'] . ")" : "";
                    $detLog = "$oldTargetName üzerinden $itmNameForLog$itmSerial söküldü. / Component $itmNameForLog$itmSerial detached from $oldTargetName.";
                    addAssetLog($pdo, $item_id, $current_user_id, 'checkin', $detLog, $oldTargetId, 'component', $oldTargetType);
                }

                // Prevent assigning to same asset (server-side enforcement)
                if ($target_type === 'asset' && $target_id > 0 && intval($itemData['asset_id']) === $target_id) {
                    $_SESSION['hata'] = ($isTr ? 'Bu bileşen zaten seçili cihaza bağlı.' : 'This component is already attached to the selected device.');
                    header("Location: varliklar?view=components");
                    exit;
                }

                // Instance-based tracking: Moving a part should NOT decrease stock.
                // If this part was already assigned, we clear the old one first.
                $pdo->prepare("DELETE FROM asset_component_checkouts WHERE component_id = ?")->execute([$item_id]);
                $pdo->prepare("UPDATE asset_components SET asset_id = NULL, assigned_user_id = NULL WHERE id = ?")->execute([$item_id]);

                $col = ($target_type == 'user') ? 'user_id' : 'asset_id';
                $checkoutTable = "asset_component_checkouts";
                $insSql = "INSERT INTO $checkoutTable (component_id, $col, quantity, notes) VALUES (?, ?, ?, ?)";
                $pdo->prepare($insSql)->execute([$item_id, $target_id, 1, $notes]);

                // Sync legacy field for UI compatibility if assigned to asset
                if ($target_type === 'asset') {
                    $pdo->prepare("UPDATE asset_components SET asset_id = ? WHERE id = ?")->execute([$target_id, $item_id]);
                } elseif ($target_type === 'user') {
                    $pdo->prepare("UPDATE asset_components SET assigned_user_id = ? WHERE id = ?")->execute([$target_id, $item_id]);
                }

                // Localized message
                if (($_POST['is_transfer'] ?? '0') === '1') {
                    $_SESSION['mesaj'] = $isTr ? "Parça başarıyla yeni cihaza taşındı." : "Item successfully transferred to the new device.";
                } else {
                    $_SESSION['mesaj'] = $isTr ? ("$itmName, $targetName " . ($target_type === 'user' ? "personeline" : "cihazına") . " başarıyla zimmetlendi.") : "$itmName successfully assigned to $targetName.";
                }
            }

            $singular = getSingularType($view_submit);
            $nameCol = ($view_submit == 'licenses') ? 'software_name' : 'name';
            $itmDataFinal = $pdo->query("SELECT $nameCol, serial_no FROM $table WHERE id = $item_id")->fetch();
            $itmName = $itmDataFinal[$nameCol] ?? $singular;
            $itmSerialFinal = (!empty($itmDataFinal['serial_no']) && $view_submit === 'components') ? " (SN: " . $itmDataFinal['serial_no'] . ")" : "";


            $isAssetTarget = ($target_type === 'asset' && $target_id > 0);
            if ($view_submit === 'licenses') {
                if ($isAssetTarget) {
                    $trMsg = "Lisans $itmName, cihaz üzerine ($targetName) atandı.";
                    $enMsg = "License $itmName assigned to device: $targetName.";
                } else {
                    $trMsg = "Lisans $itmName, personele ($targetName) atandı.";
                    $enMsg = "License $itmName assigned to user: $targetName.";
                }
            } elseif ($view_submit === 'accessories') {
                if ($isAssetTarget) {
                    $trMsg = "Aksesuar $itmName, cihaz üzerine ($targetName) zimmetlendi ($requested_qty Adet).";
                    $enMsg = "Accessory $itmName assigned to device: $targetName ($requested_qty Qty).";
                } else {
                    $trMsg = "Aksesuar $itmName, personele ($targetName) zimmetlendi ($requested_qty Adet).";
                    $enMsg = "Accessory $itmName assigned to user: $targetName ($requested_qty Qty).";
                }
            } elseif ($view_submit === 'components') {
                if ($isAssetTarget) {
                    $trMsg = "Bileşen $itmName$itmSerialFinal, cihaz üzerine ($targetName) takıldı.";
                    $enMsg = "Component $itmName$itmSerialFinal attached to device: $targetName.";
                } else {
                    $trMsg = "Bileşen $itmName$itmSerialFinal, personele ($targetName) zimmetlendi.";
                    $enMsg = "Component $itmName$itmSerialFinal assigned to user: $targetName.";
                }
            } else {
                if ($isAssetTarget) {
                    $trMsg = "Cihaz $itmName, cihaz üzerine ($targetName) zimmetlendi.";
                    $enMsg = "Device $itmName assigned to device: $targetName.";
                } else {
                    $trMsg = "Cihaz $itmName, personele ($targetName) zimmetlendi.";
                    $enMsg = "Device $itmName assigned to user: $targetName.";
                }
            }
            $finalLogMsg = "$trMsg$noteSuffix / $enMsg$noteSuffix";
            addAssetLog($pdo, $item_id, $current_user_id, 'checkout', $finalLogMsg, $target_id, $singular, $target_type);
        }
        // Send Assignment Notification Email
        if ($target_type === 'user' && !empty($target_id)) {
            $uInfo = $pdo->query("SELECT fullname, mail FROM users WHERE id = $target_id")->fetch();
            if ($uInfo && !empty($uInfo['mail'])) {
                $tableMail = match ($view_submit) {
                    'assets' => 'assets',
                    'licenses' => 'asset_licenses',
                    'accessories' => 'asset_accessories',
                    'consumables' => 'asset_consumables',
                    'components' => 'asset_components',
                    default => 'assets'
                };
                $nameColMail = ($view_submit == 'licenses') ? 'software_name' : 'name';
                $itmNameMail = $pdo->query("SELECT $nameColMail FROM $tableMail WHERE id = $item_id")->fetchColumn() ?: $singular;
                $lang = $_SESSION['lang'] ?? 'tr';
                if ($lang !== 'en') $lang = 'tr';
                sendTemplatedMail($uInfo['mail'], $uInfo['fullname'], 'asset_assigned', [
                    'fullname' => $uInfo['fullname'],
                    'ITEM_NAME' => $itmNameMail,
                    'DATE' => date('d.m.Y H:i'),
                    'ITEM_TYPE' => $view_submit
                ], '', $lang);
            }
        }
        header("Location: varliklar?view=$view_submit");
        exit;
    } elseif ($action == 'checkin' || $action == 'return') {
        $isAjax = isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1';
        $role = $_SESSION['role'] ?? 3;
        if ($role == 2) {
            $isTr_check = ($_SESSION['lang'] ?? 'tr') === 'tr';
            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => $isTr_check ? "İade işlemi için yetkiniz bulunmamaktadır." : "You do not have permission to return this item."]);
                exit;
            } else {
                $_SESSION['hata'] = $isTr_check ? "İade işlemi için yetkiniz bulunmamaktadır." : "You do not have permission to return this item.";
                header("Location: varliklar?view=" . ($_POST['view'] ?? 'assets'));
                exit;
            }
        }
        $returnReason = $_POST['return_reason'] ?? '';
        $returnStatus = $_POST['return_status'] ?? 'hasarsiz';
        $damageNote = $_POST['damage_note'] ?? '';
        $proxyName = $_POST['proxy_name'] ?? '';
        $bypass = isset($_POST['bypass']) && $_POST['bypass'] == '1';
        $checkout_id = intval($_POST['checkout_id'] ?? 0);
        $fromAssetId = intval($_POST['from_asset_id'] ?? 0);
        
        $pSig = $_POST['personnel_signature'] ?? '';
        $aSig = $_POST['admin_signature'] ?? '';
        
        $singular = getSingularType($view_submit);
        
        // Find who the asset is assigned to
        $targetUserId = 0;
        if ($view_submit == 'assets') {
            $astData = $pdo->query("SELECT assigned_user_id FROM assets WHERE id = $item_id")->fetch();
            $targetUserId = $astData['assigned_user_id'] ?? 0;
        } else {
            $checkoutTable = "asset_" . $singular . "_checkouts";
            if ($checkout_id > 0) {
                $targetUserId = (int)$pdo->query("SELECT user_id FROM $checkoutTable WHERE id = $checkout_id")->fetchColumn();
            } else {
                $targetUserId = (int)$pdo->query("SELECT user_id FROM $checkoutTable WHERE {$singular}_id = $item_id ORDER BY id DESC LIMIT 1")->fetchColumn();
            }
        }
        
        $assetIdVal = ($view_submit === 'assets') ? $item_id : null;
        $accessoryIdVal = ($view_submit === 'accessories') ? $item_id : null;
        $componentIdVal = ($view_submit === 'components') ? $item_id : null;
        $licenseIdVal = ($view_submit === 'licenses') ? $item_id : null;

        $notesData = json_encode([
            'return_reason' => $returnReason,
            'return_status' => $returnStatus,
            'damage_note' => $damageNote,
            'proxy_name' => $proxyName,
            'checkout_id' => $checkout_id,
            'from_asset_id' => $fromAssetId
        ]);

        // Handle optional manual file upload during return
        if (isset($_FILES['return_document']) && $_FILES['return_document']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['return_document'];
            $fileName = $file['name'];
            $fileSize = $file['size'];
            $tmpPath = $file['tmp_name'];
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            
            $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'docx', 'xlsx', 'zip'];
            if (in_array(strtolower($ext), $allowed)) {
                $cleanName = time() . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '', $fileName);
                $uploadDir = __DIR__ . '/../../public/uploads/return_docs/';
                if (!file_exists($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }
                $fullPath = $uploadDir . $cleanName;
                $dbPath = 'public/uploads/return_docs/' . $cleanName;
                
                if (move_uploaded_file($tmpPath, $fullPath)) {
                    $stmtAttach = $pdo->prepare("INSERT INTO attachments (entity_type, entity_id, file_name, file_path, file_type, file_size, uploaded_by, created_at, document_type) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'return')");
                    $stmtAttach->execute([$singular, $item_id, $fileName, $dbPath, $file['type'], $fileSize, $current_user_id]);
                }
            }
        }

        if ($bypass) {
            // Admin is bypassing personnel signature (doing both at once) or Direct Return
            $isDirect = isset($_POST['signature_type']) && $_POST['signature_type'] === 'direct';
            if ($isDirect) {
                $pSig = 'Direct Return';
                $aSig = 'Direct Return';
            }
            $stmtIns = $pdo->prepare("INSERT INTO asset_signatures (asset_id, accessory_id, component_id, license_id, user_id, status, signed_at, notes, signature_image, action_type, admin_id, admin_signature_image, admin_signed_at, bypass_user_signature) VALUES (?, ?, ?, ?, ?, 'approved', NOW(), ?, ?, 'checkin', ?, ?, NOW(), 1) ON DUPLICATE KEY UPDATE status='approved', signed_at=NOW(), notes=VALUES(notes), signature_image=VALUES(signature_image), action_type='checkin', admin_id=VALUES(admin_id), admin_signature_image=VALUES(admin_signature_image), admin_signed_at=NOW(), bypass_user_signature=1");
            $stmtIns->execute([$assetIdVal, $accessoryIdVal, $componentIdVal, $licenseIdVal, $targetUserId, $notesData, $pSig, $current_user_id, $aSig]);
            
            // Execute the actual checkin logic since both signatures are provided (or bypassed)
            require_once __DIR__ . '/../includes/asset_helpers.php';
            finalizeAssetCheckin($pdo, $item_id, $view_submit, $current_user_id, $isTr, $checkout_id, $fromAssetId, $returnReason, $returnStatus, $damageNote);
            
            try {
                $attachment_id = generateReturnPDF($pdo, $item_id, $singular, $targetUserId, $returnReason, $returnStatus, $pSig, $aSig, $isTr, $current_user_id, $proxyName, $checkout_id);
            } catch (Exception $e) {
                error_log("Return PDF Generation Failed: " . $e->getMessage());
            }
        } else {
            // Stage 1: Send to personnel for signature — created_by = bu iadeyi başlatan admin
            $stmtIns = $pdo->prepare("INSERT INTO asset_signatures (asset_id, accessory_id, component_id, license_id, user_id, status, action_type, notes, created_by) VALUES (?, ?, ?, ?, ?, 'pending_user', 'checkin', ?, ?) ON DUPLICATE KEY UPDATE status='pending_user', action_type='checkin', notes=VALUES(notes), created_by=VALUES(created_by)");
            $stmtIns->execute([$assetIdVal, $accessoryIdVal, $componentIdVal, $licenseIdVal, $targetUserId, $notesData, $current_user_id]);
        }

        $_SESSION['mesaj'] = $bypass ? __("Checked in successfully.") : ($isTr ? "İade talebi oluşturuldu. Personel imzası bekleniyor." : "Return requested. Waiting for personnel signature.");
        if ($isAjax) {
            ob_clean();
            header('Content-Type: application/json');
            
            // Mail Warning Check
            $mailWarning = '';
            if (!empty($_SESSION['send_warnings'])) {
                $mailWarning = "\n\n⚠️ " . implode("\n⚠️ ", $_SESSION['send_warnings']);
                unset($_SESSION['send_warnings']);
            }
            
            $msg = $_SESSION['mesaj'] . $mailWarning;
            unset($_SESSION['mesaj']);
            
            $response = ['success' => true, 'message' => $msg];
            if (isset($attachment_id) && $attachment_id > 0) {
                $response['download_url'] = 'dashboard?route=view_attachment&id=' . $attachment_id;
                $response['excel_url'] = 'varliklar?checkout_excel_template=' . $attachment_id;
            }
            echo json_encode($response);
            exit;
        }
    } elseif ($action == 'empty_trash') {
        $table = ($view_submit == 'predefined') ? inventoryTableMeta($_POST['type'] ?? 'categories')['table'] : (($view_submit == 'assets') ? 'assets' : (($view_submit == 'licenses') ? 'asset_licenses' : (($view_submit == 'accessories') ? 'asset_accessories' : (($view_submit == 'consumables') ? 'asset_consumables' : (($view_submit == 'components') ? 'asset_components' : "asset_" . $view_submit)))));
        if (tableHasColumn($pdo, $table, 'deleted_at')) {
            $count = $pdo->query("SELECT COUNT(*) FROM $table WHERE deleted_at IS NOT NULL")->fetchColumn();
            if ($count > 0) {
                $pdo->prepare("DELETE FROM $table WHERE deleted_at IS NOT NULL")->execute();
                $_SESSION['mesaj'] = $isTr ? "Çöp kutusu başarıyla temizlendi ($count öğe silindi)." : "Trash emptied successfully ($count items deleted).";
            } else {
                $_SESSION['mesaj'] = $isTr ? "Çöp kutusu zaten boş." : "Trash is already empty.";
            }
        }
    }

    $is_trash = (($_GET['view_deleted'] ?? $_POST['view_deleted'] ?? '0') == '1');
    $type_param = ($_GET['type'] ?? $_POST['type'] ?? '');
    $redirect = getCleanInventoryRedirectUrl($view_submit, $is_trash, $type_param);
    if (isset($_POST['return_to']) && $_POST['return_to'] == 'detail') {
        $redId = intval($_POST['current_asset_id'] ?? 0);
        if ($redId > 0)
            $redirect = "varlik-detay/" . $redId . "?view=" . $view_submit;
    }
    $_SESSION['mesaj'] ??= ($isTr ? "İşlem başarıyla tamamlandı." : "Action completed successfully.");
    header("Location: $redirect");
    exit;
    } catch (Exception $e) {
        $view_submit = $_POST['view'] ?? 'assets';
        $_SESSION['mesaj'] = ($isTr ? "Hata: Bir sorun oluştu: " : "Error: An issue occurred: ") . $e->getMessage();
        $is_trash = (($_GET['view_deleted'] ?? $_POST['view_deleted'] ?? '0') == '1');
        $type_param = ($_GET['type'] ?? $_POST['type'] ?? '');
        $redirect = getCleanInventoryRedirectUrl($view_submit, $is_trash, $type_param);
        if (isset($_POST['return_to']) && $_POST['return_to'] == 'detail') {
            $redId = intval($_POST['current_asset_id'] ?? 0);
            if ($redId > 0)
                $redirect = "varlik-detay/" . $redId . "?view=" . $view_submit;
        }
        header("Location: $redirect");
        exit;
    }
}

function categoryMatches(array $category, string $type): bool
{
    $normalizedType = normalizeInventoryCategoryType($category['type'] ?? '');
    return $normalizedType === $type;
}

// Fetch Timeline for specific asset (AJAX)
if (isset($_GET['timeline_asset_id'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    $tid = intval($_GET['timeline_asset_id'] ?? 0);
    $type = $_GET['timeline_type'] ?? 'asset';
    $logs = $pdo->prepare("SELECT at.*, u.fullname as performer_name 
                           FROM asset_timeline at 
                           LEFT JOIN users u ON at.user_id = u.id 
                           WHERE at.asset_id = ? AND at.item_type = ? 
                           ORDER BY at.created_at DESC");
    $logs->execute([$tid, $type]);
    $results = $logs->fetchAll(PDO::FETCH_ASSOC);

    // Privacy protection for regular personnel (Role 2)
    if ((int)$current_user_role === 2) {
        $cleanResults = [];
        foreach ($results as $row) {
            if ((int)$row['user_id'] !== (int)$current_user_id) {
                $row['performer_name'] = $isTr ? 'Sistem / Yetkili' : 'System / Authorized';
            }
            if (!empty($row['event_description'])) {
                $row['event_description'] = preg_replace('/Devir:\s*[^\s->]+\s*->\s*[^\s->]+/i', 'Devir işlemi yapıldı', $row['event_description']);
            }
            $cleanResults[] = $row;
        }
        $results = $cleanResults;
    }

    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    exit;
}

// Fetch Supplier Summary (AJAX)
if (isset($_GET['supplier_summary_id'])) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { @ob_clean(); break; } }
    header('Content-Type: application/json; charset=utf-8');
    $sid = intval($_GET['supplier_summary_id']);

    $supplier = $pdo->query("SELECT * FROM asset_suppliers WHERE id = $sid")->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) {
        echo json_encode(['error' => 'Supplier not found']);
        exit;
    }

    // Assets
    $assets = $pdo->query("SELECT a.id, a.name, a.asset_tag, a.serial_no, m.name as model_name, sl.name as status_name, sl.color as status_color 
                           FROM assets a 
                           LEFT JOIN asset_models m ON a.model_id = m.id 
                           LEFT JOIN asset_status_labels sl ON a.status_id = sl.id 
                           WHERE a.supplier_id = $sid AND a.deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);

    // Licenses
    $licenses = $pdo->query("SELECT id, software_name as name, license_key, seats as quantity FROM asset_licenses WHERE supplier_id = $sid AND deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);

    // Consumables
    $consumables = $pdo->query("SELECT id, name, remaining_qty as quantity FROM asset_consumables WHERE supplier_id = $sid AND deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);

    // Accessories
    $accessories = $pdo->query("SELECT id, name, total_qty as quantity FROM asset_accessories WHERE supplier_id = $sid AND deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);

    // Components
    $components = $pdo->query("SELECT id, name, total_qty as quantity FROM asset_components WHERE supplier_id = $sid AND deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'supplier' => $supplier,
        'assets' => $assets,
        'licenses' => $licenses,
        'consumables' => $consumables,
        'accessories' => $accessories,
        'components' => $components
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Custom field fetch handler moved to top of file to avoid routing/auth complexities.

// Data fetching based on view
$show_deleted = isset($_GET['view_deleted']) && $_GET['view_deleted'] == 1;

// Row-Level Permission Logic
$can_view_all_varliklar = hasPermission('varliklar_view_all') || in_array((int)$current_user_role, [1, 3]);
$can_view_own_varliklar = !$can_view_all_varliklar && (hasPermission('varliklar_view_own') || (int)$current_user_role === 2);
$can_edit_varliklar = ((int)$current_user_role !== 2) && (hasPermission('varliklar_edit') || in_array((int)$current_user_role, [1, 3]));

if (!$can_view_all_varliklar && !$can_view_own_varliklar && hasPermission('varliklar') && (int)$current_user_role !== 2) {
    $can_view_all_varliklar = true;
    $can_edit_varliklar = true;
}



if ($view == 'assets') {
    $where = "WHERE " . deletedWhereClause($pdo, 'assets', $show_deleted, 'a');
    if ($can_view_own_varliklar) {
        $where .= " AND a.assigned_user_id = " . (int)$current_user_id . " ";
    }
    $params = [];
    if (isset($_GET['status'])) {
        $st = $_GET['status'];
        if ($st == 'deployed') {
            $where .= " AND (a.assigned_user_id IS NOT NULL OR a.asset_id IS NOT NULL)";
        } elseif ($st == 'ready') {
            $where .= " AND a.assigned_user_id IS NULL AND a.asset_id IS NULL AND sl.type = 'deployable'";
        } elseif ($st == 'faulty') {
            $where .= " AND sl.type IN ('pending', 'undeployable') AND sl.id != 6";
        } elseif ($st == 'scrapped') {
            $where .= " AND sl.id = 6";
        }
    }

    $user_id_filter = intval($_GET['assigned_user_id'] ?? 0);
    if ($user_id_filter > 0) {
        $where .= " AND a.assigned_user_id = ? ";
        $params[] = $user_id_filter;
    }

    if (isset($_GET['category_id'])) {
        $category_id_filter = $_GET['category_id'];
        if ($category_id_filter === '0') {
            $where .= " AND (a.category_id IS NULL OR a.category_id = 0) ";
        } elseif (intval($category_id_filter) > 0) {
            $where .= " AND a.category_id = ? ";
            $params[] = intval($category_id_filter);
        }
    }

    $company_id_filter = intval($_GET['company_id'] ?? 0);
    if ($company_id_filter > 0) {
        $where .= " AND a.company_id = ? ";
        $params[] = $company_id_filter;
    }

    $supplier_id_filter = intval($_GET['supplier_id'] ?? 0);
    if ($supplier_id_filter > 0) {
        $where .= " AND a.supplier_id = ? ";
        $params[] = $supplier_id_filter;
    }

    $dept_id_filter = intval($_GET['department_id'] ?? 0);
    if ($dept_id_filter > 0) {
        $where .= " AND a.department_id = ? ";
        $params[] = $dept_id_filter;
    }

    $model_id_filter = intval($_GET['model_id'] ?? 0);
    if ($model_id_filter > 0) {
        $where .= " AND a.model_id = ? ";
        $params[] = $model_id_filter;
    }

    $manufacturer_id_filter = intval($_GET['manufacturer_id'] ?? 0);
    if ($manufacturer_id_filter > 0) {
        $where .= " AND a.manufacturer_id = ? ";
        $params[] = $manufacturer_id_filter;
    }

    $q_search = trim($_GET['search'] ?? '');
    if (!empty($q_search)) {
        $where .= " AND (a.name LIKE ? OR a.asset_tag LIKE ? OR a.serial_no LIKE ? OR a.ip_address LIKE ? OR a.ip_secondary LIKE ? OR a.mac_address LIKE ? OR u.fullname LIKE ? OR a.notes LIKE ? OR a.specs LIKE ? OR c.name LIKE ? OR dept.bolum_adi LIKE ? OR comp.name LIKE ? OR supp.name LIKE ? OR manu.name LIKE ? OR m.name LIKE ? OR sl.name LIKE ? OR a.purchase_date LIKE ? OR a.order_number LIKE ?)";
        $searchPattern = "%$q_search%";
        for ($i = 0; $i < 18; $i++) {
            $params[] = $searchPattern;
        }
    }

    $stmt = $pdo->prepare("SELECT a.*, u.fullname as assigned_user, u.username as assigned_username, m.name as model_name, m.image as model_image, c.name as category_name, c.name_en as category_name_en, c.type as type,
                                  sl.name as status_label, sl.color as status_color, sl.type as status_type,
                                  COALESCE(NULLIF(a.image, ''), NULLIF(m.image, '')) as display_image,
                                  comp.name as company_name, dept.bolum_adi as dept_name,
                                  supp.name as supplier_name, manu.name as manufacturer_name,
                                  pa.name as assigned_asset_name,
                                  SUBSTR(MD5(CONCAT(COALESCE(NULLIF(a.asset_tag, ''), CAST(a.id AS CHAR)), '$SALT')), 1, 16) as public_token,
                                  (SELECT COUNT(*) FROM asset_accessories WHERE asset_id = a.id AND deleted_at IS NULL) as linked_accessories_count,
                                  (SELECT COUNT(*) FROM asset_consumable_checkouts WHERE asset_id = a.id) as linked_consumables_count,
                                  (SELECT COUNT(*) FROM asset_licenses WHERE asset_id = a.id AND deleted_at IS NULL) as linked_licenses_count,
                                  (SELECT GROUP_CONCAT(value SEPARATOR ' ') FROM inventory_asset_field_values WHERE asset_id = a.id) as custom_fields_text,
                                  (SELECT COUNT(*) FROM assets WHERE asset_id = a.id AND deleted_at IS NULL) as linked_assets_count
                           FROM assets a 
                           LEFT JOIN users u ON a.assigned_user_id = u.id 
                           LEFT JOIN asset_models m ON a.model_id = m.id
                           LEFT JOIN asset_categories c ON a.category_id = c.id
                           LEFT JOIN asset_status_labels sl ON a.status_id = sl.id
                           LEFT JOIN asset_companies comp ON a.company_id = comp.id
                           LEFT JOIN bolumler dept ON a.department_id = dept.id
                           LEFT JOIN asset_suppliers supp ON a.supplier_id = supp.id
                           LEFT JOIN asset_manufacturers manu ON a.manufacturer_id = manu.id
                           LEFT JOIN assets pa ON a.asset_id = pa.id
                           $where ORDER BY a.id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total Count for Pagination (Optimized)
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM assets a 
                                LEFT JOIN users u ON a.assigned_user_id = u.id 
                                LEFT JOIN asset_models m ON a.model_id = m.id
                                LEFT JOIN asset_categories c ON a.category_id = c.id
                                LEFT JOIN asset_status_labels sl ON a.status_id = sl.id 
                                LEFT JOIN asset_companies comp ON a.company_id = comp.id
                                LEFT JOIN bolumler dept ON a.department_id = dept.id
                                LEFT JOIN asset_suppliers supp ON a.supplier_id = supp.id
                                LEFT JOIN asset_manufacturers manu ON a.manufacturer_id = manu.id
                                $where");
    $total_stmt->execute($params);
    $total_records = $total_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);
} elseif ($view == 'licenses') {
    $licenseDeletedWhere = deletedWhereClause($pdo, 'asset_licenses', $show_deleted, 'l');
    $where_l = " WHERE $licenseDeletedWhere";
    if ($can_view_own_varliklar) {
        $where_l .= " AND (l.assigned_user_id = " . (int)$current_user_id . " OR l.id IN (SELECT alc.license_id FROM asset_license_checkouts alc LEFT JOIN assets a ON alc.asset_id = a.id WHERE (alc.user_id = " . (int)$current_user_id . " OR alc.assigned_user_id = " . (int)$current_user_id . " OR a.assigned_user_id = " . (int)$current_user_id . ") AND (alc.transaction_type IS NULL OR alc.transaction_type != 'checkin'))) ";
    }
    $params_l = [];
    $user_id_f = intval($_GET['assigned_user_id'] ?? 0);
    if ($user_id_f > 0) {
        $where_l .= " AND l.assigned_user_id = ? ";
        $params_l[] = $user_id_f;
    }
    if (isset($_GET['category_id'])) {
        $category_id_val = $_GET['category_id'];
        if ($category_id_val === '0') {
            $where_l .= " AND (l.category_id IS NULL OR l.category_id = 0) ";
        } elseif (intval($category_id_val) > 0) {
            $where_l .= " AND l.category_id = ? ";
            $params_l[] = intval($category_id_val);
        }
    }
    if (intval($_GET['supplier_id'] ?? 0) > 0) {
        $where_l .= " AND l.supplier_id = ? ";
        $params_l[] = intval($_GET['supplier_id']);
    }
    if (intval($_GET['manufacturer_id'] ?? 0) > 0) {
        $where_l .= " AND l.manufacturer_id = ? ";
        $params_l[] = intval($_GET['manufacturer_id']);
    }
    if (intval($_GET['company_id'] ?? 0) > 0) {
        $where_l .= " AND l.company_id = ? ";
        $params_l[] = intval($_GET['company_id']);
    }
    if (intval($_GET['department_id'] ?? 0) > 0) {
        $where_l .= " AND l.department_id = ? ";
        $params_l[] = intval($_GET['department_id']);
    }
    $q_search = trim($_GET['search'] ?? '');
    if (!empty($q_search)) {
        $where_l .= " AND (l.software_name LIKE ? OR l.license_key LIKE ? OR l.license_email LIKE ? OR l.license_name LIKE ? OR l.order_no LIKE ? OR comp.name LIKE ? OR l.notes LIKE ?)";
        $params_l[] = "%$q_search%";
        $params_l[] = "%$q_search%";
        $params_l[] = "%$q_search%";
        $params_l[] = "%$q_search%";
        $params_l[] = "%$q_search%";
        $params_l[] = "%$q_search%";
        $params_l[] = "%$q_search%";
    }
        $stmt = $pdo->prepare("SELECT l.*, l.software_name as name, l.seats as total_qty, c.name as category_name, c.name_en as category_name_en, c.type as type, comp.name as company_name, s.name as supplier_name, m.name as manufacturer_name, dept.bolum_adi as dept_name,
                                   SUBSTR(MD5(CONCAT(l.id, '$SALT')), 1, 16) as public_token,
                                   (SELECT GROUP_CONCAT(DISTINCT COALESCE(u.fullname, ast.name) SEPARATOR ', ') 
                                    FROM asset_license_checkouts alc 
                                    LEFT JOIN users u ON alc.user_id = u.id 
                                    LEFT JOIN assets ast ON alc.asset_id = ast.id 
                                    WHERE alc.license_id = l.id AND (alc.transaction_type IS NULL OR alc.transaction_type != 'checkin')) as assigned_targets
                          FROM asset_licenses l 
                          LEFT JOIN asset_categories c ON l.category_id = c.id 
                          LEFT JOIN asset_companies comp ON l.company_id = comp.id
                          LEFT JOIN asset_suppliers s ON l.supplier_id = s.id
                          LEFT JOIN asset_manufacturers m ON l.manufacturer_id = m.id
                          LEFT JOIN bolumler dept ON l.department_id = dept.id
                          $where_l ORDER BY l.id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params_l);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total Count
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM asset_licenses l $where_l");
    $total_stmt->execute($params_l);
    $total_records = $total_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);
} elseif ($view == 'accessories') {
    $accessoryDeletedWhere = deletedWhereClause($pdo, 'asset_accessories', $show_deleted, 'a');
    $where_a = " WHERE $accessoryDeletedWhere";
    if ($can_view_own_varliklar) {
        $where_a .= " AND (a.assigned_user_id = " . (int)$current_user_id . " OR a.id IN (SELECT aac.accessory_id FROM asset_accessory_checkouts aac LEFT JOIN assets ast ON aac.asset_id = ast.id WHERE aac.user_id = " . (int)$current_user_id . " OR aac.assigned_user_id = " . (int)$current_user_id . " OR ast.assigned_user_id = " . (int)$current_user_id . ")) ";
    }
    $params_a = [];
    if (isset($_GET['category_id'])) {
        $cat_f = $_GET['category_id'];
        if ($cat_f === '0') {
            $where_a .= " AND (a.category_id IS NULL OR a.category_id = 0) ";
        } elseif (intval($cat_f) > 0) {
            $where_a .= " AND a.category_id = ? ";
            $params_a[] = intval($cat_f);
        }
    }
    if (intval($_GET['supplier_id'] ?? 0) > 0) {
        $where_a .= " AND a.supplier_id = ? ";
        $params_a[] = intval($_GET['supplier_id']);
    }
    if (intval($_GET['manufacturer_id'] ?? 0) > 0) {
        $where_a .= " AND a.manufacturer_id = ? ";
        $params_a[] = intval($_GET['manufacturer_id']);
    }
    if (intval($_GET['company_id'] ?? 0) > 0) {
        $where_a .= " AND a.company_id = ? ";
        $params_a[] = intval($_GET['company_id']);
    }
    if (intval($_GET['department_id'] ?? 0) > 0) {
        $where_a .= " AND a.department_id = ? ";
        $params_a[] = intval($_GET['department_id']);
    }
    $q_search = trim($_GET['search'] ?? '');
    if (!empty($q_search)) {
        $where_a .= " AND (a.name LIKE ? OR a.model_no LIKE ? OR a.serial_no LIKE ? OR a.order_no LIKE ? OR m.name LIKE ? OR c.name LIKE ? OR a.notes LIKE ?)";
        $params_a[] = "%$q_search%";
        $params_a[] = "%$q_search%";
        $params_a[] = "%$q_search%";
        $params_a[] = "%$q_search%";
        $params_a[] = "%$q_search%";
        $params_a[] = "%$q_search%";
        $params_a[] = "%$q_search%";
    }
    $stmt = $pdo->prepare("SELECT a.*, u.fullname as assigned_user, ac.name as category_name, ac.image as category_image, ac.name_en as category_name_en, ac.type as category_type, c.name as company_name, s.name as supplier_name, m.name as manufacturer_name, dept.bolum_adi as dept_name,
                                   SUBSTR(MD5(CONCAT(a.id, '$SALT')), 1, 16) as public_token
                         FROM asset_accessories a 
                         LEFT JOIN users u ON a.assigned_user_id = u.id 
                         LEFT JOIN asset_categories ac ON a.category_id = ac.id 
                         LEFT JOIN asset_companies c ON a.company_id = c.id
                         LEFT JOIN asset_suppliers s ON a.supplier_id = s.id
                         LEFT JOIN asset_manufacturers m ON a.manufacturer_id = m.id
                         LEFT JOIN bolumler dept ON a.department_id = dept.id
                         $where_a ORDER BY a.id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params_a);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total Count
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM asset_accessories a $where_a");
    $total_stmt->execute($params_a);
    $total_records = $total_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);
} elseif ($view == 'consumables') {
    $consumableDeletedWhere = deletedWhereClause($pdo, 'asset_consumables', $show_deleted, 'c');
    $where_c = " WHERE $consumableDeletedWhere";
    if ($can_view_own_varliklar) {
        $where_c .= " AND (c.assigned_user_id = " . (int)$current_user_id . " OR c.id IN (SELECT acc.consumable_id FROM asset_consumable_checkouts acc LEFT JOIN assets ast ON acc.asset_id = ast.id WHERE acc.user_id = " . (int)$current_user_id . " OR acc.assigned_user_id = " . (int)$current_user_id . " OR ast.assigned_user_id = " . (int)$current_user_id . ")) ";
    }
    $params_c = [];
    if (isset($_GET['category_id'])) {
        $cat_f = $_GET['category_id'];
        if ($cat_f === '0') {
            $where_c .= " AND (c.category_id IS NULL OR c.category_id = 0) ";
        } elseif (intval($cat_f) > 0) {
            $where_c .= " AND c.category_id = ? ";
            $params_c[] = intval($cat_f);
        }
    }
    if (intval($_GET['supplier_id'] ?? 0) > 0) {
        $where_c .= " AND c.supplier_id = ? ";
        $params_c[] = intval($_GET['supplier_id']);
    }
    if (intval($_GET['manufacturer_id'] ?? 0) > 0) {
        $where_c .= " AND c.manufacturer_id = ? ";
        $params_c[] = intval($_GET['manufacturer_id']);
    }
    if (intval($_GET['company_id'] ?? 0) > 0) {
        $where_c .= " AND c.company_id = ? ";
        $params_c[] = intval($_GET['company_id']);
    }
    if (intval($_GET['department_id'] ?? 0) > 0) {
        $where_c .= " AND c.department_id = ? ";
        $params_c[] = intval($_GET['department_id']);
    }
    $q_search = trim($_GET['search'] ?? '');
    if (!empty($q_search)) {
        $where_c .= " AND (c.name LIKE ? OR c.model_no LIKE ? OR c.serial_no LIKE ? OR m.name LIKE ? OR comp.name LIKE ? OR c.notes LIKE ? OR c.order_no LIKE ?)";
        $params_c[] = "%$q_search%";
        $params_c[] = "%$q_search%";
        $params_c[] = "%$q_search%";
        $params_c[] = "%$q_search%";
        $params_c[] = "%$q_search%";
        $params_c[] = "%$q_search%";
        $params_c[] = "%$q_search%";
    }
    $stmt = $pdo->prepare("SELECT c.*, a.name as assigned_asset, ac.name as category_name, ac.image as category_image, ac.name_en as category_name_en, ac.type as category_type, m.name as manufacturer_name, comp.name as company_name, s.name as supplier_name, d.bolum_adi as dept_name,
                                   SUBSTR(MD5(CONCAT(c.id, '$SALT')), 1, 16) as public_token
                         FROM asset_consumables c 
                         LEFT JOIN assets a ON c.asset_id = a.id 
                         LEFT JOIN asset_categories ac ON c.category_id = ac.id 
                         LEFT JOIN asset_manufacturers m ON c.manufacturer_id = m.id
                         LEFT JOIN asset_companies comp ON c.company_id = comp.id
                         LEFT JOIN asset_suppliers s ON c.supplier_id = s.id
                         LEFT JOIN bolumler d ON c.department_id = d.id
                         $where_c ORDER BY c.id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params_c);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // Total Count
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM asset_consumables c 
                                LEFT JOIN asset_manufacturers m ON c.manufacturer_id = m.id
                                LEFT JOIN asset_companies comp ON c.company_id = comp.id
                                $where_c");
    $total_stmt->execute($params_c);
    $total_records = $total_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);
} elseif ($view == 'components') {
    $componentDeletedWhere = deletedWhereClause($pdo, 'asset_components', $show_deleted, 'c');
    $where_co = " WHERE $componentDeletedWhere";
    if ($can_view_own_varliklar) {
        $where_co .= " AND (c.assigned_user_id = " . (int)$current_user_id . " OR c.asset_id IN (SELECT id FROM assets WHERE assigned_user_id = " . (int)$current_user_id . ") OR c.id IN (SELECT acc.component_id FROM asset_component_checkouts acc LEFT JOIN assets ast ON acc.asset_id = ast.id WHERE acc.user_id = " . (int)$current_user_id . " OR acc.assigned_user_id = " . (int)$current_user_id . " OR acc.assigned_asset_id IN (SELECT id FROM assets WHERE assigned_user_id = " . (int)$current_user_id . ") OR ast.assigned_user_id = " . (int)$current_user_id . ")) ";
    }
    $params_co = [];
    if (isset($_GET['category_id'])) {
        $cat_f = $_GET['category_id'];
        if ($cat_f === '0') {
            $where_co .= " AND (c.category_id IS NULL OR c.category_id = 0) ";
        } elseif (intval($cat_f) > 0) {
            $where_co .= " AND c.category_id = ? ";
            $params_co[] = intval($cat_f);
        }
    }
    if (intval($_GET['supplier_id'] ?? 0) > 0) {
        $where_co .= " AND c.supplier_id = ? ";
        $params_co[] = intval($_GET['supplier_id']);
    }
    if (intval($_GET['manufacturer_id'] ?? 0) > 0) {
        $where_co .= " AND c.manufacturer_id = ? ";
        $params_co[] = intval($_GET['manufacturer_id']);
    }
    if (intval($_GET['company_id'] ?? 0) > 0) {
        $where_co .= " AND c.company_id = ? ";
        $params_co[] = intval($_GET['company_id']);
    }
    if (intval($_GET['department_id'] ?? 0) > 0) {
        $where_co .= " AND c.department_id = ? ";
        $params_co[] = intval($_GET['department_id']);
    }
    $q_search = trim($_GET['search'] ?? '');
    if (!empty($q_search)) {
        $where_co .= " AND (c.name LIKE ? OR c.serial_no LIKE ? OR a.name LIKE ? OR comp.name LIKE ? OR c.notes LIKE ? OR c.order_no LIKE ?)";
        $params_co[] = "%$q_search%";
        $params_co[] = "%$q_search%";
        $params_co[] = "%$q_search%";
        $params_co[] = "%$q_search%";
        $params_co[] = "%$q_search%";
        $params_co[] = "%$q_search%";
    }
    // Grouping by Name and Category (and Company) to unify batches for cleaner stock tracking
    $stmt = $pdo->prepare("SELECT c.name, c.category_id, c.company_id,
                                   MAX(c.manufacturer_id) as manufacturer_id, MAX(c.supplier_id) as supplier_id, 
                                   MAX(c.purchase_currency) as purchase_currency, MAX(c.purchase_cost) as purchase_cost, MAX(c.purchase_date) as purchase_date, MAX(c.min_qty) as min_qty,
                                   MAX(c.order_no) as order_no, MAX(c.serial_no) as serial_no, MAX(c.department_id) as department_id, MAX(c.notes) as notes, MAX(c.image) as image,
                                   COALESCE(SUM(c.total_qty), COUNT(DISTINCT c.id)) as total_qty, 
                                   COALESCE(SUM(CASE WHEN c.asset_id IS NOT NULL OR c.assigned_user_id IS NOT NULL THEN 1 ELSE 0 END), COUNT(DISTINCT acc.component_id)) as assigned_qty,
                                   MIN(c.id) as id,
                                   ac.name as category_name, ac.image as category_image, ac.name_en as category_name_en, ac.type as category_type, 
                                   MAX(comp.name) as company_name, MAX(m.name) as manufacturer_name, MAX(s.name) as supplier_name, MAX(dept.bolum_adi) as dept_name
                            FROM asset_components c 
                            LEFT JOIN asset_categories ac ON c.category_id = ac.id 
                            LEFT JOIN asset_companies comp ON c.company_id = comp.id
                            LEFT JOIN asset_manufacturers m ON c.manufacturer_id = m.id
                            LEFT JOIN asset_suppliers s ON c.supplier_id = s.id
                            LEFT JOIN bolumler dept ON c.department_id = dept.id
                            LEFT JOIN asset_component_checkouts acc ON c.id = acc.component_id
                            $where_co 
                            GROUP BY c.name, c.category_id, c.company_id
                            ORDER BY MIN(c.id) DESC LIMIT $limit OFFSET $offset");

    $stmt->execute($params_co);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total Count for Pagination (Grouped)
    $total_stmt = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT_WS('-', c.name, COALESCE(c.category_id, 0), COALESCE(c.company_id, 0))) FROM asset_components c $where_co");
    $total_stmt->execute($params_co);
    $total_records = $total_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);
} elseif ($view == 'predefined') {
    $type = $_GET['type'] ?? 'categories';
    $meta = inventoryTableMeta($type);
    $p_table = $meta['table'];
    $nameColumn = $meta['name_column'];
    $notesColumn = $meta['notes_column'];
    $predefinedDeletedWhere = deletedWhereClause($pdo, $p_table, $show_deleted);

    if ($type === 'categories') {
        $stmt = $pdo->query("SELECT c.*, c.name_en, (SELECT COUNT(DISTINCT f.id) FROM inventory_custom_fields f LEFT JOIN inventory_field_group_links l ON f.field_group = l.field_group WHERE f.category_id = c.id OR l.category_id = c.id) as fields_count 
                             FROM asset_categories c WHERE $predefinedDeletedWhere ORDER BY type, parent_id, name ASC");
    } elseif ($type === 'custom_fields') {
        $catFilter = intval($_GET['cat_id'] ?? 0);
        if ($catFilter > 0) {
            $stmt = $pdo->prepare("SELECT DISTINCT f.*, f.field_label AS name, c.name as category_name 
                                 FROM inventory_custom_fields f 
                                 LEFT JOIN asset_categories c ON f.category_id = c.id
                                 LEFT JOIN inventory_field_group_links l ON f.field_group = l.field_group
                                 WHERE (f.category_id = ? OR l.category_id = ?) 
                                 ORDER BY f.field_group, f.sort_order ASC");
            $stmt->execute([$catFilter, $catFilter]);
        } else {
            $stmt = $pdo->query("SELECT f.*, f.field_label AS name, c.name as category_name 
                                 FROM inventory_custom_fields f 
                                 LEFT JOIN asset_categories c ON f.category_id = c.id
                                 ORDER BY f.field_group, f.sort_order ASC");
        }
    } else {
        $notesSelect = ($notesColumn !== null && tableHasColumn($pdo, $p_table, $notesColumn)) ? ", $notesColumn AS notes" : ", NULL AS notes";
        $stmt = $pdo->query("SELECT *, $nameColumn AS name $notesSelect FROM $p_table WHERE $predefinedDeletedWhere ORDER BY id DESC LIMIT $limit OFFSET $offset");
    }
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_records = count($items);
    $total_pages = ceil($total_records / $limit);
} elseif ($view == 'signatures') {
    try {
        $isAdminQuery = in_array($current_user_role ?? 3, [1, 3]);
        
        if ($isAdminQuery) {
            // Admin:
            // - Tüm pending_user kayıtları (personel imzası bekleniyor — bilgi amaçlı)
            // - Sadece KENDİNİN başlattığı pending_admin kayıtları (imzalaması gereken)
            // - Kendi üzerine zimmet olan pending_user (hem admin hem personel durumu)
            $whereCond = "(s.status = 'pending_user' OR s.status = 'pending_admin')";
        } else {
            // Personel: kendi pending_user VE pending_admin kayıtlarını görsün (bilgi amaçlı)
            $whereCond = "(s.status = 'pending_user' AND s.user_id = " . (int)$current_user_id . ") OR (s.status = 'pending_admin' AND s.user_id = " . (int)$current_user_id . ")";
        }
        
        $total_records_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM asset_signatures s
            WHERE $whereCond
        ");
        // Parametreleri query içine gömdüğümüz için doğrudan execute edebiliriz
        $total_records_stmt->execute();
        $total_records = $total_records_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);
        
        $stmt = $pdo->prepare("
            SELECT 
                s.id as signature_row_id,
                COALESCE(s.status, 'pending') as status,
                s.action_type,
                s.signed_at,
                s.created_at,
                s.admin_signed_at,
                CASE 
                    WHEN s.asset_id IS NOT NULL THEN 'asset'
                    WHEN s.accessory_id IS NOT NULL THEN 'accessory'
                    WHEN s.component_id IS NOT NULL THEN 'component'
                    WHEN s.license_id IS NOT NULL THEN 'license'
                    ELSE 'unknown'
                END as item_type,
                CASE 
                    WHEN s.asset_id IS NOT NULL THEN s.asset_id
                    WHEN s.accessory_id IS NOT NULL THEN s.accessory_id
                    WHEN s.component_id IS NOT NULL THEN s.component_id
                    WHEN s.license_id IS NOT NULL THEN s.license_id
                    ELSE NULL
                END as asset_id,
                CASE 
                    WHEN s.asset_id IS NOT NULL THEN a.name
                    WHEN s.accessory_id IS NOT NULL THEN acc.name
                    WHEN s.component_id IS NOT NULL THEN comp.name
                    WHEN s.license_id IS NOT NULL THEN lic.software_name
                    ELSE 'Bilinmeyen'
                END as asset_name,
                CASE 
                    WHEN s.asset_id IS NOT NULL THEN a.asset_tag
                    WHEN s.accessory_id IS NOT NULL THEN acc.serial_no
                    WHEN s.component_id IS NOT NULL THEN comp.serial_no
                    WHEN s.license_id IS NOT NULL THEN lic.license_key
                    ELSE '-'
                END as asset_tag,
                CASE 
                    WHEN s.asset_id IS NOT NULL THEN am.name
                    ELSE '-'
                END as model_name,
                CASE 
                    WHEN s.asset_id IS NOT NULL THEN ac.name
                    WHEN s.accessory_id IS NOT NULL THEN acc_cat.name
                    WHEN s.component_id IS NOT NULL THEN comp_cat.name
                    WHEN s.license_id IS NOT NULL THEN lic_cat.name
                    ELSE '-'
                END as category_name,
                (
                    SELECT MAX(att2.id)
                    FROM attachments att2
                    WHERE att2.entity_id = (
                        CASE 
                            WHEN s.asset_id IS NOT NULL THEN s.asset_id
                            WHEN s.accessory_id IS NOT NULL THEN s.accessory_id
                            WHEN s.component_id IS NOT NULL THEN s.component_id
                            WHEN s.license_id IS NOT NULL THEN s.license_id
                            ELSE 0
                        END
                    )
                    AND att2.entity_type = (
                        CASE 
                            WHEN s.asset_id IS NOT NULL THEN 'asset'
                            WHEN s.accessory_id IS NOT NULL THEN 'accessory'
                            WHEN s.component_id IS NOT NULL THEN 'component'
                            WHEN s.license_id IS NOT NULL THEN 'license'
                            ELSE 'unknown'
                        END
                    )
                    AND att2.file_path LIKE '%signatures%'
                ) as attachment_id,
                s.user_id as personnel_user_id,
                s.created_by,
                u.fullname as personnel_name,
                u.profil_fotosu as personnel_avatar,
                cb.fullname as created_by_name
            FROM asset_signatures s
            LEFT JOIN assets a ON s.asset_id = a.id
            LEFT JOIN asset_models am ON a.model_id = am.id
            LEFT JOIN asset_categories ac ON a.category_id = ac.id
            LEFT JOIN asset_accessories acc ON s.accessory_id = acc.id
            LEFT JOIN asset_categories acc_cat ON acc.category_id = acc_cat.id
            LEFT JOIN asset_components comp ON s.component_id = comp.id
            LEFT JOIN asset_categories comp_cat ON comp.category_id = comp_cat.id
            LEFT JOIN asset_licenses lic ON s.license_id = lic.id
            LEFT JOIN asset_categories lic_cat ON lic.category_id = lic_cat.id
            LEFT JOIN users u ON s.user_id = u.id
            LEFT JOIN users cb ON s.created_by = cb.id
            WHERE $whereCond
            ORDER BY s.id DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
        ");
        // Parametreler $whereCond içinde gömülü olduğu için boş execute
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Auto-sync missing attachment files on disk
        foreach ($items as $k => $item) {
            if (!empty($item['attachment_id'])) {
                $stmtAtchCheck = $pdo->prepare("SELECT file_path FROM attachments WHERE id = ?");
                $stmtAtchCheck->execute([$item['attachment_id']]);
                $atchCheck = $stmtAtchCheck->fetch(PDO::FETCH_ASSOC);
                if ($atchCheck) {
                    $fullPath = __DIR__ . '/../../' . $atchCheck['file_path'];
                    if (!is_file($fullPath)) {
                        // Delete from database because the file was deleted from disk
                        $pdo->prepare("DELETE FROM attachments WHERE id = ?")->execute([$item['attachment_id']]);
                        $items[$k]['attachment_id'] = null;
                    }
                } else {
                    $items[$k]['attachment_id'] = null;
                }
            }
        }
    } catch (Exception $e) {
        die("SIGNATURES QUERY ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    }
} elseif ($view == 'people') {
    $where_p = " WHERE username != 'customer_gateway' AND mail != 'system_customer_gateway@eaprimus.local' ";
    $params_p = [];
    $role_f = intval($_GET['role'] ?? 0);
    if ($role_f > 0) {
        $where_p .= " AND role = ? ";
        $params_p[] = $role_f;
    }
    $status_f = $_GET['status'] ?? '';
    if ($status_f === 'active')
        $where_p .= " AND status = 1 ";
    elseif ($status_f === 'passive')
        $where_p .= " AND status = 0 ";

    $q_search = trim($_GET['search'] ?? '');
    if (!empty($q_search)) {
        $where_p .= " AND (fullname LIKE ? OR username LIKE ? OR email LIKE ?)";
        $params_p[] = "%$q_search%";
        $params_p[] = "%$q_search%";
        $params_p[] = "%$q_search%";
    }

    $stmt = $pdo->prepare("SELECT * FROM users $where_p ORDER BY fullname ASC LIMIT $limit OFFSET $offset");
    $stmt->execute($params_p);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total Count
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM users $where_p");
    $total_stmt->execute($params_p);
    $total_records = $total_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);
}

$allAssetsList = $pdo->query("SELECT id, name, asset_tag FROM assets WHERE " . deletedWhereClause($pdo, 'assets', false) . " ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$existingTypes = $pdo->query("SELECT DISTINCT type FROM assets WHERE type != '' ORDER BY type ASC")->fetchAll(PDO::FETCH_COLUMN);

$stmtU = $pdo->query("SELECT id, fullname FROM users WHERE status = 1 ORDER BY fullname ASC");
$usersList = $stmtU->fetchAll(PDO::FETCH_ASSOC);

$companies = $pdo->query("SELECT id, name FROM asset_companies WHERE " . deletedWhereClause($pdo, 'asset_companies', false) . " ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$manufacturers = $pdo->query("SELECT id, name FROM asset_manufacturers WHERE " . deletedWhereClause($pdo, 'asset_manufacturers', false) . " ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, name FROM asset_suppliers WHERE " . deletedWhereClause($pdo, 'asset_suppliers', false) . " ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, name, type, parent_id, name_en FROM asset_categories WHERE " . deletedWhereClause($pdo, 'asset_categories', false) . " ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Map views to category types for filtering
$viewToTypeMap = [
    'assets' => 'asset',
    'licenses' => 'license',
    'accessories' => 'accessory',
    'consumables' => 'consumable',
    'components' => 'component'
];

$targetCategoryType = $viewToTypeMap[$view] ?? 'asset';
$filteredCategories = [];

foreach ($categories as &$category) {
    $category['normalized_type'] = normalizeInventoryCategoryType($category['type'] ?? '');
    if ($category['normalized_type'] === $targetCategoryType) {
        $filteredCategories[] = $category;
    }
}
// For general usage/modals, use the full list but highlight the relevant ones if needed.
// However, the user specifically asked for filtered categories when adding.
unset($category);
$parentCategories = array_values(array_filter($categories, function ($c) use ($targetCategoryType) {
    return (normalizeInventoryCategoryType($c['type'] ?? '') === $targetCategoryType) && (empty($c['parent_id']) || $c['parent_id'] === null);
}));
// Lazy re-fetch for UI components and JS lookups to avoid "Undefined Variable" errors
$all_categories = $all_categories ?? $pdo->query("SELECT * FROM asset_categories WHERE " . deletedWhereClause($pdo, 'asset_categories') . " ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$all_models = []; // Highly optimized: AJAX Select2 used instead of heavy DB fetch
$all_suppliers = []; // Highly optimized: AJAX Select2 used instead of heavy DB fetch
$all_locations = $all_locations ?? $pdo->query("SELECT * FROM asset_locations WHERE " . deletedWhereClause($pdo, 'asset_locations') . " ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$all_companies = $all_companies ?? $pdo->query("SELECT * FROM asset_companies WHERE " . deletedWhereClause($pdo, 'asset_companies') . " ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$all_departments = []; // Highly optimized: AJAX Select2 used instead of heavy DB fetch
$all_status_labels = $all_status_labels ?? $pdo->query("SELECT * FROM asset_status_labels WHERE " . deletedWhereClause($pdo, 'asset_status_labels') . " ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$all_users = []; // Highly optimized: AJAX Select2 used instead of heavy DB fetch
$all_manufacturers = []; // Highly optimized: AJAX Select2 used instead of heavy DB fetch
$allAssetsList = []; 

$departments = $all_departments;
$models = $all_models;
$users = $all_users;
$all_assets = $allAssetsList;
$status_labels_all = $all_status_labels;
$depreciations = $pdo->query("SELECT id, name FROM asset_depreciations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$field_groups_all = $pdo->query("SELECT DISTINCT field_group as name FROM inventory_custom_fields ORDER BY field_group ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    /* Dark Mode Defaults for Varliklar List */
    body.dark-mode {
        --bs-body-bg: #0f172a;
        --bs-body-color: #f8fafc;
    }

    body.dark-mode .card {
        background-color: #1e293b;
        border-color: #334155 !important;
    }

    body.dark-mode .card-header,
    body.dark-mode .card-footer {
        border-color: #334155 !important;
    }

    body.dark-mode .bg-white,
    body.dark-mode .bg-light,
    body.dark-mode .list-group-item {
        background-color: #1e293b !important;
        color: #f8fafc !important;
    }

    body.dark-mode thead.bg-light th,
    body.dark-mode table.table th {
        background-color: #0f172a !important;
        color: #94a3b8 !important;
        border-color: #334155 !important;
    }

    body.dark-mode .table,
    body.dark-mode .table tbody,
    body.dark-mode .table tr,
    body.dark-mode .table td {
        background-color: #1e293b !important;
        color: #f8fafc !important;
        border-color: #334155 !important;
    }

    body.dark-mode .table-hover tbody tr:hover,
    body.dark-mode .table-hover tbody tr:hover td {
        background-color: rgba(255, 255, 255, 0.05) !important;
    }

    body.dark-mode .text-dark {
        color: #f8fafc !important;
    }

    body.dark-mode .text-muted {
        color: #94a3b8 !important;
    }

    body.dark-mode .border,
    body.dark-mode .border-bottom,
    body.dark-mode .border-top,
    body.dark-mode .border-right,
    body.dark-mode .border-left {
        border-color: #334155 !important;
    }

    body.dark-mode .modal-content {
        background-color: #1e293b;
        color: #f8fafc;
        border: 1px solid #334155;
    }

    body.dark-mode .modal-header,
    body.dark-mode .modal-footer {
        border-color: #334155;
    }

    body.dark-mode .form-control,
    body.dark-mode .form-select {
        background-color: #0f172a;
        border-color: #334155;
        color: #f8fafc;
    }

    body.dark-mode .form-control:focus,
    body.dark-mode .form-select:focus {
        background-color: #0f172a;
        color: #f8fafc;
    }

    body.dark-mode select option {
        background-color: #0f172a;
        color: #f8fafc;
    }

    body.dark-mode .input-group-text {
        background-color: #334155;
        border-color: #334155;
        color: #f8fafc;
    }

    body.dark-mode .badge-light {
        background-color: #334155 !important;
        color: #f8fafc !important;
        border: none !important;
    }

    body.dark-mode .toolbar-btn {
        border-color: #334155 !important;
        color: #f8fafc;
    }

    body.dark-mode .toolbar-btn:hover {
        background-color: #334155 !important;
    }

    body.dark-mode .nav-tabs-custom .nav-link {
        color: #94a3b8;
    }

    body.dark-mode .nav-tabs-custom .nav-link:hover {
        color: #3b82f6;
        background: rgba(59, 130, 246, 0.1);
    }

    body.dark-mode .nav-tabs-custom .nav-link.active {
        background: #1e293b !important;
        color: #3b82f6 !important;
        border-bottom-color: #3b82f6 !important;
    }

    body.dark-mode .nav-tabs-custom {
        border-bottom-color: #334155 !important;
    }

    body.dark-mode .dropdown-menu {
        background-color: #1e293b;
        border-color: #334155;
    }

    body.dark-mode .dropdown-item {
        color: #f8fafc;
    }

    body.dark-mode .dropdown-item:hover {
        background-color: #334155;
        color: #f8fafc;
    }

    body.dark-mode .dropdown-divider {
        border-color: #334155;
    }

    body.dark-mode .spec-section {
        background-color: #0f172a;
        border-color: #334155;
    }

    body.dark-mode .spec-row:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }

    body.dark-mode .btn-light,
    body.dark-mode .btn-outline-secondary {
        background-color: #334155 !important;
        color: #f8fafc !important;
        border-color: #475569 !important;
    }

    body.dark-mode .btn-light:hover,
    body.dark-mode .btn-outline-secondary:hover {
        background-color: #475569 !important;
    }

    /* Supplier Summary Modal Theme Adaptations (Light & Dark Mode) */
    #supplierSummaryModal .modal-content {
        background: #ffffff;
        color: #1e293b;
        border: 1px solid #e2e8f0;
        box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    }
    #supplierSummaryModal .modal-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
    }
    #supplierSummaryModal .modal-title {
        color: #0f172a !important;
    }
    #supplierSummaryModal .supp-header-subtext {
        color: #64748b !important;
    }
    #supplierSummaryModal .supp-close-btn {
        color: #64748b !important;
        opacity: 0.7;
    }
    #supplierSummaryModal .supp-close-btn:hover {
        color: #0f172a !important;
        opacity: 1;
    }
    #supplierSummaryModal .modal-body {
        background: #ffffff;
    }
    #supplierSummaryModal .supp-side-panel {
        background: #f8fafc;
        border-right: 1px solid #e2e8f0;
    }
    #supplierSummaryModal .supp-label {
        color: #64748b !important;
    }
    #supplierSummaryModal .supp-val {
        color: #0f172a !important;
    }
    #supplierSummaryModal .supp-sub-val {
        color: #64748b !important;
    }
    #supplierSummaryModal .supp-notes-box {
        background: rgba(99, 102, 241, 0.08) !important;
        border: 1px solid rgba(99, 102, 241, 0.2) !important;
        color: #4338ca !important;
    }
    #supplierSummaryModal .nav-pills .nav-link {
        background: #f1f5f9;
        color: #64748b;
        border-radius: 20px;
        padding: 6px 18px;
        font-size: 13px;
        font-weight: 600;
        transition: all 0.2s ease;
    }
    #supplierSummaryModal .nav-pills .nav-link:hover {
        color: #4338ca;
        background: #e0e7ff;
    }
    #supplierSummaryModal .nav-pills .nav-link.active {
        background: #4f46e5 !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
    }
    #supplierSummaryModal .nav-pills .nav-link .badge {
        background: rgba(0, 0, 0, 0.08);
        color: inherit;
    }
    #supplierSummaryModal .nav-pills .nav-link.active .badge {
        background: rgba(255, 255, 255, 0.25);
        color: #ffffff;
    }
    #supplierSummaryModal table.table {
        background: transparent;
        color: #1e293b;
    }
    #supplierSummaryModal table.table thead th {
        background: transparent !important;
        color: #64748b !important;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        border-bottom: 1px solid #e2e8f0 !important;
        border-top: none !important;
    }
    #supplierSummaryModal table.table tbody tr {
        border-bottom: 1px solid #f1f5f9;
    }
    #supplierSummaryModal table.table tbody tr:hover {
        background: rgba(99, 102, 241, 0.04);
    }

    /* Dark Mode Overrides for Supplier Summary Modal */
    body.dark-mode #supplierSummaryModal .modal-content {
        background: #1e2130 !important;
        color: #e2e8f0 !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }
    body.dark-mode #supplierSummaryModal .modal-header {
        background: #252840 !important;
        border-bottom-color: rgba(255, 255, 255, 0.07) !important;
    }
    body.dark-mode #supplierSummaryModal .modal-title {
        color: #f1f5f9 !important;
    }
    body.dark-mode #supplierSummaryModal .supp-header-subtext {
        color: #94a3b8 !important;
    }
    body.dark-mode #supplierSummaryModal .supp-close-btn {
        color: #94a3b8 !important;
    }
    body.dark-mode #supplierSummaryModal .supp-close-btn:hover {
        color: #f1f5f9 !important;
    }
    body.dark-mode #supplierSummaryModal .modal-body {
        background: #1e2130 !important;
    }
    body.dark-mode #supplierSummaryModal .supp-side-panel {
        background: rgba(255, 255, 255, 0.03) !important;
        border-right-color: rgba(255, 255, 255, 0.07) !important;
    }
    body.dark-mode #supplierSummaryModal .supp-label {
        color: #64748b !important;
    }
    body.dark-mode #supplierSummaryModal .supp-val {
        color: #f1f5f9 !important;
    }
    body.dark-mode #supplierSummaryModal .supp-sub-val {
        color: #94a3b8 !important;
    }
    body.dark-mode #supplierSummaryModal .supp-notes-box {
        background: rgba(99, 102, 241, 0.1) !important;
        border-color: rgba(99, 102, 241, 0.2) !important;
        color: #a5b4fc !important;
    }
    body.dark-mode #supplierSummaryModal .nav-pills .nav-link {
        background: rgba(255, 255, 255, 0.05) !important;
        color: #94a3b8 !important;
    }
    body.dark-mode #supplierSummaryModal .nav-pills .nav-link:hover {
        color: #818cf8 !important;
        background: rgba(99, 102, 241, 0.15) !important;
    }
    body.dark-mode #supplierSummaryModal .nav-pills .nav-link.active {
        background: rgba(99, 102, 241, 0.85) !important;
        color: #ffffff !important;
    }
    body.dark-mode #supplierSummaryModal table.table {
        color: #cbd5e1 !important;
    }
    body.dark-mode #supplierSummaryModal table.table thead th {
        color: #64748b !important;
        border-bottom-color: rgba(255, 255, 255, 0.08) !important;
    }
    body.dark-mode #supplierSummaryModal table.table tbody tr {
        border-bottom-color: rgba(255, 255, 255, 0.05) !important;
    }
    body.dark-mode #supplierSummaryModal table.table tbody tr:hover {
        background: rgba(255, 255, 255, 0.03) !important;
    }

    /* Expanded Container Layout */
    .main-content-row {
        width: 100% !important;
        max-width: 100% !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        padding: 0 15px !important;
    }

    /* Standardized Header Fonts and Scaling for Bilingual UI */
    .card-title, .modal-title {
        font-size: 1.15rem !important;
        letter-spacing: -0.2px;
    }
    
    .nav-header {
        font-size: 0.75rem !important;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .main-header .nav-link {
        font-size: 0.9rem;
    }

    @media (min-width: 1600px) {
        .main-content-row {
            padding: 0 40px !important;
        }
    }


    .spec-row {
        transition: all 0.2s;
        border-radius: 6px;
    }

    .spec-row:hover {
        background: rgba(0, 0, 0, 0.03);
    }

    .btn-add-spec {
        border: 1px dashed #007bff;
        color: #007bff;
        width: 100%;
        border-radius: 8px;
        font-weight: 500;
        transition: 0.3s;
    }

    .btn-add-spec:hover {
        background: #007bff;
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2);
    }

    .font-monospace {
        font-family: 'Courier New', Courier, monospace;
    }

    .badge-soft-primary {
        background: #e7f1ff;
        color: #007bff;
        border-radius: 6px;
        font-weight: 500;
    }

    .spec-section {
        background: #f8f9fa;
        border-radius: 10px;
        border: 1px solid #eee;
        padding: 15px;
    }

    .spec-section label.spec-title {
        font-weight: 700;
        font-size: 12px;
        text-transform: uppercase;
        color: #888;
        letter-spacing: 0.5px;
        margin-bottom: 10px;
        display: block;
    }

    /* Table headers and cells wrapping and word break fixes */
    table.table thead th {
        white-space: nowrap !important;
    }
    table.table th, table.table td {
        word-break: keep-all !important;
        overflow-wrap: normal !important;
    }

    /* Timeline */
    .timeline-item {
        position: relative;
        padding-left: 30px;
        margin-bottom: 12px;
    }

    .timeline-item::before {
        content: '';
        position: absolute;
        left: 8px;
        top: 24px;
        bottom: -12px;
        width: 2px;
        background: #e9ecef;
    }

    .timeline-item:last-child::before {
        display: none;
    }

    .timeline-dot {
        position: absolute;
        left: 0;
        top: 4px;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 9px;
        box-shadow: 0 0 0 3px #fff;
    }

    .timeline-content {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 8px 12px;
        font-size: 13px;
    }

    .timeline-content small {
        font-size: 11px;
    }

    .copy-key:hover {
        background-color: #e2e8f0 !important;
        color: #1e293b;
    }

    @media print {

        .col-actions,
        .no-print,
        .card-header,
        .bg-light.p-3,
        .btn-group,
        .dropdown {
            display: none !important;
        }

        .table-responsive {
            overflow: visible !important;
        }

        .card {
            border: none !important;
            box-shadow: none !important;
        }
    }
</style>

<script>
    if (typeof placeholderImg === 'undefined') {
        var placeholderImg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%23f3f4f6'/%3E%3Ctext x='50%25' y='50%25' dominant-baseline='middle' text-anchor='middle' font-family='sans-serif' font-size='10' fill='%239ca3af'%3E<?= $isTr ? 'Resim Yok' : 'No Image' ?>%3C/text%3E%3C/svg%3E";
    }

    function escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
</script>

<!-- TABLOLAR YÜKLENİYOR OVERLAY -->
<div id="inv-tables-loading-overlay" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(15, 23, 42, 0.75); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); z-index:99999; flex-direction:column; align-items:center; justify-content:center; color:#fff; transition:opacity 0.3s ease;">
    <div class="text-center p-4" style="background:rgba(30, 41, 59, 0.95); border:1px solid rgba(255,255,255,0.18); border-radius:24px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.6); max-width:460px; width:90%; position:relative; overflow:hidden;">
        <div class="mb-3 pt-2">
            <div class="spinner-border text-primary" role="status" style="width:3.5rem; height:3.5rem; border-width:4px;">
                <span class="sr-only">Yükleniyor...</span>
            </div>
        </div>
        <h5 class="font-weight-bold text-white mb-2" style="font-size:1.25rem;">
            <i class="fas fa-table text-info mr-2"></i><?= $isTr ? 'Tablolar ve Veriler Yükleniyor' : 'Loading Tables & Data' ?>
        </h5>
        <p class="text-muted small mb-3" style="color:#cbd5e1 !important; font-size:0.88rem; line-height:1.5;">
            <?= $isTr ? 'Sistem tabloları ve veriler hazırlanıyor. Lütfen bekleyin...' : 'System tables and asset records are initializing. Please wait...' ?>
        </p>
        <div class="progress" style="height:6px; border-radius:10px; background:rgba(255,255,255,0.1); overflow:hidden;">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: 100%;"></div>
        </div>
    </div>
</div>

<script>
(function() {
    function showInvLoader() {
        const overlay = document.getElementById('inv-tables-loading-overlay');
        if (overlay) {
            overlay.style.display = 'flex';
            overlay.style.opacity = '1';
        }
    }
    function hideInvLoader() {
        const overlay = document.getElementById('inv-tables-loading-overlay');
        if (overlay) {
            overlay.style.opacity = '0';
            setTimeout(() => { overlay.style.display = 'none'; }, 300);
        }
    }

    // Attach listener to all inventory tab links & category filters
    document.addEventListener('DOMContentLoaded', function() {
        const links = document.querySelectorAll('a[href*="varliklar?view="], .nav-tabs-custom a.nav-link');
        links.forEach(function(link) {
            link.addEventListener('click', function(e) {
                if (link.getAttribute('href') && !link.getAttribute('href').startsWith('#') && !e.ctrlKey && !e.metaKey) {
                    showInvLoader();
                }
            });
        });

        // Hide overlay once page DOM and scripts finish loading
        hideInvLoader();
    });

    // Show initial overlay briefly if this is the first page load in session
    if (!sessionStorage.getItem('eaprimus_inv_tables_loaded')) {
        sessionStorage.setItem('eaprimus_inv_tables_loaded', '1');
        showInvLoader();
    }
})();
</script>

<div class="row main-content-row">
    <div class="col-12">
        <div class="card shadow-sm border-0 mb-3" style="border-radius:15px; overflow: visible !important;">
            <?php
            $isPersonnelRole = ((int)$current_user_role === 2);
            $personnelCounts = [
                'assets' => 0,
                'licenses' => 0,
                'accessories' => 0,
                'consumables' => 0,
                'components' => 0
            ];

            if ($isPersonnelRole) {
                $personnelCounts = getPersonnelCategoryCounts($pdo, (int)$current_user_id);
            }
            ?>
            <div class="card-header bg-white p-0 border-bottom">
                <ul class="nav nav-tabs nav-tabs-custom border-0" id="inventoryTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link <?= ($view == 'assets') ? 'active' : '' ?> py-3 px-4"
                            href="varliklar?view=assets">
                            <i class="fas fa-laptop mr-2"></i> <?= __("fixed_assets") ?>
                            <?php if ($isPersonnelRole): ?><span class="badge badge-secondary ml-1"><?= $personnelCounts['assets'] ?></span><?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= ($view == 'licenses') ? 'active' : '' ?> py-3 px-4"
                            href="varliklar?view=licenses">
                            <i class="fas fa-id-card mr-2"></i> <?= __("licenses") ?>
                            <?php if ($isPersonnelRole): ?><span class="badge badge-secondary ml-1"><?= $personnelCounts['licenses'] ?></span><?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= ($view == 'accessories') ? 'active' : '' ?> py-3 px-4"
                            href="varliklar?view=accessories">
                            <i class="fas fa-keyboard mr-2"></i> <?= __("accessories") ?>
                            <?php if ($isPersonnelRole): ?><span class="badge badge-secondary ml-1"><?= $personnelCounts['accessories'] ?></span><?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= ($view == 'consumables') ? 'active' : '' ?> py-3 px-4"
                            href="varliklar?view=consumables">
                            <i class="fas fa-tint mr-2"></i> <?= __("consumables") ?>
                            <?php if ($isPersonnelRole): ?><span class="badge badge-secondary ml-1"><?= $personnelCounts['consumables'] ?></span><?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= ($view == 'components') ? 'active' : '' ?> py-3 px-4"
                            href="varliklar?view=components">
                            <i class="fas fa-microchip mr-2"></i> <?= __("components") ?>
                            <?php if ($isPersonnelRole): ?><span class="badge badge-secondary ml-1"><?= $personnelCounts['components'] ?></span><?php endif; ?>
                        </a>
                    </li>

                    <?php if (!$isPersonnelRole): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($view == 'people') ? 'active' : '' ?> py-3 px-4"
                            href="varliklar?view=people">
                            <i class="fas fa-users mr-2"></i> <?= __("people") ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php
                    $c_sigs_tab = 0;
                    try {
                        $isAdminMenu = in_array($current_user_role, [1, 3]);
                        $whereCondMenu = $isAdminMenu ? "(s.status = 'pending_user' AND s.user_id = " . (int)$current_user_id . ") OR (s.status = 'pending_admin')" : "s.status = 'pending_user' AND s.user_id = " . (int)$current_user_id;
                        $c_sigs_tab = $pdo->query("SELECT COUNT(*) FROM asset_signatures s WHERE $whereCondMenu")->fetchColumn();
                    } catch (Exception $e) {}
                    ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($view == 'signatures') ? 'active' : '' ?> py-3 px-4"
                            href="varliklar?view=signatures">
                            <i class="fas fa-file-signature mr-2"></i> <?= $isTr ? 'Zimmet Onaylarım' : 'My Approvals' ?>
                            <?php if ($c_sigs_tab > 0): ?>
                                <span class="badge badge-danger ml-2"><?= $c_sigs_tab ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <!-- 'Ön Tanımlı Alanlar' sekmesi kaldırıldı isteğe bağlı olarak ayarlar altında erişilebilir -->
                </ul>
            </div>
            <style>
                .nav-tabs-custom {
                    flex-wrap: nowrap !important;
                    overflow-x: auto !important;
                    overflow-y: hidden !important;
                    white-space: nowrap !important;
                    scrollbar-width: none; /* Firefox */
                }
                .nav-tabs-custom::-webkit-scrollbar {
                    display: none; /* Safari and Chrome */
                }
                .nav-tabs-custom .nav-link {
                    border: none;
                    color: #64748b;
                    font-weight: 600;
                    transition: 0.3s;
                    border-bottom: 3px solid transparent;
                }

                .nav-tabs-custom .nav-link:hover {
                    color: #1e293b;
                    background: rgba(0, 0, 0, 0.05);
                }

                .nav-tabs-custom .nav-link.active {
                    color: #1e293b;
                    border-bottom-color: #334155;
                    background: #fff;
                }

                body.dark-mode .nav-tabs-custom .nav-link {
                    color: #94a3b8;
                    border-bottom: 2px solid transparent;
                }
                body.dark-mode .nav-tabs-custom .nav-link:hover {
                    background: rgba(255, 255, 255, 0.05);
                    color: #f8fafc;
                }
                body.dark-mode .nav-tabs-custom .nav-link.active {
                    background: transparent;
                    color: #f8fafc;
                    border-bottom-color: #60a5fa;
                }

                body.dark-mode .btn-outline-primary {
                    color: #f8fafc !important;
                    border-color: #475569 !important;
                }
                body.dark-mode .btn-outline-primary:hover {
                    background-color: #475569 !important;
                    color: #fff !important;
                }

                .toolbar-btn {
                    height: 38px;
                    width: 38px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 4px;
                    padding: 0;
                    font-size: 14px;
                    border: 1px solid #dee2e6;
                    color: #444;
                    transition: 0.2s;
                    margin-left: 1px;
                }

                .toolbar-btn:hover {
                    background: #f8f9fa;
                    color: #007bff;
                }

                .toolbar-btn-blue {
                    background: #0891b2;
                    color: #fff;
                    border-color: #0891b2;
                }

                .toolbar-btn-blue:hover {
                    background: #0e7490;
                    color: #fff;
                }

                .toolbar-btn-cyan {
                    background: #06b6d4;
                    color: #fff;
                    border-color: #06b6d4;
                }

                .toolbar-btn-cyan:hover {
                    background: #0891b2;
                    color: #fff;
                }

                .action-btn {
                    width: 30px;
                    height: 30px;
                    border-radius: 4px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0 4px;
                    color: #fff !important;
                    border: none;
                    transition: 0.2s;
                    padding: 0;
                }

                .action-btn:hover {
                    opacity: 0.8;
                    transform: translateY(-1px);
                }

                .action-btn-copy {
                    background: #06b6d4;
                }

                .action-btn-edit {
                    background: #f97316;
                }

                .action-btn-delete {
                    background: #ef4444;
                }

                .action-btn-timeline {
                    background: #f8af30;
                }

                .btn-assignment {
                    background: #db2777;
                    color: #fff !important;
                    border-radius: 6px;
                    height: 30px;
                    padding: 0 10px;
                    font-size: 13px;
                    font-weight: 600;
                    border: none;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                }

                .btn-assignment:hover {
                    background: #be185d;
                }

                .btn-checkin {
                    background: #db2777;
                    opacity: 0.6;
                }

                /* Assignment column alignment */
                .col-assignment {
                    vertical-align: middle;
                    width: 140px;
                }

                td.col-assignment {
                    vertical-align: middle;
                }

                /* Ensure action buttons fit and align */
                .col-actions {
                    min-width: 150px;
                }

                td.col-actions {
                    vertical-align: middle;
                }

                td.col-actions .action-btn {
                    width: 30px;
                    height: 30px;
                    margin: 0 4px;
                }

                td.col-actions .action-group {
                    display: flex;
                    align-items: center;
                    justify-content: flex-end;
                    gap: 6px;
                }

                /* Remove any blue/persistent borders as requested */
                .table,
                .table tr,
                .table td,
                .table th {
                    border-color: #f1f5f9 !important;
                    border-image: none !important;
                    box-shadow: none !important;
                    outline: none !important;
                }

                .table thead th {
                    border-bottom: 2px solid #e2e8f0 !important;
                    border-top: none !important;
                    background-color: #f8fafc !important;
                    color: #64748b !important;
                    font-size: 13px !important;
                    text-transform: uppercase !important;
                    font-weight: 700 !important;
                }

                .table tbody td {
                    border-bottom: 1px solid #f8fafc !important;
                    vertical-align: middle !important;
                }

                .ds-card {
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05) !important;
                    border: 1px solid #f1f5f9 !important;
                }

                .table-hover tbody tr:hover {
                    background-color: rgba(0, 0, 0, 0.01) !important;
                }
                .dropdown-menu {
                    z-index: 10000 !important;
                }
                .z-index-999 {
                    z-index: 9999 !important;
                    position: relative !important;
                }
                .table-responsive {
                    overflow: visible !important;
                }
                .status-scrap {
                    color: #111827 !important;
                }
                [data-theme="dark"] .status-scrap, 
                .dark-mode .status-scrap {
                    color: #f3f4f6 !important;
                }
                .badge-soft-dark {
                    background-color: rgba(17, 24, 39, 0.1);
                    color: #111827;
                    border: 1px solid rgba(17, 24, 39, 0.2);
                }
                .dark-mode .badge-soft-dark {
                    background-color: rgba(243, 244, 246, 0.1);
                    color: #f3f4f6;
                    border: 1px solid rgba(243, 244, 246, 0.2);
                }
                
                /* Dark Mode Button Visibility */
                [data-theme="dark"] .btn-outline-primary,
                .dark-mode .btn-outline-primary,
                [data-theme="dark"] .btn-outline-info,
                .dark-mode .btn-outline-info {
                    color: #fff !important;
                    border-color: rgba(255, 255, 255, 0.4) !important;
                }
                [data-theme="dark"] .btn-outline-primary:hover,
                .dark-mode .btn-outline-primary:hover {
                    background-color: #007bff !important;
                    color: #fff !important;
                }
                .card-body.p-0 tr:nth-child(even) {
                    background-color: #fdfdfd !important;
                }
                .card-body.p-0 tr:hover {
                    background-color: #f8fafc !important;
                }
                [data-theme="dark"] .card-body.p-0 tr:nth-child(even) {
                    background-color: rgba(255, 255, 255, 0.02) !important;
                }
                [data-theme="dark"] .card-body.p-0 tr:hover {
                    background-color: rgba(255, 255, 255, 0.05) !important;
                }
                .card-body.p-0 tr {
                    background-color: #ffffff !important;
                    border-bottom: 1px solid #f1f5f9;
                }
                [data-theme="dark"] .card-body.p-0 tr {
                    background-color: transparent !important;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
                }
            
                /* Premium Action Buttons for Fixed Assets List */
                .btn-action-checkout, .btn-action-checkin, .btn-action-disabled {
                    display: inline-flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                    font-size: 11px !important;
                    font-weight: 700 !important;
                    padding: 5px 12px !important;
                    border-radius: 20px !important;
                    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.03) !important;
                    min-width: 105px !important;
                    height: 28px !important;
                    border: 1px solid transparent !important;
                    cursor: pointer !important;
                    text-transform: uppercase !important;
                    letter-spacing: 0.5px !important;
                    gap: 5px !important;
                    line-height: 1 !important;
                    text-decoration: none !important;
                }
                .btn-action-checkout i, .btn-action-checkin i, .btn-action-disabled i {
                    font-size: 11px !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }

                /* Assign Button Style (Blue/Primary Soft) */
                .btn-action-checkout {
                    background-color: rgba(0, 123, 255, 0.08) !important;
                    color: #007bff !important;
                    border: 1px solid rgba(0, 123, 255, 0.25) !important;
                }
                .btn-action-checkout:hover {
                    background-color: #007bff !important;
                    color: #ffffff !important;
                    border-color: #007bff !important;
                    box-shadow: 0 4px 8px rgba(0, 123, 255, 0.25) !important;
                    transform: translateY(-1px) !important;
                }

                /* Checkin Button Style (Orange/Warning Soft) */
                .btn-action-checkin {
                    background-color: rgba(245, 158, 11, 0.08) !important;
                    color: #d97706 !important;
                    border: 1px solid rgba(245, 158, 11, 0.25) !important;
                }
                .btn-action-checkin:hover {
                    background-color: #f59e0b !important;
                    color: #ffffff !important;
                    border-color: #f59e0b !important;
                    box-shadow: 0 4px 8px rgba(245, 158, 11, 0.25) !important;
                    transform: translateY(-1px) !important;
                }

                /* Disabled Button Style (Gray Soft) */
                .btn-action-disabled {
                    background-color: rgba(107, 114, 128, 0.08) !important;
                    color: #6b7280 !important;
                    border: 1px solid rgba(107, 114, 128, 0.2) !important;
                    cursor: not-allowed !important;
                }

                /* Dark Mode Styles */
                body.dark-mode .btn-action-checkout,
                [data-theme="dark"] .btn-action-checkout {
                    background-color: rgba(59, 130, 246, 0.15) !important;
                    color: #60a5fa !important;
                    border: 1px solid rgba(59, 130, 246, 0.35) !important;
                }
                body.dark-mode .btn-action-checkout:hover,
                [data-theme="dark"] .btn-action-checkout:hover {
                    background-color: #3b82f6 !important;
                    color: #ffffff !important;
                    border-color: #3b82f6 !important;
                    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35) !important;
                }

                body.dark-mode .btn-action-checkin,
                [data-theme="dark"] .btn-action-checkin {
                    background-color: rgba(245, 158, 11, 0.15) !important;
                    color: #fbbf24 !important;
                    border: 1px solid rgba(245, 158, 11, 0.35) !important;
                }
                body.dark-mode .btn-action-checkin:hover,
                [data-theme="dark"] .btn-action-checkin:hover {
                    background-color: #f59e0b !important;
                    color: #ffffff !important;
                    border-color: #f59e0b !important;
                    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35) !important;
                }

                body.dark-mode .btn-action-disabled,
                [data-theme="dark"] .btn-action-disabled {
                    background-color: rgba(156, 163, 175, 0.12) !important;
                    color: #9ca3af !important;
                    border: 1px solid rgba(156, 163, 175, 0.25) !important;
                }

            </style>

            <div class="card-body p-0" style="overflow: visible !important; min-height: 600px; position: relative;">
                <!-- UNIFIED ACTION TOOLBAR -->
                <?php if ($view !== 'signatures'): ?>
                <div class="p-3 border-bottom d-flex flex-wrap justify-content-between align-items-center bg-white shadow-sm sticky-top"
                    style="z-index: 1000; overflow: visible !important;">
                    <!-- LEFT: Action Buttons Group -->
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($view == 'people'): ?>
                            <h4 class="mb-0 font-weight-bold text-dark"><i
                                    class="fas fa-users mr-2 text-primary"></i><?= __("people") ?></h4>
                        <?php else: ?>
                            <!-- Column Visibility Dropdown -->
                            <div class="dropdown">
                                <button class="btn btn-white dropdown-toggle shadow-sm d-flex align-items-center"
                                    type="button" id="colVisDropdown" data-toggle="dropdown" aria-haspopup="true"
                                    aria-expanded="false" data-boundary="window"
                                    style="height:40px; border-radius:10px; border:1px solid #e2e8f0;">
                                    <i class="fas fa-columns mr-2 text-muted"></i> <?= $isTr ? 'Sütunlar' : 'Columns' ?>
                                </button>
                                <div class="dropdown-menu dropdown-menu-left p-3 shadow-lg border-0"
                                    aria-labelledby="colVisDropdown"
                                    style="min-width: 270px; border-radius:15px; max-height: 550px; overflow-y: auto; z-index: 10000 !important; box-shadow: 0 15px 50px rgba(0,0,0,0.3) !important; margin-left:20px;">
                                    <h6 class="dropdown-header px-0 mb-2 font-weight-bold text-dark"
                                        style="font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                                        <?= $isTr ? 'Sütunları Göster/Gizle' : __("column_visibility") ?></h6>
                                    <div class="d-flex justify-content-between mb-3 bg-light p-2 rounded-lg">
                                        <button class="btn btn-xs btn-link font-weight-bold text-primary p-0"
                                            onclick="toggleAllCols(true)"><?= $isTr ? 'Tümünü Göster' : 'Show All' ?></button>
                                        <button class="btn btn-xs btn-link font-weight-bold text-danger p-0"
                                            onclick="toggleAllCols(false)"><?= $isTr ? 'Tümünü Gizle' : 'Hide All' ?></button>
                                    </div>
                                    <?php
                                    $col_map = [
                                        'col-image' => 'device_image',
                                        'col-tag' => 'asset_tag',
                                        'col-serial' => 'serial_no',
                                        'col-model' => 'model',
                                        'col-category' => 'category',
                                        'col-status' => 'status',
                                        'col-user' => 'checked_out_to',
                                        'col-dept' => 'department',
                                        'col-company' => 'company',
                                        'col-location' => 'location',
                                        'col-checkout' => 'assignment',
                                        'col-actions' => 'actions',
                                        'col-item-no' => 'item_no',
                                        'col-order' => 'order_number',
                                        'col-purchase' => 'purchase_date',
                                        'col-min-qty' => 'min_qty',
                                        'col-total' => 'total',
                                        'col-remaining' => 'remaining',
                                        'col-cost' => 'unit_cost',
                                        'col-total-cost' => 'total_cost',
                                        'col-key' => 'product_key',
                                        'col-expire' => 'expire_date',
                                        'col-email' => 'email',
                                        'col-lic-name' => 'license_name',
                                        'col-manufacturer' => 'manufacturer',
                                        'col-supplier' => 'supplier',
                                        'col-name' => 'name',
                                        'col-notes' => 'notes',
                                        'col-type' => 'type',
                                        'col-status-type' => 'status_type',
                                        'col-status-color' => 'status_color',
                                        'col-show-in-nav' => 'show_in_nav',
                                        'col-is-default' => 'is_default',
                                        'col-field-name' => 'field_name',
                                        'col-field-type' => 'field_type',
                                        'col-field-group' => 'field_group',
                                        'col-options' => 'options',
                                        'col-field-status' => 'field_status',
                                        'col-warranty' => 'warranty',
                                        'col-assigned-target' => 'assigned_target'
                                    ];

                                    $view_specific_cols = [
                                        'assets' => ['col-image', 'col-tag', 'col-serial', 'col-manufacturer', 'col-supplier', 'col-model', 'col-category', 'col-status', 'col-user', 'col-dept', 'col-company', 'col-checkout', 'col-actions'],
                                        'licenses' => ['col-image', 'col-category', 'col-company', 'col-order', 'col-total', 'col-remaining', 'col-cost', 'col-key', 'col-expire', 'col-email', 'col-lic-name', 'col-manufacturer', 'col-dept', 'col-supplier', 'col-purchase', 'col-notes', 'col-actions'],
                                        'accessories' => ['col-image', 'col-category', 'col-serial', 'col-order', 'col-purchase', 'col-total', 'col-remaining', 'col-cost', 'col-manufacturer', 'col-dept', 'col-company', 'col-supplier', 'col-warranty', 'col-notes', 'col-actions'],
                                        'consumables' => ['col-image', 'col-category', 'col-company', 'col-serial', 'col-order', 'col-purchase', 'col-total', 'col-remaining', 'col-cost', 'col-manufacturer', 'col-dept', 'col-supplier', 'col-actions'],
                                        'components' => ['col-image', 'col-serial', 'col-order', 'col-category', 'col-company', 'col-purchase', 'col-total', 'col-remaining', 'col-cost', 'col-manufacturer', 'col-dept', 'col-supplier', 'col-checkout', 'col-actions'],
                                        'predefined' => [
                                            'categories' => ['col-type', 'col-notes', 'col-actions'],
                                            'models' => ['col-notes', 'col-actions'],
                                            'manufacturers' => ['col-notes', 'col-actions'],
                                            'suppliers' => ['col-notes', 'col-actions'],
                                            'companies' => ['col-notes', 'col-actions'],
                                            'departments' => ['col-notes', 'col-actions'],
                                            'locations' => ['col-notes', 'col-actions'],
                                            'status_labels' => ['col-status-type', 'col-status-color', 'col-show-in-nav', 'col-is-default', 'col-actions'],
                                            'depreciation' => ['col-notes', 'col-actions'],
                                            'custom_fields' => ['col-field-name', 'col-field-type', 'col-field-group', 'col-options', 'col-field-status', 'col-actions']
                                        ]
                                    ];

                                    $allowed = $view_specific_cols[$view] ?? array_keys($col_map);
                                    if ($view === 'predefined' && isset($_GET['type'])) {
                                        $allowed = $view_specific_cols['predefined'][$_GET['type']] ?? $allowed;
                                    }

                                    foreach ($col_map as $cls => $lbl):
                                        if (!in_array($cls, $allowed))
                                            continue;
                                        ?>
                                        <?php
                                        $finalLabel = __($lbl);
                                        if ($isTr) {
                                            if ($lbl === 'unit_cost')
                                                $finalLabel = 'Satın Alma Ücreti';
                                            if ($lbl === 'total_cost')
                                                $finalLabel = 'Toplam Ücret';
                                        }
                                        ?>
                                        <div class="custom-control custom-switch mb-2 p-1 pl-5 hover-bg-light rounded"
                                            style="transition:0.2s; position:relative;">
                                            <?php
                                             $isColChecked = true;
                                             if ($view === 'licenses') {
                                                 if (in_array($cls, ['col-email', 'col-manufacturer', 'col-notes'])) {
                                                     $isColChecked = false;
                                                 }
                                             } elseif ($view === 'accessories') {
                                                 if (in_array($cls, ['col-order', 'col-supplier', 'col-warranty', 'col-notes'])) {
                                                     $isColChecked = false;
                                                 }
                                             } elseif ($view === 'consumables') {
                                                 if (in_array($cls, ['col-serial', 'col-manufacturer', 'col-supplier', 'col-dept', 'col-order', 'col-purchase'])) {
                                                     $isColChecked = false;
                                                 }
                                             }
                                             elseif ($view === 'components') {
                                                 if (in_array($cls, ['col-order', 'col-cost', 'col-manufacturer', 'col-supplier'])) {
                                                     $isColChecked = false;
                                                 }
                                             }
                                            ?>
                                            <input type="checkbox" class="custom-control-input col-vis-toggle"
                                                id="sw-<?= $cls ?>" data-column="<?= $cls ?>" <?= $isColChecked ? 'checked' : '' ?> style="cursor:pointer;">
                                            <label class="custom-control-label font-weight-bold cursor-pointer"
                                                for="sw-<?= $cls ?>"
                                                style="font-size:13px; color:#475569; padding-left:5px;"><?= $finalLabel ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Main Buttons Wrapper -->
                            <div class="btn-group shadow-sm"
                                style="border-radius:10px; overflow:hidden; border:1px solid #e2e8f0;">
                                <?php if ($view !== 'people' && $can_edit_varliklar): ?>
                                    <button class="btn btn-primary d-flex align-items-center justify-content-center"
                                        style="height:40px; width:45px; border:none;" onclick="addAssetByView('<?= $view ?>')"
                                        title="<?= __("new_asset") ?>">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                <?php endif; ?>

                                <?php
                                $trashUrl = getCleanInventoryRedirectUrl($view, !$show_deleted, $_GET['type'] ?? '');
                                ?>
                                <a href="<?= $trashUrl ?>"
                                    class="btn btn-white d-flex align-items-center justify-content-center <?= $show_deleted ? 'bg-danger text-white border-danger' : 'text-danger' ?>"
                                    title="<?= $show_deleted ? __("view_active") : __("trash") ?>"
                                    style="width:45px; height:40px; border-left:1px solid #e2e8f0;">
                                    <i class="fas <?= $show_deleted ? 'fa-undo' : 'fa-trash-alt' ?>"></i>
                                </a>

                                <?php if ($show_deleted):
                                    $trashTable = ($view == 'predefined') ? inventoryTableMeta($_GET['type'] ?? 'categories')['table'] : (($view == 'assets') ? 'assets' : "asset_" . $view);
                                    $trashCount = 0;
                                    try {
                                        $trashCount = $pdo->query("SELECT COUNT(*) FROM $trashTable WHERE deleted_at IS NOT NULL")->fetchColumn();
                                    } catch (PDOException $e) {
                                        $trashCount = 0;
                                    }
                                    ?>
                                    <button class="btn btn-danger d-flex align-items-center justify-content-center"
                                        onclick="confirmEmptyTrash()" <?= $trashCount == 0 ? 'disabled' : '' ?>
                                        style="width:45px; height:40px; border-left:1px solid #e2e8f0;"
                                        title="<?= $isTr ? 'Çöpü Boşalt' : 'Empty Trash' ?>">
                                        <i class="fas fa-dumpster-fire"></i>
                                    </button>
                                <?php endif; ?>

                                <button class="btn btn-white d-flex align-items-center justify-content-center"
                                    onclick="location.reload()"
                                    style="width:45px; height:40px; border-left:1px solid #e2e8f0;"
                                    title="<?= __("refresh") ?>">
                                    <i class="fas fa-sync-alt text-muted"></i>
                                </button>

                                <button class="btn btn-white d-flex align-items-center justify-content-center"
                                    onclick="openExportModal('excel')" style="width:45px; height:40px; border-left:1px solid #e2e8f0;"
                                    title="Excel">
                                    <i class="fas fa-file-csv text-success"></i>
                                </button>
                                <button class="btn btn-white d-flex align-items-center justify-content-center"
                                    onclick="openExportModal('pdf')" style="width:45px; height:40px; border-left:1px solid #e2e8f0;"
                                    title="PDF">
                                    <i class="fas fa-file-pdf text-danger"></i>
                                </button>
                                <button class="btn btn-white d-flex align-items-center justify-content-center"
                                    onclick="window.print()" style="width:45px; height:40px; border-left:1px solid #e2e8f0;"
                                    title="<?= __("print") ?>">
                                    <i class="fas fa-print text-dark"></i>
                                </button>

                                <button class="btn btn-white d-flex align-items-center justify-content-center"
                                    onclick="toggleFullScreen()"
                                    style="width:45px; height:40px; border-left:1px solid #e2e8f0;"
                                    title="<?= __("fullscreen") ?>">
                                    <i class="fas fa-expand text-muted"></i>
                                </button>
                            </div>
                        </div>

                        <!-- RIGHT: Quick Filters -->
                        <div class="d-flex align-items-center gap-2 justify-content-end"
                            style="flex-grow: 1; max-width:800px;">
                            <?php
                            $searchPlaceholder = __("search") . "...";
                            if ($view === 'predefined') {
                                if (($_GET['type'] ?? '') === 'custom_fields') {
                                    $searchPlaceholder = $isTr ? "Alan Ara..." : "Search Fields...";
                                } else {
                                    $searchPlaceholder = $isTr ? "İsim Ara..." : "Search Name...";
                                }
                            } elseif ($view === 'components') {
                                $searchPlaceholder = $isTr ? "Bileşen Ara..." : "Search Components...";
                            } elseif ($view === 'consumables') {
                                $searchPlaceholder = $isTr ? "Sarf Malzeme Ara..." : "Search Consumables...";
                            } elseif ($view === 'accessories') {
                                $searchPlaceholder = $isTr ? "Aksesuar Ara..." : "Search Accessories...";
                            } elseif ($view === 'licenses') {
                                $searchPlaceholder = $isTr ? "Lisans Ara..." : "Search Licenses...";
                            } else {
                                $searchPlaceholder = $isTr ? "Varlık Ara (Etiket, PC Adı, Seri No, Şirket, Notlar)..." : "Search Assets (Tag, PC Name, Serial, Company, Notes)...";
                            }
                            ?>
                            <div class="input-group" style="max-width:300px;">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-white border-right-0"
                                        style="border-radius:10px 0 0 10px;"><i class="fas fa-search text-muted"></i></span>
                                </div>
                                <input type="text" id="assetSearch" class="form-control border-left-0 shadow-sm px-2"
                                    onkeyup="filterAssetTable(event)" placeholder="<?= $searchPlaceholder ?>"
                                    value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                    style="height:40px; border-radius:0 10px 10px 0; font-size:14px; border:1px solid #e2e8f0 !important;">
                            </div>

                            <?php if ($view !== 'people' && $view !== 'predefined'): ?>
                                <select id="filterCategory" class="form-control shadow-sm mr-1" onchange="filterAssetTable()"
                                    style="height:40px; border-radius:10px; font-size:13px; max-width:180px; border:1px solid #e2e8f0;">
                                    <option value=""><?= __("all_categories") ?></option>
                                    <?php
                                    // Group categories by parent
                                    $parents = array_filter($categories, function ($c) use ($targetCategoryType) {
                                        return (normalizeInventoryCategoryType($c['type'] ?? '') === $targetCategoryType) && empty($c['parent_id']);
                                    });
                                    $children = array_filter($categories, function ($c) use ($targetCategoryType) {
                                        return (normalizeInventoryCategoryType($c['type'] ?? '') === $targetCategoryType) && !empty($c['parent_id']);
                                    });

                                    foreach ($parents as $p):
                                        $pName = $isTr ? $p['name'] : ($p['name_en'] ?? $p['name']);
                                        $subs = array_filter($children, function ($c) use ($p) {
                                            return $c['parent_id'] == $p['id']; });
                                        if (count($subs) > 0):
                                            ?>
                                            <optgroup label="<?= htmlspecialchars($pName) ?>">
                                                <?php foreach ($subs as $child):
                                                    $cName = $isTr ? $child['name'] : ($child['name_en'] ?? $child['name']);
                                                    $isSelCat = (($_GET['category_id'] ?? '') == $child['id']) ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($child['id']) ?>" <?= $isSelCat ?>><?= htmlspecialchars($cName) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php else: 
                                            $isSelParent = (($_GET['category_id'] ?? '') == $p['id']) ? 'selected' : '';
                                        ?>
                                            <option value="<?= htmlspecialchars($p['id']) ?>" <?= $isSelParent ?>><?= htmlspecialchars($pName) ?></option>
                                        <?php endif; endforeach; ?>

                                    <?php
                                    // Orphan children if any
                                    $orphans = array_filter($children, function ($c) use ($parents) {
                                        return !array_filter($parents, function ($p) use ($c) {
                                            return $p['id'] == $c['parent_id']; });
                                    });
                                    foreach ($orphans as $o):
                                        $oName = $isTr ? $o['name'] : ($o['name_en'] ?? $o['name']);
                                        ?>
                                        <option value="<?= htmlspecialchars($o['id']) ?>"><?= htmlspecialchars($oName) ?></option>
                                    <?php endforeach; ?>
                                </select>

                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                // Active Filter Banner
                $activeFilterLabel = '';
                if (!empty($_GET['supplier_id'])) {
                    $sName = $pdo->query("SELECT name FROM asset_suppliers WHERE id = " . intval($_GET['supplier_id']))->fetchColumn();
                    if ($sName) $activeFilterLabel = ($isTr ? "Tedarikçi: " : "Supplier: ") . htmlspecialchars($sName);
                } elseif (isset($_GET['category_id']) && $_GET['category_id'] !== '') {
                    $catId = intval($_GET['category_id']);
                    $catRow = $pdo->prepare("SELECT id, name, name_en FROM asset_categories WHERE id = ? LIMIT 1");
                    $catRow->execute([$catId]);
                    $catInfo = $catRow->fetch(PDO::FETCH_ASSOC);

                    if ($catInfo) {
                        $preferredName = trim((string)($isTr ? ($catInfo['name'] ?? '') : ($catInfo['name_en'] ?? $catInfo['name'] ?? '')));
                        $fallbackName = trim((string)($catInfo['name_en'] ?? $catInfo['name'] ?? ''));
                        $displayName = $preferredName !== '' ? $preferredName : $fallbackName;

                        if ($displayName === '' || preg_match('/^\d+$/', $displayName)) {
                            $displayName = ($isTr ? 'Kategori' : 'Category') . ' #' . $catId;
                        }

                        $activeFilterLabel = ($isTr ? "Kategori: " : "Category: ") . htmlspecialchars($displayName);
                    } elseif ($catId > 0) {
                        $activeFilterLabel = ($isTr ? "Kategori: " : "Category: ") . '#' . $catId;
                    }
                } elseif (!empty($_GET['manufacturer_id'])) {
                    $mName = $pdo->query("SELECT name FROM asset_manufacturers WHERE id = " . intval($_GET['manufacturer_id']))->fetchColumn();
                    if ($mName) $activeFilterLabel = ($isTr ? "Üretici: " : "Manufacturer: ") . htmlspecialchars($mName);
                } elseif (!empty($_GET['model_id'])) {
                    $modName = $pdo->query("SELECT name FROM asset_models WHERE id = " . intval($_GET['model_id']))->fetchColumn();
                    if ($modName) $activeFilterLabel = ($isTr ? "Model: " : "Model: ") . htmlspecialchars($modName);
                } elseif (!empty($_GET['department_id'])) {
                    $dName = $pdo->query("SELECT bolum_adi FROM bolumler WHERE id = " . intval($_GET['department_id']))->fetchColumn();
                    if ($dName) $activeFilterLabel = ($isTr ? "Bölüm: " : "Department: ") . htmlspecialchars($dName);
                } elseif (!empty($_GET['company_id'])) {
                    $compName = $pdo->query("SELECT name FROM asset_companies WHERE id = " . intval($_GET['company_id']))->fetchColumn();
                    if ($compName) $activeFilterLabel = ($isTr ? "Şirket: " : "Company: ") . htmlspecialchars($compName);
                }
                ?>
                <?php if (!empty($activeFilterLabel)): ?>
                    <div class="alert alert-info d-flex align-items-center justify-content-between mx-4 mt-3 mb-2 shadow-sm rounded-lg" style="border:2px solid #2563eb; background: linear-gradient(135deg, #dbeafe 0%, #eff6ff 100%); color:#0f172a; padding: 14px 18px; min-height: 56px; font-size: 15px; line-height: 1.4; box-shadow: 0 4px 12px rgba(37,99,235,0.15);">
                        <div class="d-flex align-items-center flex-wrap" style="color:#0f172a;">
                            <i class="fas fa-filter text-primary mr-2" style="color:#1d4ed8; font-size: 16px;"></i>
                            <strong style="color:#0f172a; font-size: 15px;"><?= $isTr ? 'Aktif Filtre:' : 'Active Filter:' ?></strong>
                            <span class="ml-2" style="color:#0f172a; font-weight:600;"><?= $activeFilterLabel ?></span>
                        </div>
                        <a href="varliklar?view=<?= htmlspecialchars($view) ?>" class="btn btn-sm rounded-pill px-3 ml-3" style="white-space: nowrap; background: rgba(255,255,255,0.9); color:#0f172a; border: 2px solid #2563eb; font-weight: 600; box-shadow: inset 0 0 0 1px rgba(37,99,235,0.15);">
                            <i class="fas fa-times mr-1" style="color:#1d4ed8;"></i> <?= $isTr ? 'Filtreyi Temizle' : 'Clear Filter' ?>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($view == 'assets'): ?>
                <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="pl-4" style="width:30px;"><input type="checkbox" id="selectAll"></th>
                                    <th class="col-name"><?= __("asset_name") ?></th>
                                    <th class="col-company"><?= __("company") ?></th>
                                    <th class="col-image text-center"><?= __("device_image") ?></th>
                                    <th class="col-tag"><?= __("asset_tag") ?></th>
                                    <th class="col-serial"><?= __("serial_no") ?></th>
                                    <th class="col-manufacturer"><?= __("manufacturer") ?></th>
                                    <th class="col-supplier"><?= __("supplier") ?></th>
                                    <th class="col-model"><?= __("model") ?></th>
                                    <th class="col-category"><?= __("category") ?></th>
                                    <th class="col-status"><?= __("status") ?></th>
                                    <th class="col-user"><?= __("checked_out_to") ?></th>
                                    <th class="col-dept"><?= __("department") ?></th>
                                    <th class="col-checkout" style="width:130px;"><?= __("assignment") ?></th>
                                    <th class="text-right pr-4 col-actions"><?= __("actions") ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($assets)): ?>
                                    <tr>
                                        <td colspan="15" class="text-center py-5 text-muted"><?= __("no_assets_found") ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($assets as $ast):
                                        $specsText = '';
                                        if (!empty($ast['specs'])) {
                                            $specsDecoded = json_decode($ast['specs'], true);
                                            if (is_array($specsDecoded)) {
                                                array_walk_recursive($specsDecoded, function($val) use (&$specsText) {
                                                    if (is_scalar($val)) {
                                                        $specsText .= ' ' . $val;
                                                    }
                                                });
                                            }
                                        }
                                    ?>
                                        <tr data-id="<?= $ast['id'] ?>"
                                            data-category-id="<?= $ast['category_id'] ?>"
                                            data-search-terms="<?= htmlspecialchars(strtolower(implode(' ', array_filter([
                                                $ast['name'], 
                                                $ast['asset_tag'], 
                                                $ast['serial_no'], 
                                                $ast['ip_address'], 
                                                $ast['mac_address'], 
                                                $ast['model_name'], 
                                                $ast['assigned_user'], 
                                                $ast['assigned_username'], 
                                                $ast['company_name'], 
                                                $ast['dept_name'], 
                                                $ast['category_name'] ?? '',
                                                $specsText,
                                                $ast['status_label'], 
                                                $ast['manufacturer_name'] ?? '',
                                                $ast['supplier_name'] ?? '', 
                                                $ast['purchase_date'] ?? '', 
                                                $ast['custom_fields_text'] ?? '',
                                                $ast['notes'] ?? ''
                                            ])))) ?>"
                                            class="<?= (($_GET['highlight_id'] ?? 0) == $ast['id'] || ($_GET['category_id'] ?? 0) == $ast['category_id']) ? 'row-highlight-pulse' : '' ?>"
                                            id="item-<?= $ast['id'] ?>">
                                            <td class="pl-4"><input type="checkbox" class="selectItem" value="<?= $ast['id'] ?>">
                                            </td>
                                            <td class="pl-4 font-weight-bold col-name">
                                                <a href="varlik-detay/<?= e($ast['id']) ?>" class="text-primary text-decoration-none">
                                                    <?= e($ast['name']) ?>
                                                </a>
                                            </td>
                                            <td class="text-xs col-company">
                                                 <?php if (!empty($ast['company_id'])): ?>
                                                     <a href="varliklar?view=predefined&type=companies&highlight_id=<?= e($ast['company_id']) ?>" class="text-info font-weight-bold" title="<?= $isTr ? 'Şirket Tanımına Git' : 'View Company' ?>">
                                                         <?= e($ast['company_name']) ?>
                                                     </a>
                                                 <?php else: ?>
                                                     <span class="text-muted">—</span>
                                                 <?php endif; ?>
                                             </td>
                                            <td class="col-image text-center">
                                                <?php
                                                $raw_img = $ast['display_image'] ?? '';
                                                $final_path = "";
                                                if (!empty($raw_img)) {
                                                    $folder = 'assets'; 
                                                    if (strpos($raw_img, 'models-') === 0) $folder = 'models';
                                                    elseif (strpos($raw_img, 'categories-') === 0) $folder = 'categories';
                                                    elseif (strpos($raw_img, 'accessories-') === 0) $folder = 'accessories';
                                                    elseif (strpos($raw_img, 'consumables-') === 0) $folder = 'consumables';
                                                    elseif (strpos($raw_img, 'components-') === 0) $folder = 'components';
                                                    elseif (strpos($raw_img, 'licenses-') === 0) $folder = 'licenses';

                                                    if (strpos($raw_img, 'public/') === 0) $raw_img = substr($raw_img, 7);
                                                    
                                                    if (strpos($raw_img, 'uploads/') === 0) {
                                                        $final_path = "public/" . $raw_img;
                                                    } else {
                                                        $final_path = "public/uploads/" . $folder . "/" . $raw_img;
                                                    }
                                                }
                                                
                                                if (empty($final_path) && !empty($ast['category_image'])) {
                                                    $final_path = "public/uploads/categories/" . $ast['category_image'];
                                                }

                                                $final_img_with_v = getSafeImageUrl($final_path);
                                                ?>
                                                <?php if (!empty($final_img_with_v)): ?>
                                                    <img src="<?= htmlspecialchars($final_img_with_v) ?>" class="rounded border shadow-sm"
                                                         style="height:35px; width:35px; object-fit:contain; background:#fff;"
                                                         onerror="this.src=placeholderImg;">
                                                <?php else: ?>
                                                    <i class="fas fa-desktop text-muted opacity-50"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-xs col-tag"><a href="varlik-detay/<?= e($ast['id']) ?>" class="text-primary font-weight-bold"><?= e($ast['asset_tag'] ?: '—') ?></a></td>
                                            <td class="text-xs col-serial"><a href="varlik-detay/<?= e($ast['id']) ?>" class="text-muted hover-underline"><?= e($ast['serial_no'] ?: '—') ?></a></td>
                                            <td class="text-xs col-manufacturer">
                                                 <?php if (!empty($ast['manufacturer_id'])): ?>
                                                     <a href="varliklar?view=predefined&type=manufacturers&highlight_id=<?= e($ast['manufacturer_id']) ?>" class="text-info font-weight-bold" title="<?= $isTr ? 'Üretici Tanımına Git' : 'View Manufacturer' ?>">
                                                         <?= e($ast['manufacturer_name'] ?? '—') ?>
                                                     </a>
                                                 <?php else: ?>
                                                     <span class="text-muted">—</span>
                                                 <?php endif; ?>
                                             </td>
                                             <td class="text-xs col-supplier">
                                                 <?php if (!empty($ast['supplier_id'])): ?>
                                                     <a href="varliklar?view=predefined&type=suppliers&highlight_id=<?= e($ast['supplier_id']) ?>" class="text-info font-weight-bold" title="<?= $isTr ? 'Tedarikçi Tanımına Git' : 'View Supplier' ?>">
                                                         <?= e($ast['supplier_name'] ?? '—') ?>
                                                     </a>
                                                 <?php else: ?>
                                                     <span class="text-muted">—</span>
                                                 <?php endif; ?>
                                             </td>
                                            <td class="text-xs col-model">
                                                 <?php if (!empty($ast['model_id'])): ?>
                                                     <a href="varliklar?view=predefined&type=models&highlight_id=<?= e($ast['model_id']) ?>" class="text-info font-weight-bold" title="<?= $isTr ? 'Model Tanımına Git' : 'View Model' ?>">
                                                         <?= e($ast['model_name'] ?? $ast['type'] ?? '—') ?>
                                                     </a>
                                                 <?php else: ?>
                                                     <?= e($ast['model_name'] ?? $ast['type'] ?? '—') ?>
                                                 <?php endif; ?>
                                             </td>
                                            <td class="text-xs col-category">
                                                <?php
                                                $disName = $isTr ? ($ast['category_name'] ?? '—') : ($ast['category_name_en'] ?? $ast['category_name'] ?? '—');
                                                if (!empty($ast['category_id'])) {
                                                    echo '<a href="varliklar?view=predefined&type=categories&highlight_id=' . e($ast['category_id']) . '" class="text-info font-weight-bold" title="' . ($isTr ? 'Kategori Tanımına Git' : 'View Category') . '">' . htmlspecialchars($disName) . '</a>';
                                                } else {
                                                    echo htmlspecialchars($disName);
                                                }
                                                ?>
                                            </td>
                                            <td class="col-status">
                                                <?php
                                                // Unified status translation logic
                                                $s_raw = !empty($ast['status_label']) ? $ast['status_label'] : ($ast['status'] ? 'Ready' : 'Passive');
                                                $trans_map = [
                                                    'Hazır' => ['tr' => 'Hazır', 'en' => 'Ready'],
                                                    'Ready' => ['tr' => 'Hazır', 'en' => 'Ready'],
                                                    'Arızalı' => ['tr' => 'Arızalı', 'en' => 'Faulty'],
                                                    'Faulty' => ['tr' => 'Arızalı', 'en' => 'Faulty'],
                                                    'Hurda' => ['tr' => 'Hurda', 'en' => 'Scrap'],
                                                    'Scrap' => ['tr' => 'Hurda', 'en' => 'Scrap'],
                                                    'Hurdaya Ayrılmış' => ['tr' => 'Hurda', 'en' => 'Scrap'],
                                                    'Atanmış' => ['tr' => 'Hazır', 'en' => 'Ready'],
                                                    'Assigned' => ['tr' => 'Hazır', 'en' => 'Ready'],
                                                    'İmza Bekliyor' => ['tr' => 'İmza Bekliyor', 'en' => 'Pending Signature'],
                                                    'Pending Signature' => ['tr' => 'İmza Bekliyor', 'en' => 'Pending Signature'],
                                                    'Beklemede' => ['tr' => 'Beklemede', 'en' => 'Pending'],
                                                    'Pending' => ['tr' => 'Beklemede', 'en' => 'Pending']
                                                ];
                                                
                                                if (isset($trans_map[$s_raw])) {
                                                    $s_text = $isTr ? $trans_map[$s_raw]['tr'] : $trans_map[$s_raw]['en'];
                                                } else {
                                                    $s_text = __($s_raw);
                                                }
                                                
                                                $s_color = !empty($ast['status_color']) ? $ast['status_color'] : ($ast['status'] ? '#10b981' : '#ef4444');

                                                $is_assigned = !empty($ast['assigned_user_id']) || !empty($ast['asset_id']);
                                                if ($is_assigned && !$show_deleted) {
                                                    $s_color = '#007bff'; // Blue for assigned
                                                }
                                                $is_scrap = (stripos($s_text, 'hurda') !== false || stripos($s_text, 'scrap') !== false);
                                                $is_broken = (stripos($s_text, 'arızalı') !== false || stripos($s_text, 'faulty') !== false || stripos($s_text, 'broken') !== false);
                                                
                                                if ($is_scrap) {
                                                    $s_color = '#111827'; // Near black for Scrap
                                                }
                                                ?>
                                                <div class="d-flex align-items-center">
                                                    <a href="varlik-detay/<?= e($ast['id']) ?>" class="text-xs <?= $is_scrap ? 'status-scrap' : '' ?> text-decoration-none hover-underline" style="color: <?= $s_color ?>; font-weight:700;">
                                                        <i class="fas fa-circle mr-1" style="font-size:8px;"></i>
                                                        <?= htmlspecialchars($s_text) ?>
                                                    </a>
                                                    <?php if ($is_assigned && !$show_deleted): ?>
                                                        <span class="badge badge-light-primary text-xs ml-1"
                                                            style="font-size:10px; padding: 2px 5px; color:#007bff; background: rgba(0,123,255,0.1); border: 1px solid rgba(0,123,255,0.2);"><?= $isTr ? 'Atanmış' : 'Assigned' ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="col-user">
                                                <?php if ($ast['assigned_user']): ?>
                                                    <div class="text-xs font-weight-bold">
                                                        <a href="kullanici-detay/<?= $ast['assigned_user_id'] ?>"
                                                            class="text-primary text-decoration-none">
                                                            <i
                                                                class="fas fa-user-circle mr-1 opacity-75"></i><?= htmlspecialchars($ast['assigned_user']) ?>
                                                        </a>
                                                    </div>
                                                <?php elseif (!empty($ast['asset_id'])): ?>
                                                    <div class="text-xs font-weight-bold">
                                                        <a href="varlik-detay/<?= $ast['asset_id'] ?>?view=assets"
                                                            class="text-info text-decoration-none">
                                                            <i
                                                                class="fas fa-desktop mr-1 opacity-75"></i><?= htmlspecialchars($ast['assigned_asset_name'] ?? 'Device') ?>
                                                        </a>
                                                <?php else: ?>
                                                    <span class="text-muted text-xs">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-xs col-dept">
                                                 <?php if (!empty($ast['department_id'])): ?>
                                                     <a href="varliklar?view=assets&department_id=<?= $ast['department_id'] ?>" class="text-info font-weight-bold">
                                                         <?= htmlspecialchars($ast['dept_name']) ?>
                                                     </a>
                                                 <?php else: ?>
                                                     <span class="text-muted">—</span>
                                                 <?php endif; ?>
                                             </td>

                                             <td class="col-checkout">
                                                 <?php if (!$show_deleted && in_array($current_user_role, [1, 3])): ?>
                                                     <?php if ($is_scrap || $is_broken): ?>
                                                         <button class="btn-action-disabled" type="button" disabled>
                                                             <i class="fas fa-ban"></i> <span><?= $isTr ? 'İşlem Kapalı' : 'Disabled' ?></span>
                                                         </button>
                                                     <?php else: ?>
                                                         <?php if ($ast['assigned_user_id'] || $ast['asset_id']): ?>
                                                             <button class="btn-action-checkin" type="button"
                                                                     onclick='checkInItem(<?= $ast['id'] ?>, "assets", "<?= $ast['assigned_user_id'] ? "user" : "asset" ?>", <?= json_encode($ast['assigned_user_id'] ? $ast['assigned_user'] : ($ast['assigned_asset_name'] ?? 'Device'), JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>)'>
                                                                 <i class="fas fa-undo"></i> <span><?= $isTr ? 'İade Al' : 'Return' ?></span>
                                                             </button>
                                                         <?php else: ?>
                                                             <button class="btn-action-checkout" type="button"
                                                                     onclick="checkOutItem(<?= $ast['id'] ?>, 'assets')">
                                                                 <i class="fas fa-share"></i> <span><?= $isTr ? 'Zimmetle' : 'Assign' ?></span>
                                                             </button>
                                                         <?php endif; ?>
                                                     <?php endif; ?>
                                                 <?php else: ?>
                                                     <span class="text-muted small">—</span>
                                                 <?php endif; ?>
                                             </td>

                                            <td class="text-right pr-4 col-actions" style="white-space: nowrap;">
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-light border dropdown-toggle px-3 shadow-sm" type="button" 
                                                            style="border-radius:10px; font-weight:600;"
                                                            id="actions-ast-<?= $ast['id'] ?>" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" data-boundary="window">
                                                        <i class="fas fa-ellipsis-v mr-1 small"></i> <?= $isTr ? 'İşlemler' : 'Actions' ?>
                                                    </button>
                                                    <div class="dropdown-menu dropdown-menu-right shadow border-0 p-1" style="border-radius:12px; min-width: 180px; z-index: 9999 !important;">
                                                        <?php if ($show_deleted): ?>
                                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick='confirmRestore(<?= $ast["id"] ?>, "assets")'>
                                                                <i class="fas fa-trash-restore mr-2 text-success" style="width:20px;"></i> <?= __("restore") ?>
                                                            </a>
                                                            <div class="dropdown-divider"></div>
                                                            <a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick='confirmPermanentDelete(<?= $ast["id"] ?>, "assets")'>
                                                                <i class="fas fa-eraser mr-2" style="width:20px;"></i> <?= $isTr ? 'Kalıcı Olarak Sil' : 'Permanently Delete' ?>
                                                            </a>
                                                        <?php else: ?>

                                                            <div class="dropdown-divider"></div>
                                                            
                                                            <?php if ($can_edit_varliklar): ?>
                                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick='editAsset(<?= json_encode($ast, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>)'>
                                                                <i class="fas fa-edit mr-2 text-primary" style="width:20px;"></i> <?= __("edit") ?>
                                                            </a>
                                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick='cloneAsset(<?= json_encode($ast, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>)'>
                                                                <i class="fas fa-copy mr-2 text-info" style="width:20px;"></i> <?= $isTr ? 'Kopyala' : 'Copy' ?>
                                                            </a>
                                                            <?php endif; ?>
                                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick='showTimeline(<?= $ast["id"] ?>, "asset")'>
                                                                <i class="fas fa-history mr-2 text-warning" style="width:20px;"></i> <?= __("history") ?>
                                                            </a>
                                                            
                                                            <?php if (s('inventory_enable_qr_labels') === '1' && $can_edit_varliklar):
                                                                $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
                                                                $basePath = rtrim(str_replace('/public', '', dirname($scriptName)), '/\\');
                                                                $bU = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]" . $basePath;
                                                                $qL = $bU . "/cihaz/izle/" . ($ast['public_token'] ?? '') . "?lang=" . ($_SESSION['lang'] ?? 'tr');
                                                                $qI = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" . urlencode($qL); ?>
                                                                <a class="dropdown-item py-2" href="<?= $qI ?>" target="_blank">
                                                                    <i class="fas fa-qrcode mr-2 text-dark" style="width:20px;"></i> <?= $isTr ? 'QR Etiketi' : 'QR Label' ?>
                                                                </a>
                                                            <?php endif; ?>

                                                            <?php if ($can_edit_varliklar): ?>
                                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick='confirmScrap(<?= $ast["id"] ?>, "assets")'>
                                                                <i class="fas fa-dumpster mr-2 text-danger" style="width:20px;"></i> <?= $isTr ? 'Hurdaya Ayır' : 'Move to Scrap' ?>
                                                            </a>

                                                            <div class="dropdown-divider"></div>
                                                            <?php
                                                            $linked_items = [];
                                                            if (($ast['linked_accessories_count'] ?? 0) > 0) $linked_items[] = $ast['linked_accessories_count'] . " " . ($isTr ? "Aksesuar" : "Accessories");
                                                            if (($ast['linked_consumables_count'] ?? 0) > 0) $linked_items[] = $ast['linked_consumables_count'] . " " . ($isTr ? "Sarf Malzeme" : "Consumables");
                                                            if (($ast['linked_components_count'] ?? 0) > 0) $linked_items[] = $ast['linked_components_count'] . " " . ($isTr ? "Parça" : "Components");
                                                            $linked_html = implode(', ', $linked_items);
                                                            ?>
                                                            <a class="dropdown-item py-2 text-danger" href="javascript:void(0)" 
                                                               onclick='confirmDelete(<?= $ast["id"] ?>, "<?= htmlspecialchars($ast["assigned_user"] ?: ($ast["assigned_asset_name"] ?? "")) ?>", "<?= addslashes($linked_html) ?>", "<?= addslashes($ast["name"]) ?>", "assets")'>
                                                                <i class="fas fa-trash-alt mr-2" style="width:20px;"></i> <?= __("delete") ?>
                                                            </a>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($view == 'people'): ?>
                    <div class="row m-0">
                        <!-- Left Sidebar for People Filters -->
                        <div class="col-md-3 p-0 border-right bg-light min-vh-50">
                            <div class="p-4">
                                <h6 class="font-weight-bold text-dark mb-3"><i
                                        class="fas fa-filter mr-2"></i><?= __("Kişi Filtreleri") ?></h6>

                                <div class="mb-4">
                                    <label
                                        class="small font-weight-bold text-muted text-uppercase"><?= __("Rol Bazlı") ?></label>
                                    <div class="list-group list-group-flush shadow-sm rounded-lg overflow-hidden border">
                                        <a href="varliklar?view=people"
                                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= !isset($_GET['role']) ? 'active bg-primary' : '' ?>">
                                            <span><i class="fas fa-users mr-2 opacity-50"></i><?= __("Hepsi") ?></span>
                                        </a>
                                        <a href="varliklar?view=people&role=1"
                                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= ($_GET['role'] ?? '') == '1' ? 'active bg-primary' : '' ?>">
                                            <span><i
                                                    class="fas fa-user-shield mr-2 opacity-50"></i><?= __("Süper Admin") ?></span>
                                        </a>
                                        <a href="varliklar?view=people&role=3"
                                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= ($_GET['role'] ?? '') == '3' ? 'active bg-primary' : '' ?>">
                                            <span><i class="fas fa-user-cog mr-2 opacity-50"></i><?= __("Admin") ?></span>
                                        </a>
                                        <a href="varliklar?view=people&role=2"
                                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= ($_GET['role'] ?? '') == '2' ? 'active bg-primary' : '' ?>">
                                            <span><i
                                                    class="fas fa-user mr-2 opacity-50"></i><?= __("Normal Kullanıcı") ?></span>
                                        </a>
                                    </div>
                                </div>

                                <div>
                                    <label
                                        class="small font-weight-bold text-muted text-uppercase"><?= __("Durum") ?></label>
                                    <div class="list-group list-group-flush shadow-sm rounded-lg overflow-hidden border">
                                        <a href="varliklar?view=people&status=active"
                                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= ($_GET['status'] ?? '') == 'active' ? 'active bg-success text-white' : '' ?>">
                                            <span><i
                                                    class="fas fa-check-circle mr-2 opacity-50"></i><?= __("Aktifler") ?></span>
                                        </a>
                                        <a href="varliklar?view=people&status=passive"
                                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= ($_GET['status'] ?? '') == 'passive' ? 'active bg-secondary text-white' : '' ?>">
                                            <span><i
                                                    class="fas fa-times-circle mr-2 opacity-50"></i><?= __("Pasifler") ?></span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Content Area -->
                        <div class="col-md-9 p-0 bg-white">
                            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 font-weight-bold text-dark"><i
                                        class="fas fa-user-friends mr-2"></i><?= __("Kullanıcı Listesi") ?> <span
                                        class="badge badge-light ml-2 text-dark"><?= count($items) ?></span></h6>
                                <button class="btn btn-sm btn-primary px-3 shadow-sm"
                                    style="border-radius:10px; font-weight:600;" onclick="location.href='kullanicilar'">
                                    <i class="fas fa-user-plus mr-1"></i>
                                    <?= $isTr ? 'Kullanıcı Yönetimi' : 'User Management' ?>
                                </button>
                            </div>
                            <table class="table table-hover mb-0">
                                <thead class="bg-light text-xs text-uppercase text-muted">
                                    <tr>
                                        <th class="pl-4"><?= __("fullname") ?></th>
                                        <th><?= __("username") ?></th>
                                        <th><?= __("role") ?></th>
                                        <th><?= __("status") ?></th>
                                        <th class="text-right pr-4"><?= __("details") ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($items)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5 text-muted"><?= __("no_users_found") ?></td>
                                        </tr>
                                    <?php else:
                                        foreach ($items as $u): ?>
                                            <tr>
                                                <td class="pl-4">
                                                    <div class="d-flex align-items-center">
                                                        <div class="bg-primary text-white rounded-circle mr-3 d-flex align-items-center justify-content-center"
                                                            style="width:36px; height:36px; font-weight:700;">
                                                            <?= strtoupper(substr($u['fullname'] ?? '?', 0, 1)) ?>
                                                        </div>
                                                        <div class="font-weight-bold"><?= e($u['fullname'] ?? '—') ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-dark small font-weight-bold">
                                                        <?= e($u['username'] ?? '—') ?></div>
                                                    <div class="text-muted" style="font-size:11px;">
                                                        <?= e($u['email'] ?? '—') ?></div>
                                                </td>
                                                <td>
                                                    <?php $r = $u['role'] ?? 2; ?>
                                                    <?php if ($r == 1): ?>
                                                        <span class="badge badge-soft-danger"><i class="fas fa-crown mr-1"></i> Süper
                                                            Admin</span>
                                                    <?php elseif ($r == 3): ?>
                                                        <span class="badge badge-soft-warning"><i class="fas fa-user-cog mr-1"></i>
                                                            Admin</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-soft-primary"><i class="fas fa-user mr-1"></i>
                                                            Personel</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (($u['status'] ?? 0) == 1): ?>
                                                        <span class="text-success small font-weight-bold"><i
                                                                class="fas fa-check-circle mr-1"></i> <?= __("active") ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted small font-weight-bold"><i
                                                                class="fas fa-times-circle mr-1"></i> <?= __("passive") ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-right pr-4">
                                                    <div class="d-flex justify-content-end">
                                                        <a href="kullanici-detay/<?= $u['id'] ?>" class="action-btn action-btn-copy"
                                                            title="<?= __("details") ?>">
                                                            <i class="fas fa-external-link-alt"></i>
                                                        </a>
                                                        <a href="kullanicilar?action=edit&id=<?= $u['id'] ?>"
                                                            class="action-btn action-btn-edit" title="<?= __("edit") ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php elseif ($view === 'timeline'): ?>
                    <script>window.location = 'raporlar?view=activity';</script>
                    <?php exit; ?>
                <?php elseif (false): // Removed unreachable old timeline block ?>
                    <?php
                    $where_t = " WHERE at.is_deleted = 0 ";
                    $params_t = [];

                    $q_search = trim($_GET['search'] ?? '');
                    if (!empty($q_search)) {
                        $where_t .= " AND (at.event_description LIKE ? OR u.fullname LIKE ?) ";
                        $params_t[] = "%$q_search%";
                        $params_t[] = "%$q_search%";
                    }

                    $total_records = $pdo->prepare("SELECT COUNT(*) FROM asset_timeline at LEFT JOIN users u ON at.user_id = u.id $where_t");
                    $total_records->execute($params_t);
                    $total_records = $total_records->fetchColumn();
                    $total_pages = ceil($total_records / $limit);

                    $page_logs = $pdo->prepare("SELECT at.*, u.fullname as performer, u.id as u_id 
                                                   FROM asset_timeline at 
                                                   LEFT JOIN users u ON at.user_id = u.id 
                                                   $where_t 
                                                   ORDER BY at.created_at DESC 
                                                   LIMIT $limit OFFSET $offset");
                    $page_logs->execute($params_t);
                    $page_logs = $page_logs->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="ds-card p-0 mt-3" style="border-radius:15px; overflow:hidden;">
                        <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center bg-white">
                            <h6 class="m-0 font-weight-bold text-dark"><i class="fas fa-history mr-2 text-warning"></i>
                                <?= $isTr ? 'Envanter Hareket Geçmişi' : 'Inventory Activity History' ?></h6>
                            <div class="d-flex align-items-center">
                                <button class="btn btn-sm btn-outline-danger mr-2 d-none" id="btnDeleteLogs"
                                    onclick="deleteSelectedLogs()"><i class="fas fa-trash-alt mr-1"></i> Seçilenleri
                                    Sil</button>
                                <span class="badge badge-light px-3 py-2" style="border-radius:20px;"><?= $total_records ?>
                                    <?= $isTr ? 'Kayıt' : 'Logs' ?></span>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle text-sm ds-table">
                                <thead class="bg-light text-xs text-uppercase text-muted">
                                    <tr>
                                        <th class="pl-4" style="width:30px;"><input type="checkbox" id="selectAllLogs"></th>
                                        <th>Simge</th>
                                        <th><?= __('date') ?></th>
                                        <th><?= __('user') ?></th>
                                        <th><?= __('action') ?></th>
                                        <th><?= $isTr ? 'Ürün / Tür' : 'Product / Type' ?></th>
                                        <th><?= __('details') ?></th>
                                        <th class="pr-4"><?= $isTr ? 'Değişti' : 'Changed' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($page_logs)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-5 text-muted">
                                                <?= $isTr ? 'Henüz bir hareket kaydı bulunmuyor.' : 'No activity logs found.' ?>
                                            </td>
                                        </tr>
                                    <?php else:
                                        foreach ($page_logs as $log):
                                            $icon = 'fa-info-circle text-info';
                                            if ($log['event_type'] == 'created')
                                                $icon = 'fa-plus-circle text-success';
                                            elseif ($log['event_type'] == 'updated')
                                                $icon = 'fa-edit text-primary';
                                            elseif ($log['event_type'] == 'deleted')
                                                $icon = 'fa-trash text-danger';
                                            elseif ($log['event_type'] == 'checkout')
                                                $icon = 'fa-user-check text-warning';
                                            elseif ($log['event_type'] == 'checkin')
                                                $icon = 'fa-undo text-secondary';

                                            $desc = $log['event_description'];
                                            $changed = '';
                                            if (strpos($desc, 'Güncellenenler: ') !== false) {
                                                $parts = explode('Güncellenenler: ', $desc);
                                                $desc = $parts[0];
                                                $changed = $parts[1];
                                            } elseif (strpos($desc, 'Changes: ') !== false) {
                                                $parts = explode('Changes: ', $desc);
                                                $desc = $parts[0];
                                                $changed = $parts[1];
                                            }
                                            ?>
                                            <tr>
                                                <td class="pl-4"><input type="checkbox" class="selectLogItem"
                                                        value="<?= $log['id'] ?>"></td>
                                                <td><i class="fas <?= $icon ?> fa-lg"></i></td>
                                                <td class="text-nowrap"><?= date('d.m.Y H:i', strtotime($log['created_at'])) ?></td>
                                                <td class="font-weight-bold text-primary">
                                                    <?= htmlspecialchars($log['performer'] ?: 'Sistem') ?></td>
                                                <?php
                                                $inv_label = match($log['event_type']) {
                                                    'checkout' => $isTr ? 'ATANDI'      : 'ASSIGNED',
                                                    'checkin'  => $isTr ? 'GERİ ALINDI' : 'RETURNED',
                                                    'created'  => $isTr ? 'OLUŞTURULDU' : 'CREATED',
                                                    'updated'  => $isTr ? 'GÜNCELLENDİ': 'UPDATED',
                                                    'deleted'  => $isTr ? 'SİLİNDİ'    : 'DELETED',
                                                    default    => strtoupper($log['event_type'])
                                                };
                                                ?>
                                                <td><span
                                                        class="badge badge-light border px-2 py-1"><?= $inv_label ?></span>
                                                </td>
                                                <td>
                                                    <div class="font-weight-600"><?= htmlspecialchars($log['item_type']) ?></div>
                                                    <small class="text-muted">ID: <?= $log['asset_id'] ?></small>
                                                </td>
                                                <td style="max-width:300px;"><?= htmlspecialchars($desc) ?></td>
                                                <td class="pr-4 small text-muted"><?= htmlspecialchars($changed) ?></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <div
                            class="mt-3 d-flex justify-content-between align-items-center bg-white p-3 rounded shadow-sm border">
                            <div class="small text-muted">
                                <?= $isTr ? "$total_records kayıttan " . (($offset) + 1) . "-" . min($offset + $limit, $total_records) . " arası gösteriliyor." : "Showing " . (($offset) + 1) . "-" . min($offset + $limit, $total_records) . " of $total_records records." ?>
                            </div>
                            <nav>
                                <ul class="pagination pagination-sm m-0">
                                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>"><a class="page-link"
                                            href="?view=timeline&page=<?= $page - 1 ?>&search=<?= urlencode($q_search) ?>"><?= $isTr ? 'Önceki' : 'Prev' ?></a>
                                    </li>
                                    <?php for ($i = 1; $i <= $total_pages; $i++):
                                        if ($i < 4 || $i > $total_pages - 3 || ($i >= $page - 1 && $i <= $page + 1)): ?>
                                            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>"><a class="page-link"
                                                    href="?view=timeline&page=<?= $i ?>&search=<?= urlencode($q_search) ?>"><?= $i ?></a>
                                            </li>
                                        <?php elseif ($i == 4 || $i == $total_pages - 3): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; endfor; ?>
                                    <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>"><a class="page-link"
                                            href="?view=timeline&page=<?= $page + 1 ?>&search=<?= urlencode($q_search) ?>"><?= $isTr ? 'Sonraki' : 'Next' ?></a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                <?php elseif ($view == 'signatures'): 
                    $isCurrentAdmin = in_array($current_user_role, [1, 3]);
                ?>
                    <div class="card shadow-sm border-0" style="border-radius:15px; overflow:hidden;">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 font-weight-bold"><i class="fas fa-file-signature mr-2 text-warning"></i> <?= $isTr ? 'Zimmet Onaylarım' : 'My Approvals' ?></h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light text-muted small text-uppercase font-weight-bold">
                                        <tr>
                                            <th class="pl-4"><?= $isTr ? 'Envanter' : 'Asset' ?></th>
                                            <th><?= $isTr ? 'Etiket' : 'Tag' ?></th>
                                            <th><?= $isTr ? 'Kategori' : 'Category' ?></th>
                                            <?php if ($isCurrentAdmin): ?>
                                            <th><?= $isTr ? 'Personel' : 'Personnel' ?></th>
                                            <?php endif; ?>
                                            <th><?= $isTr ? 'Durum' : 'Status' ?></th>
                                            <th><?= $isTr ? 'Tarih' : 'Date' ?></th>
                                            <th class="text-right pr-4"><?= $isTr ? 'İşlemler' : 'Actions' ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        if (empty($items)): ?>
                                            <tr>
                                                <td colspan="<?= $isCurrentAdmin ? 7 : 6 ?>" class="text-center py-5">
                                                    <div class="text-success mb-2"><i class="fas fa-check-circle fa-2x"></i></div>
                                                    <div class="font-weight-bold text-dark"><?= $isTr ? 'Bekleyen imza bulunmuyor.' : 'No pending signatures.' ?></div>
                                                    <small class="text-muted"><?= $isTr ? 'Tüm zimmet işlemleri tamamlandı.' : 'All assignment processes are complete.' ?></small>
                                                </td>
                                            </tr>
                                        <?php else: foreach($items as $item):
                                            $isPendingAdminForMe = ($item['status'] === 'pending_admin' && $isCurrentAdmin);
                                            $isPendingUserForMe  = ($item['status'] === 'pending_user'  && !$isCurrentAdmin && $item['personnel_user_id'] == $current_user_id);
                                            $isPendingUserInfo   = ($item['status'] === 'pending_user'  && $isCurrentAdmin); // Admin gorur bilgi icin
                                        ?>
                                            <tr id="sig-row-<?= $item['item_type'] ?>-<?= $item['asset_id'] ?>" class="<?= $isPendingAdminForMe ? 'table-warning' : '' ?>">
                                                <td class="pl-4">
                                                    <div class="font-weight-bold text-dark"><?= e($item['asset_name']) ?></div>
                                                    <small class="text-muted"><?= e($item['model_name']) ?></small>
                                                    <?php if ($item['action_type'] === 'checkin'): ?>
                                                    <span class="badge badge-light border" style="font-size:10px;"><i class="fas fa-undo mr-1 text-info"></i><?= $isTr ? 'İade' : 'Return' ?></span>
                                                    <?php else: ?>
                                                    <span class="badge badge-light border" style="font-size:10px;"><i class="fas fa-share mr-1 text-success"></i><?= $isTr ? 'Zimmet' : 'Checkout' ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="badge badge-light border font-weight-normal"><?= e($item['asset_tag']) ?></span></td>
                                                <td class="small"><?= e($item['category_name']) ?></td>
                                                <?php if ($isCurrentAdmin): ?>
                                                <td>
                                                    <?php if (!empty($item['personnel_name'])): ?>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (!empty($item['personnel_avatar']) && $item['personnel_avatar'] !== 'default.png'): ?>
                                                            <?php 
                                                            $pImg = $item['personnel_avatar'];
                                                             if (strpos($pImg, 'http') === 0) {
                                                                 $imgSrc = $pImg;
                                                             } elseif (strpos($pImg, 'dist/img/avatars/') !== false) {
                                                                 $imgSrc = preg_replace('/^.*(dist\/img\/avatars\/[a-zA-Z0-9_\-\.]+)/', '', $pImg);
                                                             } else {
                                                                 $imgSrc = 'uploads/profil/' . $pImg;
                                                             }
                                                            ?>
                                                            <img src="<?= $imgSrc ?>" class="rounded-circle shadow-sm mr-2" style="width:28px; height:28px; object-fit:cover; border:1px solid #dee2e6; background:#f8fafc;" onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.setProperty('display', 'flex', 'important');">
                                                        <?php endif; ?>
                                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mr-2" style="width:28px;height:28px;font-size:11px;font-weight:700;flex-shrink:0;<?= (!empty($item['personnel_avatar']) && $item['personnel_avatar'] !== 'default.png') ? 'display:none !important;' : '' ?>">
                                                            <?= strtoupper(mb_substr($item['personnel_name'], 0, 1)) ?>
                                                        </div>
                                                        <span class="small font-weight-600"><?= e($item['personnel_name']) ?></span>
                                                    </div>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php endif; ?>
                                                <td>
                                                    <?php 
                                                    $isOwnerBadge = ((int)$item['personnel_user_id'] === (int)$current_user_id);
                                                    if ($item['status'] === 'pending_user' && ($isOwnerBadge || !$isCurrentAdmin)): ?>
                                                        <!-- Personel (veya zimmet sahibi admin): imzalaması lazım -->
                                                        <span class="badge px-3 py-1" style="color:#d97706; background:#fef3c7; border-radius:8px;">
                                                            <i class="fas fa-pen mr-1"></i> <?= $isTr ? 'İmzanız Bekleniyor' : 'Your Signature Needed' ?>
                                                        </span>
                                                    <?php elseif ($item['status'] === 'pending_admin' && $isCurrentAdmin): ?>
                                                        <!-- Admin: iade/zimmet, imzası gerekli -->
                                                        <span class="badge px-3 py-1" style="color:#7c3aed; background:#ede9fe; border-radius:8px;">
                                                            <i class="fas fa-user-shield mr-1"></i> <?= $isTr ? 'Yönetici Onayı Lazım' : 'Supervisor Approval Required' ?>
                                                            <small class="d-block" style="font-size:10px;"><?= $isTr ? '(Personel imzaladı)' : '(Personnel signed)' ?></small>
                                                        </span>
                                                    <?php elseif ($item['status'] === 'pending_admin' && !$isCurrentAdmin): ?>
                                                        <!-- Personel icin: admin imzası bekleniyor -->
                                                        <span class="badge px-3 py-1" style="color:#0369a1; background:#e0f2fe; border-radius:8px;">
                                                            <i class="fas fa-shield-alt mr-1"></i> <?= $isTr ? 'Admin Onayı Bekleniyor' : 'Waiting for Admin' ?>
                                                            <small class="d-block" style="font-size:10px;"><?= $isTr ? '(İmzanız alındı ✓)' : '(You signed ✓)' ?></small>
                                                        </span>
                                                    <?php elseif ($item['status'] === 'approved'): ?>
                                                        <span class="badge px-3 py-1" style="color:#059669; background:#dcfce7; border-radius:8px;">
                                                            <i class="fas fa-check-circle mr-1"></i> <?= $isTr ? 'Onaylandı' : 'Approved' ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge px-3 py-1" style="color:#d97706; background:#fef3c7; border-radius:8px;">
                                                            <i class="fas fa-clock mr-1"></i> <?= $isTr ? 'Bekliyor' : 'Pending' ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-muted small">
                                                    <?php 
                                                    $displayDate = null;
                                                    if ($item['status'] === 'approved') {
                                                        $displayDate = $item['admin_signed_at'] ?? $item['signed_at'] ?? $item['created_at'];
                                                    } elseif ($item['status'] === 'pending_admin') {
                                                        $displayDate = $item['signed_at'] ?? $item['created_at'];
                                                    } else {
                                                        $displayDate = $item['created_at'];
                                                    }
                                                    echo $displayDate ? date('d.m.Y H:i', strtotime($displayDate)) : '-';
                                                    ?>
                                                </td>
                                                <td class="text-right pr-4">
                                                    <?php 
                                                    // Kişi hem admin hem bu zimmetin sahibi mi?
                                                    $isOwner = ((int)$item['personnel_user_id'] === (int)$current_user_id);
                                                    ?>
                                                    <?php if ($item['status'] === 'pending_user' && ($isOwner || !$isCurrentAdmin)): ?>
                                                        <!-- Personel (veya zimmet sahibi admin): imzalama butonu -->
                                                        <button class="btn btn-sm btn-warning px-4 shadow-sm" 
                                                            onclick="approveSignature(<?= $item['asset_id'] ?>, '<?= $item['item_type'] ?>', 'pending_user', '<?= $item['action_type'] ?>', <?= $item['signature_row_id'] ?>)" 
                                                            style="border-radius:10px; font-weight:700; font-size:13px;">
                                                            <i class="fas fa-pen-nib mr-1"></i> <?= $isTr ? 'İmzala' : 'Sign' ?>
                                                        </button>
                                                    <?php elseif ($item['status'] === 'pending_user' && $isCurrentAdmin && !$isOwner): ?>
                                                        <!-- Admin: başka personelin imzalaması bekleniyor -->
                                                        <span class="text-muted small d-flex align-items-center justify-content-end">
                                                            <i class="fas fa-hourglass-half mr-1 text-warning"></i>
                                                            <?= $isTr ? 'Personel imzası bekleniyor' : 'Waiting for personnel' ?>
                                                        </span>
                                                    <?php elseif ($item['status'] === 'pending_admin' && $isCurrentAdmin): ?>
                                                        <!-- Admin: iade/zimmet, imzasını atıyor -->
                                                        <button class="btn btn-sm px-4 shadow-sm" 
                                                            onclick="approveSignature(<?= $item['asset_id'] ?>, '<?= $item['item_type'] ?>', 'pending_admin', '<?= $item['action_type'] ?>', <?= $item['signature_row_id'] ?>)" 
                                                            style="border-radius:10px; background:#7c3aed; color:white; border:none; font-weight:700; font-size:13px;">
                                                            <i class="fas fa-user-shield mr-1"></i> <?= $isTr ? 'Yönetici İmzası' : 'Supervisor Sign' ?>
                                                        </button>
                                                    <?php elseif ($item['status'] === 'pending_admin' && !$isCurrentAdmin): ?>
                                                        <!-- Personel: admin onayı bekleniyor -->
                                                        <span class="text-muted small d-flex align-items-center justify-content-end">
                                                            <i class="fas fa-shield-alt mr-1 text-info"></i>
                                                            <?= $isTr ? 'Admin onayı bekleniyor' : 'Waiting for admin' ?>
                                                        </span>
                                                    <?php elseif ($item['status'] === 'approved'): ?>
                                                        <?php if (!empty($item['attachment_id'])): ?>
                                                            <a href="dashboard?route=view_attachment&id=<?= $item['attachment_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary px-3" style="border-radius:8px;">
                                                                <i class="fas fa-file-pdf mr-1"></i> <?= $isTr ? 'Belgeyi Gör' : 'View Doc' ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="varlik-detay/<?= $item['asset_id'] ?>?view=<?= $item['item_type'] ?>s#tab-attachments" class="btn btn-sm btn-outline-primary px-3" style="border-radius:8px;">
                                                                <i class="fas fa-file-pdf mr-1"></i> <?= $isTr ? 'Belgeyi Gör' : 'View Doc' ?>
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php elseif ($view == 'predefined'): ?>
                    <!-- Predefined Views (Categories, Manufacturers, etc.) -->
                    <?php
                    $predefinedType = $_GET['type'] ?? 'categories';
                    $predefinedLabels = [
                        'categories' => $isTr ? 'Kategoriler' : __('category'),
                        'models' => $isTr ? 'Modeller' : __('model'),
                        'manufacturers' => $isTr ? 'Üreticiler' : __('manufacturer'),
                        'suppliers' => $isTr ? 'Tedarikçiler' : __('supplier'),
                        'companies' => $isTr ? 'Şirketler' : __('company'),
                        'departments' => $isTr ? 'Bölümler' : __('department'),
                        'locations' => $isTr ? 'Konumlar' : __('location'),
                        'status_labels' => $isTr ? 'Durum Etiketleri' : __('status_labels'),
                        'depreciation' => $isTr ? 'Amortisman' : __('depreciation'),
                        'custom_fields' => $isTr ? 'Özel Alanlar (Custom Fields)' : 'Özel Alan Tanımlama (Custom Fields)',
                    ];
                    $predefinedLabel = $predefinedLabels[$predefinedType] ?? ($isTr ? 'Kategori' : __('category'));
                    ?>
                    <div class="row m-0">
                        <div class="<?= $predefinedType === 'status_labels' ? 'col-md-9' : 'col-md-12' ?> p-0 border-right">
                            <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white">
                                <h6 class="mb-0 font-weight-bold ml-2 text-primary" style="text-transform: capitalize;"><i
                                        class="fas fa-list-alt mr-2"></i><?= htmlspecialchars($predefinedLabel) ?></h6>

                                <div class="d-flex align-items-center">
                                    <?php if ($predefinedType === 'custom_fields'): ?>
                                        <select class="form-control form-control-sm mr-2"
                                            style="width:180px; border-radius:8px;"
                                            onchange="window.location.href='varliklar?view=predefined&type=custom_fields&cat_id=' + this.value">
                                            <option value=""><?= $isTr ? 'Tüm Kategoriler' : 'All Categories' ?></option>
                                            <?php foreach ($categories as $cf_cat): ?>
                                                <option value="<?= $cf_cat['id'] ?>" <?= (($_GET['cat_id'] ?? 0) == $cf_cat['id'] ? 'selected' : '') ?>><?= htmlspecialchars($cf_cat['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>

                                    <?php
                                    $addContext = ($predefinedType === 'custom_fields') ? ($_GET['cat_id'] ?? '') : '';
                                    ?>
                                    <button
                                        class="btn btn-sm btn-primary px-2 shadow-sm d-flex align-items-center justify-content-center"
                                        style="border-radius:8px; width:32px; height:32px;"
                                        onclick="addPredefined('<?= $predefinedType ?>', '<?= $addContext ?>')"
                                        title="<?= __("add_new") ?>">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                            <?php if ($predefinedType === 'custom_fields'): ?>
                                <div class="px-3 pt-3 pb-1">
                                    <div class="p-3 rounded-lg border-0 shadow-sm" style="background: rgba(99, 102, 241, 0.06); border-left: 4px solid #6366f1 !important;">
                                        <div class="d-flex align-items-start">
                                            <div class="mr-3 mt-1" style="width: 28px; height: 28px; border-radius: 50%; background: rgba(99, 102, 241, 0.15); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <i class="fas fa-lightbulb" style="color: #6366f1; font-size: 13px;"></i>
                                            </div>
                                            <div>
                                                <h6 class="font-weight-bold mb-1 text-xs text-uppercase" style="color: #4f46e5; letter-spacing: 0.5px;">
                                                    <i class="fas fa-info-circle mr-1"></i><?= $isTr ? 'Özel Alanlar Nasıl Çalışır?' : 'How Custom Fields Work' ?>
                                                </h6>
                                                <p class="small mb-0 text-muted" style="line-height: 1.55; font-size: 0.83rem;">
                                                    <?= $isTr 
                                                        ? 'Tanımladığınız özel alanlar (örn: <strong>IMEI, MAC Adresi, İşlemci Modeli</strong>), seçtiğiniz <strong>Kategoriye</strong> ait bir demirbaş eklenirken veya düzenlenirken formda otomatik olarak görünür. Tüm kategorilerde görünmesini isterseniz <i>Kategori</i> seçimini boş bırakabilirsiniz.' 
                                                        : 'Custom fields (e.g., <strong>IMEI, MAC Address, CPU Model</strong>) automatically appear on asset forms when their assigned <strong>Category</strong> is selected. Leave category empty to make the field global for all assets.' ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="bg-light text-xs text-uppercase text-muted">
                                    <tr>
                                        <th class="pl-4" style="width:80px">ID</th>
                                        <?php if (in_array($predefinedType, ['categories', 'models', 'manufacturers', 'suppliers', 'companies'])): ?>
                                            <th style="width:50px;" class="text-center"><?= $isTr ? 'Görsel' : 'Image' ?></th>
                                        <?php endif; ?>
                                        <th><?= $predefinedType === 'categories' ? ($isTr ? 'Kategori Adı' : 'Category Name') : ($predefinedType === 'custom_fields' ? ($isTr ? 'İSİM' : 'NAME') : __("name")) ?></th>
                                        <?php if ($predefinedType === 'categories'): ?>
                                            <th><?= $isTr ? 'Varlık Türü' : 'Type' ?></th>
                                        <?php endif; ?>
                                        <?php if ($predefinedType === 'suppliers'): ?>
                                            <th><?= $isTr ? 'E-Posta' : 'Email' ?></th>
                                            <th><?= $isTr ? 'Telefon' : 'Phone' ?></th>
                                            <th><?= $isTr ? 'İlgili Kişi' : 'Contact' ?></th>
                                            <th><?= $isTr ? 'Adres' : 'Address' ?></th>
                                        <?php endif; ?>
                                        <?php if ($predefinedType === 'status_labels'): ?>
                                            <th><?= $isTr ? 'Durum Türü' : 'Type' ?></th>
                                            <th><?= $isTr ? 'Varlık Sayısı' : 'Assets' ?></th>
                                            <th><?= $isTr ? 'Renk' : 'Color' ?></th>
                                            <th><?= $isTr ? 'Nav' : 'Nav' ?></th>
                                            <th><?= $isTr ? 'Def' : 'Def' ?></th>
                                        <?php endif; ?>
                                        <?php if ($predefinedType === 'companies'): ?>
                                            <th><?= $isTr ? 'Telefon' : 'Phone' ?></th>
                                            <th><?= $isTr ? 'Vergi No' : 'Tax No' ?></th>
                                            <th><?= $isTr ? 'Web Sitesi' : 'Website' ?></th>
                                        <?php endif; ?>
                                        <?php if ($predefinedType === 'departments'): ?>
                                            <th><?= $isTr ? 'İlgili Kişi' : 'Dept. Head' ?></th>
                                        <?php endif; ?>
                                        <?php if ($predefinedType !== 'status_labels' && $predefinedType !== 'custom_fields'): ?>
                                            <th><?= __("notes") ?></th>
                                        <?php endif; ?>
                                        <?php if ($predefinedType === 'custom_fields'): ?>
                                            <th><?= $isTr ? 'Sistem Kodu' : 'Field Name' ?></th>
                                            <th><?= $isTr ? 'Tür' : 'Type' ?></th>
                                            <th><?= $isTr ? 'Grup' : 'Group' ?></th>
                                            <th><?= $isTr ? 'Kategori' : 'Category' ?></th>
                                            <th><?= $isTr ? 'Seçenekler' : 'Options' ?></th>
                                            <th><?= $isTr ? 'Durum' : 'Status' ?></th>
                                        <?php endif; ?>
                                        <th class="text-right pr-4"><?= __("actions") ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($items)): ?>
                                        <tr>
                                            <td colspan="12" class="text-center py-5 text-muted"><?= $isTr ? 'Kayıt bulunamadı.' : 'No records found.' ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php if ($predefinedType === 'categories'): ?>
                                            <?php
                                            $typeGroups = [
                                                'asset' => $isTr ? 'Demirbaşlar' : 'Assets',
                                                'accessory' => $isTr ? 'Aksesuarlar' : 'Accessories',
                                                'license' => $isTr ? 'Lisanslar' : 'Licenses',
                                                'consumable' => $isTr ? 'Sarf Malzemeleri' : 'Consumables',
                                                'component' => $isTr ? 'Bileşenler' : 'Components'
                                            ];
                                            foreach ($typeGroups as $tg_key => $tg_label):
                                                $tg_items = array_filter($items, function ($i) use ($tg_key) {
                                                    return $i['type'] == $tg_key; });
                                                if (empty($tg_items))
                                                    continue;
                                                ?>
                                                <tr style="background:#f8fafc; border-left: 4px solid #3b82f6; cursor:pointer;"
                                                    class="predefined-group-header" data-group="<?= $tg_key ?>"
                                                    onclick="togglePredefinedGroup('<?= $tg_key ?>')">
                                                    <td colspan="7"
                                                        class="py-2 px-4 font-weight-bold text-primary small text-uppercase">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span><i class="fas fa-layer-group mr-2"></i><?= $tg_label ?> <small
                                                                    class="text-muted ml-2">(<?= count($tg_items) ?>)</small></span>
                                                            <i class="fas fa-chevron-right toggle-icon text-muted"></i>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php
                                                $parents = array_filter($tg_items, function ($i) {
                                                    return empty($i['parent_id']); });
                                                $children = array_filter($tg_items, function ($i) {
                                                    return !empty($i['parent_id']); });

                                                usort($parents, function ($a, $b) {
                                                    return strcmp($a['name'], $b['name']); });
                                                usort($children, function ($a, $b) {
                                                    return strcmp($a['name'], $b['name']); });

                                                foreach ($parents as $p):
                                                    $p_subs = array_filter($children, function ($c) use ($p) {
                                                        return $c['parent_id'] == $p['id']; });
                                                    $p_json = json_encode($p, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
                                                    ?>
                                                    <tr class="bg-white shadow-none rows-<?= $tg_key ?>" style="display: none;">
                                                        <td class="pl-4 text-muted text-xs">#<?= $p['id'] ?></td>
                                                        <td class="text-center">
                                                            <?php if (!empty($p['image'])): ?>
                                                                <img src="public/uploads/categories/<?= e($p['image']) ?>"
                                                                    class="rounded border"
                                                                    style="height:25px; width:25px; object-fit:contain; background:#fff;">
                                                            <?php else: ?>
                                                                <i class="fas fa-folder text-muted opacity-50"></i>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="font-weight-bold"><?= e($p['name']) ?></td>
                                                        <td class="text-xs">
                                                            <span class="badge badge-light border text-uppercase"
                                                                style="font-size:10px;"><?= e($p['type'] ?? 'asset') ?></span>
                                                        </td>
                                                        <td class="text-xs text-muted"><?= e($p['notes'] ?? '—') ?></td>
                                                        <td class="text-right pr-4">
                                                            <button class="action-btn action-btn-edit"
                                                                onclick='editPredefined("categories", <?= $p_json ?>)'><i
                                                                    class="fas fa-edit"></i></button>
                                                            <button class="action-btn action-btn-delete"
                                                                onclick='confirmDelete(<?= $p['id'] ?>, "", "", <?= json_encode($p["name"], JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>)'><i
                                                                    class="fas fa-trash-alt"></i></button>
                                                        </td>
                                                    </tr>
                                                    <?php foreach ($p_subs as $c):
                                                        $c_json = json_encode($c, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
                                                        ?>
                                                        <tr class="bg-white shadow-none rows-<?= $tg_key ?>" style="display: none;">
                                                            <td class="pl-4 text-muted text-xs">#<?= $c['id'] ?></td>
                                                            <td class="text-center">
                                                                <?php if (!empty($c['image'])): ?>
                                                                    <img src="public/uploads/categories/<?= e($c['image']) ?>"
                                                                        class="rounded border"
                                                                        style="height:25px; width:25px; object-fit:contain; background:#fff;">
                                                                <?php else: ?>
                                                                    <i class="fas fa-tag text-muted opacity-50"></i>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="pl-4"><span class="text-muted mr-1">└─</span>
                                                                <?= e($c['name']) ?></td>
                                                            <td class="text-xs">
                                                                <span class="badge badge-light border text-uppercase"
                                                                    style="font-size:10px;"><?= e($c['type'] ?? 'asset') ?></span>
                                                            </td>
                                                            <td class="text-xs text-muted"><?= e($c['notes'] ?? '—') ?></td>
                                                            <td class="text-right pr-4">
                                                                <button class="action-btn action-btn-edit"
                                                                    onclick='editPredefined("categories", <?= $c_json ?>)'><i
                                                                        class="fas fa-edit"></i></button>
                                                                <button class="action-btn action-btn-delete"
                                                                    onclick='confirmDelete(<?= $c['id'] ?>, "", "", <?= json_encode($c["name"], JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>)'><i
                                                                        class="fas fa-trash-alt"></i></button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        <?php elseif ($predefinedType === 'custom_fields'): ?>
                                            <?php
                                            $groupedFields = [];
                                            foreach ($items as $f) {
                                                $grp = $f['field_group'] ?: ($isTr ? 'Genel' : 'General');
                                                $groupedFields[$grp][] = $f;
                                            }
                                            ksort($groupedFields);

                                            $glStmt = $pdo->query("SELECT field_group, GROUP_CONCAT(category_id) as cids FROM inventory_field_group_links GROUP BY field_group");
                                            $groupCatLinks = [];
                                            while ($gl = $glStmt->fetch(PDO::FETCH_ASSOC)) {
                                                $groupCatLinks[$gl['field_group']] = explode(',', $gl['cids']);
                                            }

                                            foreach ($groupedFields as $grpName => $fItems):
                                                $linkedCids = $groupCatLinks[$grpName] ?? [];
                                                ?>
                                                <tr style="background:#f8fafc; border-left: 4px solid #3b82f6; cursor:pointer;"
                                                    class="predefined-group-header"
                                                    data-group="cf-<?= md5($grpName) ?>"
                                                    onclick="togglePredefinedGroup('cf-<?= md5($grpName) ?>')">
                                                    <td colspan="8"
                                                        class="py-2 px-4 font-weight-bold text-primary small text-uppercase">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span>
                                                                <i class="fas fa-folder mr-2"></i><?= htmlspecialchars($grpName) ?>
                                                                <small class="text-muted ml-2">(<?= count($fItems) ?>)</small>
                                                            </span>
                                                            <i class="fas fa-chevron-right toggle-icon text-muted"></i>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php foreach ($fItems as $item): ?>
                                                    <tr class="bg-white shadow-none rows-cf-<?= md5($grpName) ?>" style="display: none;">
                                                        <td class="pl-4 text-muted text-xs">#<?= $item['id'] ?></td>
                                                        <td class="font-weight-bold"><?= e($item['field_label']) ?></td>
                                                        <td><code class="text-xs text-muted"><?= e($item['field_name']) ?></code></td>
                                                        <td><span class="badge badge-light border text-uppercase"
                                                                style="font-size:10px;"><?= e($item['field_type']) ?></span></td>
                                                        <td><span class="badge badge-light border text-uppercase"
                                                                style="font-size:10px;"><?= e($item['field_group']) ?></span></td>
                                                        <td class="text-xs">
                                                            <?php
                                                            $effectiveCid = !empty($item['category_id']) ? $item['category_id'] : null;
                                                            if ($effectiveCid && isset($categoryMap[$effectiveCid])): ?>
                                                                <span class="badge badge-soft-info" style="font-size:11px;"><i class="fas fa-tag mr-1"></i><?= e($categoryMap[$effectiveCid]) ?></span>
                                                            <?php elseif (!empty($linkedCids)): ?>
                                                                <?php
                                                                $catNames = array_filter(array_map(fn($cid) => $categoryMap[$cid] ?? null, $linkedCids));
                                                                if (!empty($catNames)): ?>
                                                                    <span class="badge badge-soft-info" style="font-size:11px;" title="<?= e(implode(', ', $catNames)) ?>"><i class="fas fa-tags mr-1"></i><?= count($catNames) ?> Kategori</span>
                                                                <?php else: ?>
                                                                    <span class="text-muted small"><?= $isTr ? 'Tüm Kategoriler' : 'All Categories' ?></span>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted small"><?= $isTr ? 'Tüm Kategoriler' : 'All Categories' ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-xs text-muted text-truncate" style="max-width:150px;"><?= e($item['options'] ?? '—') ?></td>
                                                        <td><i
                                                                class="fas <?= !empty($item['status']) ? 'fa-check-circle text-success' : 'fa-times-circle text-danger' ?>"></i>
                                                        </td>
                                                        <td class="text-right pr-4">
                                                            <button class="action-btn action-btn-edit"
                                                                onclick='editPredefined("custom_fields", <?= json_encode($item, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>)'><i
                                                                    class="fas fa-edit"></i></button>
                                                            <button class="action-btn action-btn-delete"
                                                                onclick='confirmDelete(<?= $item["id"] ?>, "", "", <?= json_encode($item["field_label"], JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>)'><i
                                                                    class="fas fa-trash-alt"></i></button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        <?php elseif ($predefinedType === 'manufacturers'): ?>
                                            <?php
                                            $groupedMans = [];
                                            foreach ($items as $m) {
                                                $letter = strtoupper(mb_substr($m['name'], 0, 1));
                                                if (!preg_match('/[A-Z0-9]/', $letter))
                                                    $letter = '#';
                                                $groupedMans[$letter][] = $m;
                                            }
                                            ksort($groupedMans);

                                            foreach ($groupedMans as $letter => $mItems):
                                                ?>
                                                <tr style="background:#f1f5f9; border-left: 4px solid #64748b; cursor:pointer;"
                                                    class="predefined-group-header" data-group="man-<?= $letter ?>"
                                                    onclick='togglePredefinedGroup("man-<?= $letter ?>")'>
                                                    <td colspan="12"
                                                        class="py-2 px-4 font-weight-bold text-secondary small text-uppercase">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span><i class="fas fa-industry mr-2"></i><?= $letter ?> <small
                                                                    class="text-muted ml-2">(<?= count($mItems) ?>)</small></span>
                                                            <i class="fas fa-chevron-right toggle-icon text-muted"></i>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php foreach ($mItems as $item):
                                                    $item_json = json_encode($item, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
                                                    ?>
                                                    <tr class="bg-white shadow-none rows-man-<?= $letter ?>" style="display: none;">
                                                        <td class="pl-4 text-muted text-xs">#<?= $item['id'] ?></td>
                                                        <td class="text-center">
                                                            <?php if (!empty($item['image'])): ?>
                                                                <img src="public/uploads/manufacturers/<?= e($item['image']) ?>"
                                                                    class="rounded border"
                                                                    style="height:25px; width:25px; object-fit:contain; background:#fff;">
                                                            <?php else: ?>
                                                                <i class="fas fa-industry text-muted opacity-50"></i>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="font-weight-bold"><?= e($item['name']) ?></td>
                                                        <td class="text-xs text-muted">
                                                            <?= e($item['notes'] ?? $item['description'] ?? '—') ?></td>
                                                        <td class="text-right pr-4">
                                                            <button class="action-btn action-btn-edit"
                                                                onclick='editPredefined("manufacturers", <?= $item_json ?>)'><i
                                                                    class="fas fa-edit"></i></button>
                                                            <button class="action-btn action-btn-delete"
                                                                onclick='confirmDelete(<?= $item['id'] ?>, "", "", <?= json_encode($item["name"], JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>)'><i
                                                                    class="fas fa-trash-alt"></i></button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <?php foreach ($items as $item):
                                                $item_json = json_encode($item, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
                                                ?>
                                                <tr class="<?= (($_GET['highlight_id'] ?? 0) == $item['id']) ? 'row-highlight-pulse' : '' ?>" id="item-<?= $item['id'] ?>">
                                                    <td class="pl-4 text-muted text-xs">#<?= $item['id'] ?></td>
                                                    <?php if (in_array($predefinedType, ['models', 'suppliers', 'companies'])): ?>
                                                        <td class="text-center">
                                                            <?php
                                                            $folder = match ($predefinedType) {
                                                                'models' => 'models',
                                                                'suppliers' => 'suppliers',
                                                                'companies' => 'companies',
                                                                default => 'assets'
                                                            };
                                                            $img = !empty($item['image']) ? "public/uploads/$folder/" . $item['image'] : "";
                                                            ?>
                                                            <?php if ($img): ?>
                                                                <img src="<?= e($img) ?>" class="rounded border"
                                                                    style="height:25px; width:25px; object-fit:contain; background:#fff;">
                                                            <?php else: ?>
                                                                <i
                                                                    class="fas <?= match ($predefinedType) { 'models' => 'fa-laptop', 'suppliers' => 'fa-truck', 'companies' => 'fa-building', default => 'fa-tag'} ?> text-muted opacity-50"></i>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endif; ?>
                                                    <td class="font-weight-bold">
                                                        <?php if ($predefinedType === 'status_labels' && !empty($item['color'])): ?>
                                                            <span class="mr-2"
                                                                style="display:inline-block; width:10px; height:10px; border-radius:50%; background:<?= e($item['color']) ?>"></span>
                                                        <?php endif; ?>
                                                        <?= e($item['name'] ?? $item['bolum_adi'] ?? '—') ?>
                                                    </td>

                                                    <?php if ($predefinedType === 'status_labels'): ?>
                                                        <td>
                                                            <?php
                                                            $t_map = [
                                                                'deployable' => ['tr' => 'Dağıtılabilir', 'en' => 'Deployable'],
                                                                'pending' => ['tr' => 'Bekliyor', 'en' => 'Pending'],
                                                                'undeployable' => ['tr' => 'Dağıtılamaz', 'en' => 'Undeployable'],
                                                                'archived' => ['tr' => 'Arşivlenmiş', 'en' => 'Archived']
                                                            ];
                                                            $t_info = $t_map[$item['type'] ?? 'pending'] ?? ['tr' => 'Bekliyor', 'en' => 'Pending'];
                                                            $t_label = $isTr ? $t_info['tr'] : $t_info['en'];
                                                            $t_dot_color = match ($item['type'] ?? 'pending') {
                                                                'deployable' => '#16d935',
                                                                'pending' => '#e39b10',
                                                                'undeployable' => '#ff0000',
                                                                'archived' => '#6c757d',
                                                                default => '#888'
                                                            };
                                                            ?>
                                                            <span class="text-xs font-weight-bold" style="color:<?= $t_dot_color ?>"><i
                                                                    class="fas fa-circle mr-1" style="font-size:8px;"></i>
                                                                <?= $t_label ?></span>
                                                        </td>
                                                        <td class="text-xs">
                                                            <?php
                                                            $count = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE status_id = ?");
                                                            $count->execute([$item['id']]);
                                                            echo $count->fetchColumn();
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <code class="text-xs px-2 py-1 rounded"
                                                                style="background:<?= $item['color'] ?? '#eee' ?>; color:#fff"><?= $item['color'] ?? '—' ?></code>
                                                        </td>
                                                        <td><i
                                                                class="fas <?= !empty($item['show_in_nav']) ? 'fa-check text-success' : 'fa-times text-danger' ?> text-xs"></i>
                                                        </td>
                                                        <td><i
                                                                class="fas <?= !empty($item['is_default']) ? 'fa-check text-success' : 'fa-times text-danger' ?> text-xs"></i>
                                                        </td>
                                                    <?php endif; ?>

                                                    <?php if ($predefinedType === 'suppliers'): ?>
                                                        <td class="text-xs"><?= e($item['email'] ?? '—') ?></td>
                                                        <td class="text-xs"><?= e($item['phone'] ?? '—') ?></td>
                                                        <td class="text-xs font-weight-bold text-primary"><?= e($item['contact_person'] ?? '—') ?></td>
                                                        <td class="text-xs text-truncate" style="max-width:180px;"><?= e($item['address'] ?? '—') ?></td>
                                                    <?php endif; ?>

                                                    <?php if ($predefinedType === 'companies'): ?>
                                                        <td class="text-xs"><?= e($item['phone'] ?? '—') ?></td>
                                                        <td class="text-xs font-weight-bold"><?= e($item['tax_number'] ?? '—') ?></td>
                                                        <td class="text-xs"><?= e($item['website'] ?? '—') ?></td>
                                                    <?php endif; ?>

                                                    <?php if ($predefinedType === 'departments'): ?>
                                                        <td class="text-xs font-weight-bold text-primary">
                                                            <?= e($item['responsible_person'] ?? '—') ?></td>
                                                    <?php endif; ?>

                                                    <?php if ($predefinedType !== 'status_labels' && $predefinedType !== 'custom_fields'): ?>
                                                        <td class="text-xs text-muted">
                                                            <?= e($item['notes'] ?? $item['description'] ?? '—') ?></td>
                                                    <?php endif; ?>

                                                    <td class="text-right pr-4">
                                                        <div class="d-flex justify-content-end">
                                                            <?php if ($predefinedType === 'suppliers'): ?>
                                                                <button class="action-btn" style="background:#3b82f6"
                                                                    onclick='viewSupplierSummary(<?= $item["id"] ?>, <?= json_encode($item["name"], JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>)'
                                                                    title="<?= $isTr ? 'Tedarikçi Detayları' : 'Supplier Details' ?>"><i
                                                                        class="fas fa-list-ul"></i></button>
                                                            <?php endif; ?>
                                                            <?php if ($show_deleted): ?>
                                                                <div class="action-group">
                                                                    <button class="action-btn action-btn-copy"
                                                                        onclick='confirmRestorePredefined(<?= $item["id"] ?>, "<?= $predefinedType ?>")'
                                                                        title="<?= __("restore") ?>" style="background:#10b981"><i
                                                                            class="fas fa-trash-restore"></i></button>
                                                                    <button class="action-btn action-btn-delete"
                                                                        onclick='confirmPermanentDeletePredefined(<?= $item["id"] ?>, "<?= $predefinedType ?>")'
                                                                        title="<?= $isTr ? 'Kalıcı Olarak Sil' : 'Permanently Delete' ?>"><i
                                                                            class="fas fa-eraser"></i></button>
                                                                </div>
                                                            <?php else: ?>
                                                                <button class="action-btn action-btn-edit"
                                                                    onclick='editPredefined("<?= $predefinedType ?>", <?= $item_json ?>)'
                                                                    title="<?= __("edit") ?>"><i class="fas fa-edit"></i></button>
                                                                <button class="action-btn action-btn-delete"
                                                                    onclick='confirmDelete(<?= $item["id"] ?>, "", "", <?= json_encode($item["name"] ?? $item["bolum_adi"] ?? "", JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, "predefined", "<?= $predefinedType ?>")'
                                                                    title="<?= __("delete") ?>"><i class="fas fa-trash-alt"></i></button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($predefinedType === 'status_labels'): ?>
                            <div class="col-md-3 bg-light p-4">
                                <h6 class="font-weight-bold mb-3"><?= __("Durum Etiketi Hakkında") ?></h6>
                                <p class="small text-muted mb-4">
                                    Durum etiketi türleri, varlıklarınızın içinde bulunabileceği çeşitli durumları tanımlamak
                                    için kullanılır. Onarım için dışarıda, kayıp/çalıntı vb. olabilirler.
                                </p>

                                <div class="mb-3 p-2 border-left border-success bg-white shadow-sm"
                                    style="border-left-width:4px !important;">
                                    <div class="small font-weight-bold text-success mb-1"><i class="fas fa-circle mr-1"></i>
                                        Dağıtılabilir</div>
                                    <div class="text-xs text-muted">Bu varlıklar kontrol edilebilir. Atandıkları anda Atandı
                                        statusunu alacaklar.</div>
                                </div>
                                <div class="mb-3 p-2 border-left border-warning bg-white shadow-sm"
                                    style="border-left-width:4px !important;">
                                    <div class="small font-weight-bold text-warning mb-1"><i class="fas fa-circle mr-1"></i>
                                        Beklemede</div>
                                    <div class="text-xs text-muted">Bu varlıklar kimseye atanamıyor, genellikle onarım için olan
                                        ancak dolaşıma dönmesi beklenen öğeler.</div>
                                </div>
                                <div class="mb-3 p-2 border-left border-danger bg-white shadow-sm"
                                    style="border-left-width:4px !important;">
                                    <div class="small font-weight-bold text-danger mb-1"><i class="fas fa-circle mr-1"></i>
                                        Dağıtılamaz</div>
                                    <div class="text-xs text-muted">Bu varlıklar kimseye atanamaz.</div>
                                </div>
                                <div class="mb-3 p-2 border-left border-secondary bg-white shadow-sm"
                                    style="border-left-width:4px !important;">
                                    <div class="small font-weight-bold text-secondary mb-1"><i class="fas fa-times mr-1"></i>
                                        Arşivlenmiş</div>
                                    <div class="text-xs text-muted">Bu öğeler kontrol edilemez ve yalnızca Arşivlenmiş görünümde
                                        görünür.</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th class="pl-4" style="width:30px;"><input type="checkbox" id="selectAllGeneric">
                                </th>
                                <th class="pl-4 col-name">
                                    <?= ($view === 'consumables') ? ($isTr ? 'Sarf Malzeme Adı' : 'Consumable Name') : (($view === 'components' && $isTr) ? 'Bileşen Adı' : (($view === 'accessories' && $isTr) ? 'Aksesuar Adı' : (($view === 'licenses' && $isTr) ? 'Lisans Adı' : __("name")))) ?>
                                </th>
                                <th class="col-company"><?= __("company") ?></th>
                                <th class="col-image text-center"><?= __("device_image") ?></th>

                                <?php if ($view === 'accessories' || $view === 'components' || $view === 'consumables'): ?>
                                    <th class="col-serial"><?= __("serial_no") ?></th>
                                <?php endif; ?>

                                <th class="col-category"><?= __("category") ?></th>
                                <th class="col-manufacturer"><?= __("manufacturer") ?></th>
                                <th class="col-purchase"><?= __("purchase_date") ?></th>
                                <th class="col-order"><?= __("order_number") ?></th>

                                <th class="col-cost"><?= __("purchase_cost") ?></th>
                                <th class="col-total"><?= __("total") ?></th>
                                <?php if ($view === 'consumables'): ?>
                                    <th class="col-used"><?= __("used") ?></th><?php endif; ?>
                                <th class="col-remaining"><?= __("remaining") ?></th>
                                <th class="col-supplier"><?= __("supplier") ?></th>
                                <th class="col-dept"><?= __("department") ?></th>

                                <?php if ($view === 'licenses'): ?>
                                    <th class="col-key"><?= __("product_key") ?></th>
                                    <th class="col-expire"><?= __("expire_date") ?></th>
                                    <th class="col-email"><?= __("email") ?></th>
                                    <th class="col-assigned-target"><?= $isTr ? 'Atanan Cihaz / Personel' : 'Assigned Target' ?></th>
                                <?php endif; ?>

                                <?php if ($view === 'accessories'): ?>
                                    <th class="col-warranty"><?= __("warranty") ?></th>
                                <?php endif; ?>
                                <?php if ($view === 'accessories' || $view === 'licenses'): ?>
                                    <th class="col-notes"><?= __("notes") ?></th>
                                <?php endif; ?>
                                <th class="col-checkout"><?= __("assignment") ?></th>
                                <th class="text-right pr-4 col-actions" style="white-space: nowrap;"><?= __("actions") ?></th>
                            </tr>
                        </thead>
                        <tbody>

                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted"><?= __("no_items_found") ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $item): ?>
                                        <tr data-id="<?= $item['id'] ?>"
                                            data-category-id="<?= $item['category_id'] ?>"
                                            data-search-terms="<?= htmlspecialchars(strtolower(implode(' ', array_filter([
                                                $item['name'] ?? '', 
                                                $item['software_name'] ?? '', 
                                                $item['serial_no'] ?? '', 
                                                $item['model_no'] ?? '', 
                                                $item['category_name'] ?? '', 
                                                $item['manufacturer_name'] ?? '', 
                                                $item['supplier_name'] ?? '', 
                                                $item['company_name'] ?? '', 
                                                $item['dept_name'] ?? '', 
                                                $item['purchase_date'] ?? '', 
                                                $item['order_no'] ?? '', 
                                                $item['serial_no'] ?? '', 
                                                $item['notes'] ?? ''
                                            ])))) ?>"

                                        class="<?= (($_GET['highlight_id'] ?? 0) == $item['id']) ? 'row-highlight-pulse' : '' ?>"
                                        id="item-<?= $item['id'] ?>">
                                        <td class="pl-4"><input type="checkbox" class="selectItem" value="<?= $item['id'] ?>"></td>
                                        <?php
                                        // TOPLAM = database field (Editable in modal)
                                        $totalQty = (int) ($item['total_qty'] ?? $item['seats'] ?? 0);

                                        if ($view === 'consumables') {
                                            // Accurate Used and Remaining calculation from DB transactions 
                                            $assignedQty = (int) ($pdo->query("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'checkin' THEN -quantity ELSE quantity END), 0) FROM asset_consumable_checkouts WHERE consumable_id = " . $item['id'] . " AND transaction_type IN ('consume', 'checkin')")->fetchColumn());
                                            $availableQty = max(0, $totalQty - $assignedQty);
                                        } else {
                                            $assignedQty = 0;
                                            if ($view === 'licenses') {
                                                $assignedQty = $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM asset_license_checkouts WHERE license_id = " . $item['id'] . " AND (transaction_type = 'assign' OR transaction_type IS NULL)")->fetchColumn() ?: 0;
                                            } elseif ($view === 'accessories') {
                                                $assignedQty = $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM asset_accessory_checkouts WHERE accessory_id = " . $item['id'] . " AND (transaction_type = 'assign' OR transaction_type IS NULL)")->fetchColumn() ?: 0;
                                            } elseif ($view === 'components') {
                                                $assignedQty = $item['assigned_qty'] ?? 0;
                                                $totalQty = $item['total_qty'] ?? 0;
                                            } else {
                                                $assignedQty = !empty($item['assigned_user_id']) ? 1 : 0;
                                            }
                                            $availableQty = max(0, $totalQty - (int) $assignedQty);
                                        }
                                        ?>
                                        <td class="pl-4 col-name">
                                            <a href="varlik-detay/<?= e($item['id']) ?>?view=<?= e($view) ?>"
                                                class="text-decoration-none">
                                                <div class="font-weight-bold text-dark">
                                                    <?= e($item['name'] ?? $item['software_name'] ?? '—') ?></div>
                                            </a>
                                        </td>
                                        <td class="text-xs col-company">
                                             <?php if (!empty($item['company_id'])): ?>
                                                 <a href="varliklar?view=predefined&type=companies&highlight_id=<?= e($item['company_id']) ?>" class="text-info font-weight-bold" title="<?= $isTr ? 'Şirket Tanımına Git' : 'View Company' ?>">
                                                     <?= e($item['company_name']) ?>
                                                 </a>
                                             <?php else: ?>
                                                 <span class="text-muted">—</span>
                                             <?php endif; ?>
                                         </td>

                                        <td class="col-image text-center">
                                            <?php
                                            $raw_img = $item['image'] ?? $item['model_image'] ?? '';
                                            $final_img = "";
                                            if (!empty($raw_img)) {
                                                $folder = $view; // default
                                                if (strpos($raw_img, 'models-') === 0)
                                                    $folder = 'models';
                                                elseif (strpos($raw_img, 'categories-') === 0)
                                                    $folder = 'categories';
                                                elseif (strpos($raw_img, 'accessories-') === 0)
                                                    $folder = 'accessories';
                                                elseif (strpos($raw_img, 'consumables-') === 0)
                                                    $folder = 'consumables';
                                                elseif (strpos($raw_img, 'components-') === 0)
                                                    $folder = 'components';
                                                elseif (strpos($raw_img, 'licenses-') === 0)
                                                    $folder = 'licenses';
                                                elseif (strpos($raw_img, 'assets-') === 0)
                                                    $folder = 'assets';

                                                $final_img = "public/uploads/" . $folder . "/" . $raw_img;
                                            } elseif (!empty($item['category_image'])) {
                                                $final_img = "public/uploads/categories/" . $item['category_image'];
                                            }
                                            ?>
                                            <?php if (!empty($final_img = getSafeImageUrl($final_img))): ?>
                                                <img src="<?= htmlspecialchars($final_img) ?>" class="rounded border shadow-sm"
                                                    style="height:35px; width:35px; object-fit:contain; background:#fff;"
                                                    onerror="this.src=placeholderImg;">
                                            <?php else: ?>
                                                <i
                                                    class="fas <?= match ($view) { 'accessories' => 'fa-keyboard', 'consumables' => 'fa-tint', 'components' => 'fa-microchip', 'licenses' => 'fa-id-card', default => 'fa-box'} ?> text-muted opacity-50"></i>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($view === 'accessories' || $view === 'components' || $view === 'consumables'): ?>
                                            <td class="text-xs col-serial">
                                                <?= e($item['serial_no'] ?? $item['model_no'] ?? '—') ?></td>
                                        <?php endif; ?>

                                        <td class="text-xs col-category">
                                            <?php
                                            $disName = $isTr ? ($item['category_name'] ?? '—') : ($item['category_name_en'] ?? $item['category_name'] ?? '—');
                                            if (!empty($item['category_id'])) {
                                                echo '<a href="varliklar?view=predefined&type=categories&highlight_id=' . e($item['category_id']) . '" class="text-info font-weight-bold" title="' . ($isTr ? 'Kategori Tanımına Git' : 'View Category') . '">' . e($disName) . '</a>';
                                            } else {
                                                echo e($disName);
                                            }
                                            ?>
                                        </td>
                                        <td class="text-xs col-manufacturer">
                                              <?php if (!empty($item['manufacturer_id'])): ?>
                                                  <a href="varliklar?view=predefined&type=manufacturers&highlight_id=<?= e($item['manufacturer_id']) ?>" class="text-info font-weight-bold" title="<?= $isTr ? 'Üretici Tanımına Git' : 'View Manufacturer' ?>">
                                                      <?= e($item['manufacturer_name']) ?>
                                                  </a>
                                              <?php else: ?>
                                                  <span class="text-muted">—</span>
                                              <?php endif; ?>
                                        </td>
                                        <td class="text-xs col-purchase"><?= $item['purchase_date'] ?? '—' ?></td>
                                        <td class="text-xs col-order"><?= htmlspecialchars($item['order_no'] ?? '—') ?></td>

                                        <?php if ($view !== 'licenses'): ?>
                                            <?php if ($view !== 'accessories' && $view !== 'consumables' && $view !== 'components'): ?>
                                                <td class="text-xs col-model"><?= e($item['model_no'] ?? '—') ?></td>
                                            <?php endif; ?>
                                        <?php endif; ?>


                                        <?php
                                        $sym = ($item['purchase_currency'] === 'USD' ? '$' : ($item['purchase_currency'] === 'EUR' ? '€' : '₺'));
                                        ?>
                                        <td class="text-xs col-cost"><?= number_format($item['purchase_cost'] ?? 0, 2) ?>
                                            <?= $sym ?></td>
                                        <td class="text-xs col-total"><?= $totalQty ?></td>
                                        <?php if ($view === 'consumables'): ?>
                                            <td class="text-xs col-used text-warning font-weight-bold"><?= $assignedQty ?></td>
                                        <?php endif; ?>
                                        <td class="text-xs col-remaining <?php
                                        if ($view === 'consumables') {
                                            if ($availableQty <= 0)
                                                echo 'text-danger font-weight-bold';
                                        } else {
                                            echo $availableQty <= 0 ? 'text-danger font-weight-bold' : '';
                                        }
                                        ?>"><?= $availableQty ?></td>
                                        <td class="text-xs col-supplier">
                                             <?php if (!empty($item['supplier_id'])): ?>
                                                 <a href="varliklar?view=predefined&type=suppliers&highlight_id=<?= e($item['supplier_id']) ?>" class="text-info font-weight-bold" title="<?= $isTr ? 'Tedarikçi Tanımına Git' : 'View Supplier' ?>">
                                                     <?= e($item['supplier_name']) ?>
                                                 </a>
                                             <?php else: ?>
                                                 <span class="text-muted">—</span>
                                             <?php endif; ?>
                                         </td>
                                         <td class="text-xs col-dept">
                                              <?php if (!empty($item['department_id'])): ?>
                                                  <a href="varliklar?view=predefined&type=departments&highlight_id=<?= e($item['department_id']) ?>" class="text-info font-weight-bold" title="<?= $isTr ? 'Bölüm Tanımına Git' : 'View Department' ?>">
                                                      <?= e($item['dept_name']) ?>
                                                  </a>
                                              <?php else: ?>
                                                  <span class="text-muted">—</span>
                                              <?php endif; ?>
                                          </td>
                                        <?php if ($view === 'licenses'): ?>
                                            <td class="text-xs col-key"><code class="cursor-pointer copy-key"
                                                    onclick="copyToClipboard('<?= e($item['license_key'] ?? '') ?>', this)"
                                                    data-key="<?= e($item['license_key'] ?? '') ?>"><?= e($item['license_key'] ?? '—') ?></code>
                                            </td>
                                            <td class="text-xs col-expire text-danger"><?= e($item['expire_date'] ?? '—') ?></td>
                                            <td class="text-xs col-email"><?= e($item['license_email'] ?? '—') ?></td>
                                            <td class="text-xs col-assigned-target font-weight-bold text-primary">
                                                <?= !empty($item['assigned_targets']) ? e($item['assigned_targets']) : '<span class="text-muted font-weight-normal">' . ($isTr ? 'Stokta (Atanmamış)' : 'In Stock') . '</span>' ?>
                                            </td>
                                        <?php endif; ?>

                                        <td class="col-checkout">
                                            <?php if ($show_deleted): ?>
                                                <span class="text-danger small font-weight-bold"><i
                                                        class="fas fa-exclamation-triangle mr-1"></i><?= $isTr ? 'Geri yükle' : 'Restore required' ?></span>
                                            <?php elseif ($can_edit_varliklar || $can_checkin_varliklar): ?>
                                                <?php if ($view === 'components'): ?>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-info dropdown-toggle px-3 shadow-sm w-100" type="button" 
                                                                style="border-radius:10px; font-weight:600; font-size:11px;"
                                                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" data-boundary="window">
                                                            <i class="fas fa-cog mr-1"></i> <?= $isTr ? 'Yönet' : 'Manage' ?>
                                                        </button>
                                                        <div class="dropdown-menu shadow border-0 p-1" style="border-radius:12px; min-width: 150px; z-index: 9999;">
                                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick='openInstancePicker("<?= addslashes($item["name"]) ?>", <?= $item["category_id"] ?>, <?= $item["company_id"] ?? 0 ?>)'>
                                                                <i class="fas fa-list mr-2 text-info" style="width:20px;"></i> <?= $isTr ? 'Parçaları Yönet' : 'Manage Instances' ?>
                                                            </a>
                                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick='replenishStock(<?= $item["id"] ?>, <?= json_encode($item["name"], JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, "components")'>
                                                                <i class="fas fa-plus-circle mr-2 text-success" style="width:20px;"></i> <?= $isTr ? 'Stok Ekle' : 'Add Stock' ?>
                                                            </a>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-info dropdown-toggle px-3 shadow-sm w-100" type="button" 
                                                                style="border-radius:10px; font-weight:600; font-size:11px;"
                                                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" data-boundary="window">
                                                            <i class="fas fa-cog mr-1"></i> <?= $isTr ? 'Yönet' : 'Manage' ?>
                                                        </button>
                                                        
                                                        <div class="dropdown-menu shadow border-0 p-1" style="border-radius:12px; min-width: 160px; z-index: 9999;">
                                                            <?php if ($availableQty > 0): ?>
                                                                <a class="dropdown-item py-2" href="javascript:void(0)" onclick='checkOutItem(<?= $item["id"] ?>, "<?= $view ?>", <?= (int) $availableQty ?>)'>
                                                                    <i class="fas fa-share mr-2 text-primary" style="width:20px;"></i> <?= $isTr ? 'Zimmetle / Ata' : 'Assign' ?>
                                                                </a>
                                                            <?php endif; ?>
                                                            
                                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick='replenishStock(<?= $item["id"] ?>, <?= json_encode($item["name"] ?? $item["software_name"] ?? "", JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, "<?= $view ?>")'>
                                                                <i class="fas fa-plus-circle mr-2 text-success" style="width:20px;"></i> <?= $isTr ? 'Stok Ekle' : 'Add Stock' ?>
                                                            </a>

                                                            <?php if ($assignedQty > 0 && $view !== 'consumables'): ?>
                                                                <div class="dropdown-divider"></div>
                                                                <a class="dropdown-item py-2" href="javascript:void(0)" onclick='checkInItem(<?= $item["id"] ?>, "<?= $view ?>")'>
                                                                    <i class="fas fa-undo mr-2 text-warning" style="width:20px;"></i> <?= $isTr ? 'İade Al' : 'Return' ?>
                                                                </a>
                                                                <?php if ($view !== 'consumables'): ?>
                                                                    <a class="dropdown-item py-2" href="javascript:void(0)" onclick='checkOutItem(<?= $item["id"] ?>, "<?= $view ?>", <?= (int) $availableQty ?>, true)'>
                                                                        <i class="fas fa-exchange-alt mr-2" style="width:20px; color:#6366f1;"></i> <?= $isTr ? 'Taşı / Aktar' : 'Transfer' ?>
                                                                    </a>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge badge-light border text-muted py-1 px-2" style="font-size:11px;">
                                                    <i class="fas fa-lock mr-1"></i><?= $isTr ? 'Sadece Görüntüleme' : 'View Only' ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <?php if ($view === 'accessories'): ?>
                                            <td class="text-xs col-warranty"><?= e($item['warranty_months'] ?? '0') ?>
                                                <?= $isTr ? 'Ay' : 'Months' ?></td>
                                        <?php endif; ?>
                                        <?php if ($view === 'accessories' || $view === 'licenses'): ?>
                                            <td class="text-xs col-notes text-muted"
                                                title="<?= e($item['notes'] ?? '') ?>">
                                                <?= e(mb_strimwidth($item['notes'] ?? '', 0, 20, '...')) ?></td>
                                        <?php endif; ?>



                                        <td class="text-right pr-4 col-actions">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light border dropdown-toggle px-3 shadow-sm" type="button" 
                                                        style="border-radius:10px; font-weight:600;"
                                                        id="actions-<?= $item['id'] ?>" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" data-boundary="window">
                                                    <i class="fas fa-ellipsis-v mr-1 small"></i> <?= $isTr ? 'İşlemler' : 'Actions' ?>
                                                </button>
                                                <div class="dropdown-menu dropdown-menu-right shadow border-0 p-1" style="border-radius:12px; min-width: 180px; z-index: 9999 !important;">
                                                    <?php if ($show_deleted): ?>
                                                        <a class="dropdown-item py-2" href="javascript:void(0)" onclick='confirmRestore(<?= $item["id"] ?>, "<?= $view ?>")'>
                                                            <i class="fas fa-trash-restore mr-2 text-success" style="width:20px;"></i> <?= __("restore") ?>
                                                        </a>
                                                        <div class="dropdown-divider"></div>
                                                        <a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick='confirmPermanentDelete(<?= $item["id"] ?>, "<?= $view ?>")'>
                                                            <i class="fas fa-eraser mr-2" style="width:20px;"></i> <?= $isTr ? 'Kalıcı Olarak Sil' : 'Permanently Delete' ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <?php if ($can_edit_varliklar): ?>
                                                        <a class="dropdown-item py-2" href="javascript:void(0)" onclick='editAsset(<?= json_encode($item, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, "<?= $view ?>")'>
                                                            <i class="fas fa-edit mr-2 text-primary" style="width:20px;"></i> <?= __("edit") ?>
                                                        </a>
                                                        <a class="dropdown-item py-2" href="javascript:void(0)" onclick='cloneAsset(<?= json_encode($item, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, "<?= $view ?>")'>
                                                            <i class="fas fa-copy mr-2 text-info" style="width:20px;"></i> <?= $isTr ? 'Kopyala' : 'Copy' ?>
                                                        </a>
                                                        <?php endif; ?>
                                                        <a class="dropdown-item py-2" href="javascript:void(0)" onclick='showTimeline(<?= $item["id"] ?>, "<?= getSingularType($view) ?>")'>
                                                            <i class="fas fa-history mr-2 text-warning" style="width:20px;"></i> <?= __("history") ?>
                                                        </a>
                                                        
                                                        <?php if ($can_edit_varliklar): ?>
                                                            <?php if (in_array($view, ['assets', 'accessories', 'components'])): ?>
                                                                <a class="dropdown-item py-2" href="javascript:void(0)" onclick='confirmScrap(<?= $item["id"] ?>, "<?= $view ?>", <?= (int)($item["total_qty"] ?? 1) ?>, <?= (int)($item["category_id"] ?? 0) ?>)'>
                                                                    <i class="fas fa-dumpster mr-2 text-danger" style="width:20px;"></i> <?= $isTr ? 'Hurdaya Ayır' : 'Move to Scrap' ?>
                                                                </a>
                                                            <?php endif; ?>

                                                            <div class="dropdown-divider"></div>
                                                            <?php
                                                            $aTo = $item['assigned_user'] ?? '';
                                                            $lSum = ($assignedQty > 0) ? (($isTr ? 'Aktif zimmetli: ' : 'Active assignments: ') . $assignedQty . ' ' . ($isTr ? 'adet' : 'units')) : '';
                                                            ?>
                                                            <a class="dropdown-item py-2 text-danger" href="javascript:void(0)" 
                                                               onclick='confirmDelete(<?= $item["id"] ?>, <?= json_encode($aTo, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($lSum, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($item["name"] ?? $item["software_name"] ?? "", JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, "<?= $view ?>")'>
                                                                <i class="fas fa-trash-alt mr-2" style="width:20px;"></i> <?= __("delete") ?>
                                                            </a>
                                                        <?php endif; ?>

                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <!-- NEW PAGINATION UI -->
                    <?php if ($total_pages > 1): ?>
                        <div class="px-4 py-3 bg-white border-top d-flex justify-content-between align-items-center">
                            <small class="text-muted"><?= $total_records ?>         <?= $isTr ? 'kayıttan' : 'of' ?>
                                <?= ($offset + 1) ?>-<?= min($offset + $limit, $total_records) ?>
                                <?= $isTr ? 'arası gösteriliyor.' : 'shown.' ?></small>
                            <nav>
                                <ul class="pagination pagination-sm mb-0 shadow-sm"
                                    style="border-radius:12px; overflow:hidden;">
                                    <?php
                                    global $base_url;
                                    $pageLinkBase = $show_deleted ? $base_url . "varliklar/$view/deleted?page=" : $base_url . "varliklar?view=$view&page=";
                                    $type_param = isset($_GET['type']) ? "&type=" . urlencode($_GET['type']) : '';
                                    ?>
                                    <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                                        <a class="page-link"
                                            href="<?= $pageLinkBase ?><?= $current_page - 1 ?>&limit=<?= $limit ?><?= $type_param ?>"><i
                                                class="fas fa-chevron-left"></i></a>
                                    </li>
                                    <?php
                                    $range = 2;
                                    for ($i = 1; $i <= $total_pages; $i++):
                                        if ($i == 1 || $i == $total_pages || ($i >= $current_page - $range && $i <= $current_page + $range)):
                                            ?>
                                            <li class="page-item <?= ($current_page == $i) ? 'active' : '' ?>">
                                                <a class="page-link"
                                                    href="<?= $pageLinkBase ?><?= $i ?>&limit=<?= $limit ?><?= $type_param ?>"><?= $i ?></a>
                                            </li>
                                        <?php
                                        elseif ($i == 2 || $i == $total_pages - 1):
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        endif;
                                    endfor;
                                    ?>
                                    <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                                        <a class="page-link"
                                            href="<?= $pageLinkBase ?><?= $current_page + 1 ?>&limit=<?= $limit ?><?= $type_param ?>"><i
                                                class="fas fa-chevron-right"></i></a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                    </div><!-- end table-responsive for licenses/accessories/consumables/components -->
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>


<?php if ($view === 'consumables'): ?>
    <?php
    $limit = 15;
    $hpage = intval($_GET['hpage'] ?? 1);
    if ($hpage < 1)
        $hpage = 1;
    $offset = ($hpage - 1) * $limit;

    $history_cat_id = intval($_GET['category_id'] ?? 0);
    $history_where = ""; $h_params = [];
    if ($history_cat_id > 0) { $history_where = " AND con.category_id = ? "; $h_params[] = $history_cat_id; }

    $count_q = "SELECT (
        (SELECT COUNT(*) FROM asset_consumable_checkouts cc LEFT JOIN asset_consumables con ON cc.consumable_id = con.id WHERE 1=1 " . str_replace('con.', 'con.', $history_where) . ") + 
        (SELECT COUNT(*) FROM asset_timeline at LEFT JOIN asset_consumables con ON at.asset_id = con.id WHERE at.item_type = 'consumable' AND at.event_type IN ('created', 'updated') " . str_replace('con.', 'con.', $history_where) . ")
    ) as total";
    $c_stmt = $pdo->prepare($count_q);
    $c_stmt->execute(array_merge($h_params, $h_params));
    $total_history = $c_stmt->fetchColumn() ?: 0;
    $total_hpages = ceil($total_history / $limit);

    $h_sql = "(SELECT 'checkout' as log_type, cc.id, cc.created_at, u.fullname as user_name, a.name as asset_name, con.name as consumable_name, con.category_id, cc.quantity, cc.notes, cc.transaction_type, d.bolum_adi as dept_name
         FROM asset_consumable_checkouts cc 
         LEFT JOIN users u ON cc.user_id = u.id 
         LEFT JOIN assets a ON cc.asset_id = a.id 
         LEFT JOIN asset_consumables con ON cc.consumable_id = con.id
         LEFT JOIN bolumler d ON cc.department_id = d.id
         WHERE 1=1 $history_where)
        UNION ALL
        (SELECT 'timeline' as log_type, at.id, at.created_at, u.fullname as user_name, NULL as asset_name, con.name as consumable_name, con.category_id, NULL as quantity, at.event_description as notes, 'system' as transaction_type, NULL as dept_name
         FROM asset_timeline at
         LEFT JOIN users u ON at.user_id = u.id
         LEFT JOIN asset_consumables con ON (at.item_type = 'consumable' AND at.asset_id = con.id)
         WHERE at.item_type = 'consumable' AND at.event_type IN ('created', 'updated') $history_where)
        ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($h_sql);
    $stmt->execute(array_merge($h_params, $h_params));
    $cHistory = $stmt->fetchAll();

    ?>
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card card-outline card-info shadow-sm" id="history-card"
                style="border-radius:15px; overflow:hidden;">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="card-title font-weight-bold mb-0">
                        <i class="fas fa-history mr-2 text-info"></i>
                        <?= $isTr ? 'Sarf Malzemesi Hareket Geçmişi' : 'Consumable History' ?>
                    </h5>
                    <div class="card-tools d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-danger d-none" id="btnDeleteConsLogs"
                            onclick="deleteSelectedConsLogs()">
                            <i class="fas fa-trash-alt mr-1"></i> <?= $isTr ? 'Seçilenleri Sil' : 'Delete Selected' ?>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success mr-2"
                            onclick="openExportModal('excel')">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="openExportModal('pdf')">
                            <i class="fas fa-file-pdf mr-1"></i> PDF
                        </button>
                    </div>
                </div>
                <div class="card-body p-0" style="overflow: visible !important;">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="pl-4" style="width:30px;"><input type="checkbox" id="selectAllConsLogs"></th>
                                    <th><?= $isTr ? 'Tarih' : __("Date") ?></th>
                                    <th><?= __("item") ?></th>
                                    <th><?= __("user") ?> / <?= __("asset") ?></th>
                                    <th><?= $isTr ? 'Departman' : 'Department' ?></th>
                                    <th class="text-center"><?= $isTr ? 'İşlem / Adet' : 'Action / Qty' ?></th>
                                    <th><?= __("notes") ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($cHistory)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted"><?= __("no_activity_found") ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($cHistory as $log): ?>
                                        <tr data-id="<?= $log['id'] ?>" data-type="<?= $log['log_type'] ?>"
                                            data-category-id="<?= $log['category_id'] ?? '' ?>"
                                            class="<?= $log['log_type'] === 'timeline' ? 'bg-light-info' : '' ?>">
                                            <td class="pl-4"><input type="checkbox" class="selectConsLog" value="<?= $log['id'] ?>"
                                                    data-type="<?= $log['log_type'] ?>"></td>
                                            <td class="text-xs"><?= date('d.m.Y H:i', strtotime($log['created_at'])) ?></td>
                                            <td class="font-weight-bold">
                                                <div class="d-flex align-items-center">
                                                    <i
                                                        class="fas <?= $log['log_type'] === 'checkout' ? 'fa-exchange-alt text-warning' : 'fa-cog text-info' ?> mr-2 opacity-50"></i>
                                                    <?= htmlspecialchars($log['consumable_name'] ?? '—') ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($log['user_name']): ?>
                                                    <span class="badge badge-soft-info"><i class="fas fa-user-circle mr-1"></i>
                                                        <?= htmlspecialchars($log['user_name'] ?? '—') ?></span>
                                                <?php endif; ?>
                                                <?php if ($log['asset_name']): ?>
                                                    <span class="badge badge-soft-primary ml-1"><i class="fas fa-laptop mr-1"></i>
                                                        <?= htmlspecialchars($log['asset_name'] ?? '—') ?></span>
                                                <?php endif; ?>
                                                <?php if (!$log['user_name'] && !$log['asset_name']): ?>
                                                    <span
                                                        class="text-muted small"><?= $isTr ? 'Sistem İşlemi' : 'System Action' ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span
                                                    class="small text-muted"><?= htmlspecialchars($log['dept_name'] ?? '—') ?></span>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($log['log_type'] === 'checkout'): ?>
                                                    <?php if (($log['transaction_type'] ?? '') === 'add'): ?>
                                                        <span class="badge badge-soft-success"><i class="fas fa-plus mr-1"></i>
                                                            <?= $isTr ? 'GİRİŞ' : 'ENTRY' ?></span>
                                                        <strong class="text-success ml-1">+<?= $log['quantity'] ?></strong>
                                                    <?php elseif (($log['transaction_type'] ?? '') === 'info'): ?>
                                                        <div class="d-flex flex-column align-items-center">
                                                            <span class="badge badge-soft-info"><i class="fas fa-info-circle mr-1"></i>
                                                                <?= $isTr ? 'BİLGİ' : 'INFO' ?></span>
                                                            <strong class="text-info"><?= $log['quantity'] ?></strong>
                                                            <small class="text-info"
                                                                style="font-size: 8px; line-height: 1;"><?= $isTr ? '(Stoktan düşülmedi)' : '(Stock not deducted)' ?></small>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="badge badge-soft-danger"><i class="fas fa-minus mr-1"></i>
                                                            <?= $isTr ? 'ÇIKIŞ' : 'EXIT' ?></span>
                                                        <strong class="text-danger ml-1">-<?= $log['quantity'] ?></strong>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <i class="fas fa-edit text-muted small"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-xs text-muted">
                                                <?php if ($log['log_type'] === 'timeline'): ?>
                                                    <span
                                                        class="badge badge-pill badge-info px-2 mr-1"><?= $isTr ? 'GÜNCELLEME' : 'UPDATE' ?></span>
                                                <?php endif; ?>
                                                <?= htmlspecialchars($log['notes'] ?? '—') ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if ($total_hpages > 1): ?>
                    <div class="card-footer bg-white border-top-0 d-flex justify-content-between align-items-center py-3">
                        <div class="small text-muted">
                            <?= __("showing") ?> <strong><?= $offset + 1 ?></strong> -
                            <strong><?= min($offset + $limit, $total_history) ?></strong> /
                            <strong><?= $total_history ?></strong>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm m-0">
                                <li class="page-item <?= ($hpage <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link"
                                        href="varliklar?view=consumables&category_id=<?= $history_cat_id ?>&hpage=<?= $hpage - 1 ?>#history-card"><i
                                            class="fas fa-chevron-left"></i></a>
                                </li>
                                <?php
                                $start = max(1, $hpage - 2);
                                $end = min($total_hpages, $hpage + 2);
                                for ($i = $start; $i <= $end; $i++): ?>
                                    <li class="page-item <?= ($i == $hpage) ? 'active' : '' ?>">
                                        <a class="page-link"
                                            href="varliklar?view=consumables&category_id=<?= $history_cat_id ?>&hpage=<?= $i ?>#history-card"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <li class="page-item <?= ($hpage >= $total_hpages) ? 'disabled' : '' ?>">
                                    <a class="page-link"
                                        href="varliklar?view=consumables&hpage=<?= $hpage + 1 ?>#history-card"><i
                                            class="fas fa-chevron-right"></i></a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($view === 'components'): ?>
    <?php
    $limit = 15;
    $hpage = intval($_GET['hpage'] ?? 1);
    if ($hpage < 1) $hpage = 1;
    $offset = ($hpage - 1) * $limit;

    $history_cat_id = intval($_GET['category_id'] ?? 0);
    $history_where = ""; $h_params = [];
    if ($history_cat_id > 0) { $history_where = " AND c.category_id = ? "; $h_params[] = $history_cat_id; }

    $count_q = "SELECT COUNT(*) FROM asset_timeline at LEFT JOIN asset_components c ON at.asset_id = c.id WHERE at.item_type = 'component' $history_where";
    $c_stmt = $pdo->prepare($count_q);
    $c_stmt->execute($h_params);
    $total_history = $c_stmt->fetchColumn() ?: 0;
    $total_hpages = ceil($total_history / $limit);

    $h_sql = "SELECT at.*, u.fullname as user_name, c.name as component_name, c.category_id, c.serial_no as instance_serial
         FROM asset_timeline at
         LEFT JOIN users u ON at.user_id = u.id
         LEFT JOIN asset_components c ON at.asset_id = c.id
         WHERE at.item_type = 'component' $history_where
         ORDER BY at.created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($h_sql);
    $stmt->execute($h_params);
    $compHistory = $stmt->fetchAll();

    ?>
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card card-outline card-primary shadow-sm" id="history-card"
                style="border-radius:15px; overflow:hidden;">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="card-title font-weight-bold mb-0">
                        <i class="fas fa-history mr-2 text-primary"></i>
                        <?= $isTr ? 'Bileşen Hareket Geçmişi' : 'Component Activity History' ?>
                    </h5>
                    <div class="card-tools d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-success mr-2"
                            onclick="openExportModal('excel')">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="openExportModal('pdf')">
                            <i class="fas fa-file-pdf mr-1"></i> PDF
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="pl-4" style="width:180px;"><?= __("date") ?></th>
                                    <th><?= __("item") ?></th>
                                    <th><?= $isTr ? 'Kullanıcı' : 'User' ?></th>
                                    <th><?= $isTr ? 'İşlem' : 'Action' ?></th>
                                    <th class="pr-4"><?= $isTr ? 'Notlar / Detay' : 'Notes / Details' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($compHistory)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted"><?= __("no_items_found") ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($compHistory as $log): ?>
                                        <tr data-category-id="<?= $log['category_id'] ?? '' ?>">
                                            <td class="pl-4 text-muted small">
                                                <?= date('d.m.Y H:i', strtotime($log['created_at'])) ?>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column">
                                                    <span class="font-weight-bold text-dark"><?= htmlspecialchars($log['component_name'] ?? '-') ?></span>
                                                    <?php if ($log['instance_serial']): ?>
                                                        <span class="badge badge-soft-warning border-warning mt-1" style="font-size: 10px; width: fit-content;">
                                                            <i class="fas fa-barcode mr-1"></i> S/N: <?= htmlspecialchars($log['instance_serial']) ?>
                                                        </span>
                                                    <?php endif; ?>

                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-sm bg-soft-primary text-primary rounded-circle mr-2 d-flex align-items-center justify-content-center" style="width:24px; height:24px; font-size:10px;">
                                                        <?= mb_substr($log['user_name'] ?? 'S', 0, 1) ?>
                                                    </div>
                                                    <span class="small font-weight-medium"><?= htmlspecialchars($log['user_name'] ?? 'Sistem') ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $type_color = match($log['event_type']) {
                                                    'checkout' => 'warning',
                                                    'checkin' => 'success',
                                                    'created' => 'info',
                                                    'updated' => 'primary',
                                                    'deleted' => 'danger',
                                                    default => 'secondary'
                                                };
                                                $type_icon = match($log['event_type']) {
                                                    'checkout' => 'fa-sign-out-alt',
                                                    'checkin' => 'fa-undo',
                                                    'created' => 'fa-plus',
                                                    'updated' => 'fa-edit',
                                                    'deleted' => 'fa-trash',
                                                    default => 'fa-info-circle'
                                                };
                                                $type_label = match($log['event_type']) {
                                                    'checkout' => $isTr ? 'ATANDI'     : 'ASSIGNED',
                                                    'checkin'  => $isTr ? 'GERİ ALINDI': 'RETURNED',
                                                    'created'  => $isTr ? 'OLUŞTURULDU': 'CREATED',
                                                    'updated'  => $isTr ? 'GÜNCELLENDİ': 'UPDATED',
                                                    'deleted'  => $isTr ? 'SİLİNDİ'   : 'DELETED',
                                                    default    => strtoupper($log['event_type'])
                                                };
                                                ?>
                                                <span class="badge badge-soft-<?= $type_color ?> px-2 py-1">
                                                    <i class="fas <?= $type_icon ?> mr-1"></i>
                                                    <?= $type_label ?>
                                                </span>
                                            </td>
                                            <td class="pr-4 small text-muted">
                                                <?= htmlspecialchars($log['event_description'] ?? '—') ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if ($total_hpages > 1): ?>
                    <div class="card-footer bg-white border-top-0 d-flex justify-content-between align-items-center py-3">
                        <div class="small text-muted">
                            <?= __("showing") ?> <strong><?= $offset + 1 ?></strong> -
                            <strong><?= min($offset + $limit, $total_history) ?></strong> /
                            <strong><?= $total_history ?></strong>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm m-0">
                                <li class="page-item <?= ($hpage <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link"
                                        href="varliklar?view=components&category_id=<?= $history_cat_id ?>&hpage=<?= $hpage - 1 ?>#history-card"><i
                                            class="fas fa-chevron-left"></i></a>
                                </li>
                                <?php
                                $start = max(1, $hpage - 2);
                                $end = min($total_hpages, $hpage + 2);
                                for ($i = $start; $i <= $end; $i++): ?>
                                    <li class="page-item <?= ($i == $hpage) ? 'active' : '' ?>">
                                        <a class="page-link"
                                            href="varliklar?view=components&category_id=<?= $history_cat_id ?>&hpage=<?= $i ?>#history-card"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <li class="page-item <?= ($hpage >= $total_hpages) ? 'disabled' : '' ?>">
                                    <a class="page-link"
                                        href="varliklar?view=components&hpage=<?= $hpage + 1 ?>#history-card"><i
                                            class="fas fa-chevron-right"></i></a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<style>
    .badge-soft-info {
        background-color: #e0f2fe;
        color: #0369a1;
    }

    .badge-soft-primary {
        background-color: #eef2ff;
        color: #4338ca;
    }

    #technicalSpecsSection {
        display: none;
    }

    .row-highlight-pulse {
        animation: pulse-highlight 1s ease-in-out infinite;
        background-color: #ebf5ff !important;
        position: relative;
        z-index: 5;
        box-shadow: inset 0 0 0 2px #3b82f6, 0 0 20px rgba(59, 130, 246, 0.3) !important;
    }

    @keyframes pulse-highlight {
        0% {
            background-color: #f0f9ff !important;
            box-shadow: inset 0 0 0 2px rgba(59, 130, 246, 0.1), 0 0 0 rgba(59, 130, 246, 0) !important;
        }

        50% {
            background-color: #dbeafe !important;
            box-shadow: inset 0 0 0 3px #3b82f6, 0 0 20px rgba(59, 130, 246, 0.4) !important;
        }

        100% {
            background-color: #f0f9ff !important;
            box-shadow: inset 0 0 0 2px rgba(59, 130, 246, 0.1), 0 0 0 rgba(59, 130, 246, 0) !important;
        }
    }

    .stock-critical-pulse {
        animation: stock-pulse 1.5s infinite;
        background-color: rgba(239, 68, 68, 0.1) !important;
        padding: 2px 5px;
        border-radius: 4px;
        color: #ef4444 !important;
    }

    @keyframes stock-pulse {
        0% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
        }

        70% {
            transform: scale(1.05);
            box-shadow: 0 0 0 10px rgba(239, 68, 68, 0);
        }

        100% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
        }
    }
</style>

<!-- Asset Ekle/Düzenle Modal -->
<div class="modal fade" id="assetModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <form method="POST" id="assetForm" enctype="multipart/form-data" class="modal-content shadow-lg border-0"
            style="border-radius:20px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="view" value="<?= $view ?>">
            <input type="hidden" name="type" value="<?= $_GET['type'] ?? '' ?>">
            <input type="hidden" name="asset_id" id="asset_id">
            <input type="hidden" name="discovered_id" id="discovered_id" value="">
            <input type="hidden" name="return_to" id="return_to" value="<?= $_GET['return_to'] ?? '' ?>">

            <div class="modal-header border-0 pb-0 bg-primary text-white" style="border-radius:20px 20px 0 0;">
                <h5 class="modal-title font-weight-bold py-2 ml-2" id="modalTitle"><?= __("add_new_asset") ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body p-4">
                <!-- Dynamic Container for Snipe-IT style fields -->
                <div id="dynamicFormFields">
                    <!-- Loaded via JS based on view/type -->
                </div>
            </div>

            <div class="modal-footer border-0 pt-0 pr-4 pb-4">
                <button type="button" class="btn btn-light px-4" data-dismiss="modal"
                    style="border-radius:12px;"><?= __("cancel") ?></button>
                <button type="submit" class="btn btn-primary px-5 shadow-lg"
                    style="border-radius:12px;"><?= __("save_data") ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Assignment Modal -->
<div class="modal fade" id="assignmentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:28px; overflow:hidden;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="quick_assign">
            <input type="hidden" name="view" id="assign_view">
            <input type="hidden" name="asset_id" id="assign_asset_id">
            <input type="hidden" name="is_transfer" id="assign_is_transfer" value="0">

            <div class="modal-header border-0 p-4 pb-0">
                <div class="d-flex align-items-center justify-content-between w-100">
                    <div class="d-flex align-items-center">
                        <div class="bg-gradient-primary text-white rounded-circle d-flex align-items-center justify-content-center mr-3 shadow-lg"
                            style="width:54px; height:54px; font-size:22px; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div>
                            <h5 class="modal-title font-weight-bold text-dark mb-0" id="assignTitle">
                                <?= __("assignment") ?></h5>
                            <p class="small text-muted mb-0">
                                <?= $isTr ? "Hızlı transfer ve atama ön izlemesi" : "Quick transfer and assignment preview" ?>
                            </p>
                        </div>
                    </div>
                    <button type="button"
                        class="close bg-light rounded-circle d-flex align-items-center justify-content-center p-0 m-0 shadow-sm"
                        data-dismiss="modal" style="width:36px; height:36px; line-height:36px;">&times;</button>
                </div>
            </div>

            <div class="modal-body p-4">
                <!-- Transfer Preview Area -->
                <div id="transferPreview" class="mb-4 animate__animated animate__fadeIn">
                    <div class="d-flex align-items-center justify-content-between p-3"
                        style="border-radius:16px; background:#f8fafc; border: 1px solid #e2e8f0;">
                        <div class="text-center flex-fill" style="min-width: 0; padding: 0 10px;">
                            <div class="small font-weight-bold text-primary text-uppercase mb-2"
                                style="font-size:0.65rem; letter-spacing: 0.5px;"><?= $isTr ? 'KAYNAK' : 'SOURCE' ?></div>
                            <div class="p-2 shadow-sm font-weight-bold text-truncate"
                                id="sourcePreviewName" style="border-radius:10px; background: #ffffff; color: #1e293b; border: 1px solid #e2e8f0; font-size: 0.9rem;">-</div>
                        </div>
                        <div class="text-primary px-2 opacity-75 animate__animated animate__pulse animate__infinite">
                            <i class="fas fa-chevron-right fa-lg"></i>
                        </div>
                        <div class="text-center flex-fill" style="min-width: 0; padding: 0 10px;">
                            <div class="small font-weight-bold text-success text-uppercase mb-2"
                                style="font-size:0.65rem; letter-spacing: 0.5px;"><?= $isTr ? 'HEDEF' : 'TARGET' ?></div>
                            <div class="p-2 shadow-sm font-weight-bold text-truncate"
                                id="targetPreviewName" style="border-radius:10px; background: #10b981; color: #ffffff; border: 1px solid #059669; font-size: 0.9rem;">-</div>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-4 d-none" id="sourceAssignmentGroup">
                    <label class="small font-weight-bold text-dark mb-2 d-block"><?= $isTr ? 'Kaynak Atama / Koltuk Seçin' : 'Select Source Assignment / Seat' ?></label>
                    <div class="input-group shadow-sm"
                        style="border-radius:16px; overflow:hidden; border: 1px solid #e2e8f0;">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white border-0 px-3"><i
                                    class="fas fa-sign-out-alt text-warning opacity-50"></i></span>
                        </div>
                        <select name="source_checkout_id" id="assign_source_checkout_id" class="form-control select2 border-0"
                            style="height:50px;">
                            <!-- Filled via JS -->
                        </select>
                    </div>
                    <p class="small text-muted mt-2 mb-0 ml-2">
                        <i class="fas fa-info-circle mr-1"></i><?= $isTr ? "Transfer edilecek mevcut bir atamayı veya boş koltuğu seçin." : "Select an existing assignment to transfer or a free seat." ?>
                    </p>
                </div>

                <!-- ENHANCEMENT: Instance Picker for Multiple Units -->
                <div class="form-group mb-4 d-none" id="instanceSelectionGroup">
                    <label class="small font-weight-bold text-dark mb-2 d-block"><?= $isTr ? 'Parça / Birim Seçin' : 'Select Specific Unit' ?></label>
                    <div class="input-group shadow-sm" style="border-radius:16px; overflow:hidden; border: 1px solid #e2e8f0;">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white border-0 px-3"><i class="fas fa-boxes text-info opacity-50"></i></span>
                        </div>
                        <select name="selected_instance" id="assign_selected_instance" class="form-control select2 border-0" style="height:50px;">
                            <option value=""><?= $isTr ? 'Parça Seçiniz...' : 'Select a unit...' ?></option>
                        </select>
                    </div>
                    <p class="small text-muted mt-2 mb-0 ml-2">
                        <i class="fas fa-info-circle mr-1"></i><?= $isTr ? "Atanmış ve boş parçalar listede gösterilmektedir." : "Assigned and available units are shown in the list." ?>
                    </p>
                </div>

                <div class="card border-0 mb-4 shadow-sm" style="border-radius:20px; background: #f8fafc;">
                    <div class="card-body p-3">
                        <label
                            class="small font-weight-bold text-muted mb-2 d-block text-uppercase letter-spacing-1"><?= $isTr ? 'Atama Tipi Seçin' : 'Select Target Type' ?></label>
                        <div class="row no-gutters bg-white p-1 shadow-inner" style="border-radius:15px;">
                            <div class="col-6">
                                <button type="button"
                                    class="btn btn-block py-2 border-0 shadow-none font-weight-bold target-type-btn active"
                                    data-type="user" style="border-radius:12px;">
                                    <i class="fas fa-user-circle mr-1"></i> <?= __("user") ?>
                                </button>
                            </div>
                            <div class="col-6">
                                <button type="button"
                                    class="btn btn-block py-2 border-0 shadow-none font-weight-bold target-type-btn"
                                    data-type="asset" style="border-radius:12px;">
                                    <i class="fas fa-laptop mr-1"></i> <?= __("asset") ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-4">
                    <label class="small font-weight-bold text-dark mb-2 d-block"
                        id="assignLabel"><?= __("user") ?></label>
                    <div class="input-group shadow-sm"
                        style="border-radius:16px; overflow:hidden; border: 1px solid #e2e8f0;">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white border-0 px-3"><i
                                    class="fas fa-search text-primary opacity-50"></i></span>
                        </div>
                        <select name="target_id" id="assign_target_id" class="form-control select2 border-0" required
                            style="height:50px;">
                            <!-- Options filled via JS -->
                        </select>
                    </div>
                </div>

                <input type="hidden" name="target_type" id="assign_target_type_hidden" value="user">
                <input type="hidden" id="assign_component_asset_id" value="">

                <div class="row">
                    <div class="col-6" id="assignQtyGroup">
                        <div class="form-group mb-4">
                            <label class="small font-weight-bold text-dark mb-2 d-block"><?= __("quantity") ?></label>
                            <input type="number" name="quantity" id="assign_quantity"
                                class="form-control shadow-sm border-light" value="1" min="1"
                                style="border-radius:12px; height:45px; background: #f8fafc;">
                        </div>
                    </div>
                    <div class="col-6" id="deductStockGroup">
                        <div class="form-group mb-4">
                            <label
                                class="small font-weight-bold text-dark mb-2 d-block"><?= $isTr ? 'Stok Kontrolü' : 'Stock Control' ?></label>
                            <div class="custom-control custom-switch pt-2">
                                <input type="checkbox" name="deduct_stock" class="custom-control-input"
                                    id="deduct_stock_check" value="1" checked>
                                <label class="custom-control-label font-weight-bold text-muted small"
                                    for="deduct_stock_check"><?= $isTr ? 'Envanterden Düş' : 'Deduct from Inv.' ?></label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-4 d-none" id="paperAssignGroup">
                    <div class="custom-control custom-checkbox custom-control-inline">
                        <input type="checkbox" name="paper_only" class="custom-control-input" id="assign_paper_only" value="1">
                        <label class="custom-control-label font-weight-bold text-dark small" for="assign_paper_only">
                            <i class="fas fa-print text-primary mr-1"></i>
                            <?= $isTr ? 'Kağıt Zimmet / Islak İmza (Sistem Onaylı Boş Tutanak)' : 'Paper Assignment / Wet Signature (Pre-approved Blank Form)' ?>
                        </label>
                    </div>
                    <p class="small text-muted mt-1 mb-0 ml-4">
                        <?= $isTr ? 'Kullanıcı dijital sisteme giremiyorsa bunu işaretleyin. Zimmet anında onaylanacak ve imzalamanız için boş tutanak indirilecektir.' : 'Check this if the user cannot log into the system. The assignment will be immediately approved and a blank form will be generated for printing.' ?>
                    </p>
                </div>

                <div class="form-group mb-0">
                    <label class="small font-weight-bold text-dark mb-2 d-block"><?= __("notes") ?>
                        (<?= __("optional") ?>)</label>
                    <textarea name="assignment_notes" class="form-control shadow-sm border-light" rows="2"
                        placeholder="<?= $isTr ? 'İşlem detaylarını buraya yazabilirsiniz...' : 'Write process details here...' ?>"
                        style="border-radius:16px; resize:none; background: #f8fafc; padding: 12px;"></textarea>
                </div>
            </div>

            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-light px-4 py-2 font-weight-bold text-muted" data-dismiss="modal"
                    style="border-radius:14px;"><?= __("cancel") ?></button>
                <button type="submit" class="btn btn-primary px-5 py-2 font-weight-bold shadow-lg"
                    style="border-radius:14px; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); border:none;"><?= $isTr ? 'İşlemi Onayla' : 'Confirm Action' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Instance Picker Modal -->
<div class="modal fade" id="instancePickerModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:28px; overflow:hidden;">
            <div class="modal-header border-0 p-4 pb-0">
                <div class="d-flex align-items-center">
                    <div class="bg-gradient-info text-white rounded-circle d-flex align-items-center justify-content-center mr-3 shadow-lg"
                        style="width:54px; height:54px; font-size:22px; background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%);">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold text-dark mb-0" id="instancePickerTitle">
                            <?= $isTr ? 'Parça Seçimi' : 'Instance Selection' ?></h5>
                        <p class="small text-muted mb-0" id="instancePickerSubtitle">-</p>
                    </div>
                </div>
                <button type="button"
                    class="close bg-light rounded-circle d-flex align-items-center justify-content-center p-0 m-0 shadow-sm"
                    data-dismiss="modal" style="width:36px; height:36px; line-height:36px;">&times;</button>
            </div>
            <div class="modal-body p-4">
                <div id="instanceListContainer" class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="pl-4">ID</th>
                                <th><?= __("serial_no") ?></th>
                                <th><?= $isTr ? 'Mevcut Durum / Atama' : 'Current Status / Assigned To' ?></th>
                                <th class="text-right pr-4"><?= __("action") ?></th>
                            </tr>
                        </thead>
                        <tbody id="instanceListBody">
                            <!-- Filled via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .target-type-btn {
        background: transparent;
        color: #64748b;
        transition: all 0.3s;
    }

    .target-type-btn.active {
        background: #6366f1 !important;
        color: white !important;
        shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .shadow-inner {
        box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.06);
    }

    .letter-spacing-1 {
        letter-spacing: 0.05em;
    }
</style>



<!-- Timeline Modal -->
<div class="modal fade" id="timelineModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0" style="border-radius:15px;">
            <div class="modal-header bg-warning text-dark border-0" style="border-radius:15px 15px 0 0;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-history mr-2"></i><?= __("device_history") ?>
                </h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-4" id="timelineContent">
                <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p class="mt-2"><?= __("loading") ?>...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Predefined Ekle/Düzenle Modal -->
<div class="modal fade" id="predefinedModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" id="predefinedForm" class="modal-content border-0 shadow-2xl"
            style="border-radius:24px; overflow:hidden; background: #fff;" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_predefined">
            <input type="hidden" name="tour" value="<?= htmlspecialchars($_GET['tour'] ?? '') ?>">
            <input type="hidden" name="type" id="p_type">
            <input type="hidden" name="id" id="p_id">
            <input type="hidden" name="inventory_type" id="p_context">

            <!-- Premium Header -->
            <div class="modal-header border-0 p-4 d-flex align-items-center"
                style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); position: relative;">
                <div class="header-icon-container mr-3 shadow-lg d-flex align-items-center justify-content-center"
                    style="width:50px; height:50px; background: rgba(255,255,255,0.2); backdrop-filter: blur(10px); border-radius:16px; border: 1px solid rgba(255,255,255,0.3);">
                    <i class="fas fa-plus text-white fa-lg" id="p_header_icon"></i>
                </div>
                <div class="flex-grow-1">
                    <h5 class="modal-title font-weight-bold text-white mb-0" id="p_title"
                        style="letter-spacing: -0.5px;"><?= __("add_new") ?></h5>
                    <p class="small text-white-50 mb-0">
                        <?= $isTr ? 'Lütfen formu eksiksiz doldurun.' : 'Please fill in the form completely.' ?></p>
                </div>
                <button type="button" class="close text-white opacity-80 hover-opacity-100 transition-all"
                    data-dismiss="modal" style="text-shadow: none; outline: none;">
                    <span style="font-size: 1.5rem;">&times;</span>
                </button>
            </div>

            <div class="modal-body p-4" style="background: #f8fafc;">
                <!-- Main Info Card -->
                <div class="card border-0 shadow-sm mb-4" style="border-radius:20px;">
                    <div class="card-body p-4">
                        <div class="form-group mb-0">
                            <label
                                class="small font-weight-bold text-muted text-uppercase letter-spacing-1 mb-2 d-block">
                                <i class="fas fa-signature mr-1 text-primary"></i> <span id="p_main_label"><?= __("name") ?></span> *
                            </label>
                            <input type="text" name="name" id="p_name"
                                class="form-control form-control-lg border-light shadow-none" required
                                placeholder="<?= __("name") ?>..."
                                style="background: #f1f5f9; border-radius:12px; font-size: 1.1rem; height: 56px; border: 2px solid transparent; transition: all 0.3s;"
                                onfocus="this.style.borderColor='#6366f1'; this.style.background='#fff';"
                                onblur="this.style.borderColor='transparent'; this.style.background='#f1f5f9';">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-7" id="p_left_col">
                        <!-- Content Sections Area -->
                        <div id="p_sections_container">
                            <!-- Status Labels Specific -->
                            <div id="p_status_fields" class="d-none animate__animated animate__fadeIn">
                                <div class="card border-0 shadow-sm mb-4" style="border-radius:20px;">
                                    <div class="card-body p-4">
                                        <h6 class="font-weight-bold mb-3 small text-uppercase text-muted"><i
                                                class="fas fa-tag mr-2"></i><?= $isTr ? 'Durum Ayarları' : 'Status Settings' ?>
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label
                                                    class="small font-weight-bold"><?= $isTr ? 'Renk' : 'Color' ?></label>
                                                <input type="color" name="color" id="p_color" class="form-control"
                                                    style="height:45px; border-radius:10px;" value="#3b82f6">
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label
                                                    class="small font-weight-bold"><?= $isTr ? 'Tür' : 'Type' ?></label>
                                                <select name="status_type" id="p_status_type" class="form-control"
                                                    style="border-radius:10px;">
                                                    <option value="deployable"><?= $isTr ? 'Dağıtılabilir (Hazır/Atanmış)' : 'Deployable' ?></option>
                                                    <option value="pending"><?= $isTr ? 'Beklemede' : 'Pending' ?></option>
                                                    <option value="undeployable"><?= $isTr ? 'Dağıtılamaz (Arızalı)' : 'Undeployable' ?></option>
                                                    <option value="archived"><?= $isTr ? 'Arşivlenmiş (Hurda)' : 'Archived' ?></option>
                                                </select>
                                            </div>
                                            <div class="col-6 mt-2">
                                                <div class="custom-control custom-switch">
                                                    <input type="checkbox" name="show_in_nav"
                                                        class="custom-control-input" id="p_show_in_nav">
                                                    <label class="custom-control-label small font-weight-bold"
                                                        for="p_show_in_nav">Nav</label>
                                                </div>
                                            </div>
                                            <div class="col-6 mt-2">
                                                <div class="custom-control custom-switch">
                                                    <input type="checkbox" name="is_default"
                                                        class="custom-control-input" id="p_is_default">
                                                    <label class="custom-control-label small font-weight-bold"
                                                        for="p_is_default">Default</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Supplier/Company Fields -->
                            <div id="p_supplier_fields" class="d-none animate__animated animate__fadeIn">
                                <div class="card border-0 shadow-sm mb-4" style="border-radius:20px;">
                                    <div class="card-body p-4">
                                        <h6 class="font-weight-bold mb-3 small text-uppercase text-muted"><i
                                                class="fas fa-address-card mr-2"></i><?= $isTr ? 'İletişim Bilgileri' : 'Contact Info' ?>
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-6 form-group"><label
                                                    class="small font-weight-bold"><?= $isTr ? 'İlgili Kişi' : 'Contact' ?></label><input
                                                    type="text" name="contact_person" id="p_supp_contact"
                                                    class="form-control" style="border-radius:10px;"></div>
                                            <div class="col-md-6 form-group"><label
                                                    class="small font-weight-bold"><?= $isTr ? 'Telefon' : 'Phone' ?></label><input
                                                    type="text" name="phone" id="p_supp_phone" class="form-control"
                                                    style="border-radius:10px;"></div>
                                            <div class="col-md-12 form-group"><label
                                                    class="small font-weight-bold">E-Posta</label><input type="email"
                                                    name="email" id="p_supp_email" class="form-control"
                                                    style="border-radius:10px;"></div>
                                            <div class="col-md-12 form-group"><label
                                                    class="small font-weight-bold">Adres</label><textarea name="address"
                                                    id="p_supp_address" class="form-control" rows="2"
                                                    style="border-radius:10px;"></textarea></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="p_company_fields" class="d-none animate__animated animate__fadeIn">
                                <div class="card border-0 shadow-sm mb-4" style="border-radius:20px;">
                                    <div class="card-body p-4">
                                        <h6 class="font-weight-bold mb-3 small text-uppercase text-muted"><i
                                                class="fas fa-building mr-2"></i><?= $isTr ? 'Kurumsal Bilgiler' : 'Corporate Info' ?>
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-6 form-group"><label
                                                    class="small font-weight-bold"><?= $isTr ? 'Telefon' : 'Phone' ?></label><input
                                                    type="text" name="phone" id="p_comp_phone" class="form-control"
                                                    style="border-radius:10px;"></div>
                                            <div class="col-md-6 form-group"><label
                                                    class="small font-weight-bold"><?= $isTr ? 'Vergi No' : 'Tax No' ?></label><input
                                                    type="text" name="tax_number" id="p_comp_tax" class="form-control"
                                                    style="border-radius:10px;"></div>
                                            <div class="col-md-12 form-group"><label class="small font-weight-bold">Web
                                                    Sitesi</label><input type="url" name="website" id="p_comp_website"
                                                    class="form-control" placeholder="https://..."
                                                    style="border-radius:10px;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="p_dept_fields" class="d-none animate__animated animate__fadeIn">
                                <div class="card border-0 shadow-sm mb-4" style="border-radius:20px;">
                                    <div class="card-body p-4">
                                        <h6 class="font-weight-bold mb-3 small text-uppercase text-muted"><i
                                                class="fas fa-sitemap mr-2"></i><?= $isTr ? 'Bölüm Bilgileri' : 'Department Info' ?>
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-12 form-group">
                                                <label class="small font-weight-bold"><?= $isTr ? 'İlgili Kişi / Sorumlu' : 'Responsible Person' ?></label>
                                                <input type="text" name="responsible_person" id="p_dept_responsible" class="form-control" placeholder="<?= $isTr ? 'Örn: Ahmet Yılmaz' : 'E.g: John Doe' ?>" style="border-radius:10px;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Model Fields -->
                            <div id="p_model_fields" class="d-none animate__animated animate__fadeIn">
                                <div class="card border-0 shadow-sm mb-4" style="border-radius:20px;">
                                    <div class="card-body p-4">
                                        <h6 class="font-weight-bold mb-3 small text-uppercase text-muted"><i
                                                class="fas fa-cubes mr-2"></i><?= $isTr ? 'Model Detayları' : 'Model Details' ?>
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label
                                                    class="small font-weight-bold"><?= $isTr ? 'Kategori' : 'Category' ?></label>
                                                <select name="category_id" id="p_model_category_id"
                                                    class="form-control select2">
                                                    <option value="">Seçiniz...</option>
                                                    <?php foreach ($categories as $c):
                                                        if (normalizeInventoryCategoryType($c['type'] ?? '') === 'asset'): ?>
                                                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?>
                                                            </option>
                                                        <?php endif; endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label class="small font-weight-bold d-flex align-items-center">
                                                    <?= $isTr ? 'Üretici' : 'Manufacturer' ?>
                                                    <a href="javascript:void(0)" onclick="addPredefined('manufacturers')" class="ml-2 text-primary" title="<?= $isTr ? 'Hızlı Ekle' : 'Quick Add' ?>"><i class="fas fa-plus-circle"></i></a>
                                                </label>
                                                <select name="manufacturer_id" id="p_model_manufacturer_id" class="form-control select2">
                                                    <option value="">Seçiniz...</option>
                                                    <?php foreach ($manufacturers as $m): ?>
                                                        <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6 form-group"><label class="small font-weight-bold">Model No</label><input type="text" name="model_number" id="p_model_number" class="form-control" style="border-radius:10px;"></div>
                                            

                                            <div class="col-md-6 form-group">
                                                <label class="small font-weight-bold d-flex align-items-center">
                                                    <?= __("min_qty") ?? ($isTr ? 'Min. Miktar' : 'Min. Qty') ?>
                                                    <i class="fas fa-info-circle text-info ml-2" style="font-size:12px; cursor:pointer;" data-toggle="tooltip" title="<?= $isTr ? 'Bir uyarı tetiklenmeden önce mevcut olması gereken asgari öğe miktarı. Düşük envanter için uyarı almak istemiyorsanız asgari miktarı boş bırakın.' : 'Minimum quantity before an alert is triggered. Leave blank if you do not wish to receive low inventory alerts.' ?>"></i>
                                                </label>
                                                <input type="number" name="min_amt" id="p_model_min_amt" class="form-control" placeholder="<?= $isTr ? 'Boş bırakılabilir' : 'Optional' ?>" style="border-radius:10px;">
                                            </div>
                                            <div class="col-md-6 form-group"><label class="small font-weight-bold"><?= $isTr ? 'Kullanım Ömrü (Ay)' : 'EOL (Months)' ?></label><input type="number" name="eol" id="p_model_eol" class="form-control" placeholder="Örn: 24" style="border-radius:10px;"></div>
                                            <div class="col-md-6 form-group">
                                                <label class="small font-weight-bold"><?= $isTr ? 'Özel Alan (Alan Grubu)' : 'Field Group' ?></label>
                                                <select name="field_group" id="p_model_field_group" class="form-control select2">
                                                    <option value=""><?= $isTr ? 'Seçiniz...' : 'Select...' ?></option>
                                                    <?php foreach ($field_groups_all as $fg): ?>
                                                        <option value="<?= htmlspecialchars($fg['name']) ?>"><?= htmlspecialchars($fg['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Custom Fields -->
                            <div id="p_custom_fields" class="d-none animate__animated animate__fadeIn">
                                <div class="card border-0 shadow-sm mb-4" style="border-radius:20px;">
                                    <div class="card-body p-4">
                                        <h6 class="font-weight-bold mb-3 small text-uppercase text-muted"><i
                                                class="fas fa-code mr-2"></i><?= $isTr ? 'Alan Tanımlama' : 'Field Definition' ?>
                                        </h6>
                                        <div class="alert alert-info small shadow-sm rounded-lg mb-4" style="background-color: #eff6ff; color: #1d4ed8; border-left:4px solid #3b82f6;">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            <?= $isTr ? 'Çoklu seçenekli bir alan (açılır kutu) eklemek istiyorsanız "Type" kısmından "Select" seçiniz. Seçenekleri virgülle ayırarak giriniz (Örn: SSD, HDD). "Dev Name" sadece sistemde kullanılır, boşluk bırakmadan İngilizce karakterlerle yazınız (Örn: ram_capacity).' : 'If you want to add a dropdown field, select "Select" from "Type". Enter options separated by commas (E.g: SSD, HDD). "Dev Name" is for system use, write without spaces using English characters (E.g: ram_capacity).' ?>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 form-group"><label
                                                    class="small font-weight-bold"><?= $isTr ? 'Kategori' : 'Category' ?></label><select
                                                    name="category_id" id="p_field_category_id" class="form-control">
                                                    <option value="">-- Seçin --</option>
                                                    <?php foreach ($categories as $cf_cat): ?>
                                                        <option value="<?= $cf_cat['id'] ?>">
                                                            <?= htmlspecialchars($cf_cat['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-6 form-group"><label
                                                    class="small font-weight-bold"><?= $isTr ? 'Grup' : 'Group' ?></label>
                                                    <input type="text" name="field_group" id="p_field_group" class="form-control" list="existingGroups" placeholder="<?= $isTr ? 'Örn: Donanım' : 'E.g: Hardware' ?>" style="border-radius:10px;">
                                                    <datalist id="existingGroups">
                                                        <?php
                                                        $gq = $pdo->query("SELECT DISTINCT field_group FROM inventory_custom_fields WHERE field_group > ''");
                                                        while($g = $gq->fetchColumn()): ?>
                                                            <option value="<?= htmlspecialchars($g) ?>"></option>
                                                        <?php endwhile; ?>
                                                    </datalist>
                                            </div>
                                            <div class="col-md-6 form-group"><label class="small font-weight-bold">Dev
                                                    Name</label><input type="text" name="field_name" id="p_field_name"
                                                    class="form-control" placeholder="cpu_model"
                                                    style="border-radius:10px;"></div>
                                            <div class="col-md-6 form-group"><label
                                                    class="small font-weight-bold">Type</label><select name="field_type"
                                                    id="p_field_type" class="form-control">
                                                    <option value="text">Text</option>
                                                    <option value="select">Select</option>
                                                    <option value="date">Date</option>
                                                </select></div>
                                            <div class="col-md-12 form-group d-none" id="p_options_group">
                                                <label class="small font-weight-bold"><?= $isTr ? 'Seçenekler (Virgülle Ayırın)' : 'Options (Comma Separated)' ?></label>
                                                <textarea name="options" id="p_options" class="form-control" rows="2" placeholder="<?= $isTr ? 'Örn: SSD, HDD, NVMe' : 'E.g: SSD, HDD, NVMe' ?>" style="border-radius:10px;"></textarea>
                                            </div>
                                            <div class="col-md-12 mt-2">
                                                <div class="custom-control custom-switch">
                                                    <input type="checkbox" name="status" class="custom-control-input" id="p_status_field_btn" value="1" checked>
                                                    <label class="custom-control-label font-weight-bold small text-muted" for="p_status_field_btn"><?= $isTr ? 'Aktif' : 'Active' ?></label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Notes Card (Always visible or shared) -->
                            <div class="card border-0 shadow-sm" style="border-radius:20px;">
                                <div class="card-body p-4">
                                    <label class="small font-weight-bold text-muted text-uppercase mb-2 d-block"><i
                                            class="fas fa-sticky-note mr-1"></i> <?= __("notes") ?></label>
                                    <textarea name="notes" id="p_notes" class="form-control border-light shadow-none"
                                        rows="4" placeholder="<?= __("optional_description") ?>..."
                                        style="background: #f1f5f9; border-radius:12px; resize: none;"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5" id="p_right_col">
                        <!-- Sidebar: Type / Image / Settings -->
                        <div class="card border-0 shadow-sm mb-4 overflow-hidden" style="border-radius:20px;">
                            <div class="card-body p-0">
                                <div id="p_image_preview_container"
                                    class="bg-light d-flex align-items-center justify-content-center position-relative d-none"
                                    style="height:180px; overflow:hidden; border-bottom: 1px solid #edf2f7; border-radius:12px 12px 0 0; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);">
                                    <input type="checkbox" name="remove_image" id="p_remove_image" value="1" class="d-none">
                                    
                                    <!-- Red X Delete Button on Top Right -->
                                    <button type="button" id="p_delete_img_btn" onclick="togglePredefinedImageDeletion(true)" class="btn btn-sm btn-danger shadow position-absolute d-none" style="top:10px; right:10px; z-index:11; border-radius:50%; width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" title="<?= $isTr ? 'Görseli Kaldır' : 'Remove Image' ?>">
                                        <i class="fas fa-trash-alt" style="font-size:13px;"></i>
                                    </button>
                                    
                                    <!-- Overlay shown ONLY when remove_image is checked -->
                                    <div id="p_image_deleted_overlay" class="d-none position-absolute w-100 h-100 flex-column align-items-center justify-content-center text-white" style="top:0; left:0; background:rgba(15, 23, 42, 0.88); z-index:10; backdrop-filter:blur(3px);">
                                        <i class="fas fa-trash-alt fa-2x mb-2 text-warning animate__animated animate__bounceIn"></i>
                                        <span class="font-weight-bold small text-uppercase mb-2" style="letter-spacing:1px;"><?= $isTr ? 'Görsel Kaldırılacak' : 'Image Will Be Removed' ?></span>
                                        <button type="button" class="btn btn-xs btn-outline-light px-3 py-1 font-weight-bold" style="border-radius:20px; font-size:11px;" onclick="togglePredefinedImageDeletion(false)">
                                            <i class="fas fa-undo mr-1"></i> <?= $isTr ? 'İptal / Geri Al' : 'Undo / Cancel' ?>
                                        </button>
                                    </div>
                                    
                                    <!-- Placeholder shown when NO image is uploaded -->
                                    <div id="p_image_placeholder" class="d-flex flex-column align-items-center justify-content-center text-muted p-3 text-center w-100 h-100" style="cursor:pointer; border: 2px dashed #cbd5e1; border-radius:12px; margin: 12px;" onclick="$('#p_image').click();">
                                        <div class="mb-2 rounded-circle bg-white shadow-sm d-flex align-items-center justify-content-center" style="width:44px; height:44px;">
                                            <i class="fas fa-cloud-upload-alt text-primary" style="font-size:18px;"></i>
                                        </div>
                                        <span class="font-weight-bold text-xs text-dark mb-1"><?= $isTr ? 'Görsel Yüklemek İçin Tıklayın' : 'Click to Upload Image' ?></span>
                                        <span class="text-muted" style="font-size:10px;"><?= $isTr ? 'PNG, JPG, WEBP (Max 5MB)' : 'PNG, JPG, WEBP (Max 5MB)' ?></span>
                                    </div>
                                    
                                    <img id="p_image_preview" src="" class="d-none"
                                        style="max-width:100%; max-height:100%; object-fit: contain;">
                                </div>
                                <div class="p-4">
                                    <div class="form-group mb-4 d-none" id="p_image_group">
                                        <label
                                            class="small font-weight-bold text-uppercase text-muted d-block mb-2"><?= $isTr ? 'Görsel Yükle' : 'Upload Image' ?></label>
                                        <div class="custom-file" style="border-radius:10px;">
                                            <input type="file" name="image" id="p_image" class="custom-file-input"
                                                onchange="$(this).next('.custom-file-label').html(this.files[0].name)">
                                            <label class="custom-file-label"
                                                for="p_image"><?= $isTr ? 'Dosya Seç...' : 'Browse' ?></label>
                                        </div>
                                    </div>

                                    <div class="form-group mb-4 d-none" id="p_inventory_type_group">
                                        <label
                                            class="small font-weight-bold text-uppercase text-muted d-block mb-2"><?= $isTr ? 'Envanter Türü' : 'Type' ?></label>
                                        <select name="inventory_type" class="form-control shadow-sm"
                                            id="p_inventory_type_select"
                                            style="border-radius:12px; height:45px; font-weight:600;">
                                            <option value="asset">Demirbaş</option>
                                            <option value="accessory">Aksesuar</option>
                                            <option value="consumable">Sarf Malzeme</option>
                                            <option value="component">Bileşen</option>
                                            <option value="license">Lisans</option>
                                        </select>
                                    </div>

                                    <div class="form-group mb-0 d-none" id="p_parent_group">
                                        <label
                                            class="small font-weight-bold text-uppercase text-muted d-block mb-2"><?= $isTr ? 'Üst Kategori' : 'Parent' ?></label>
                                        <select name="parent_id" id="p_parent_id" class="form-control shadow-sm"
                                            style="border-radius:12px; height:45px;">
                                            <option value="">-- Üst Seçin --</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>


                    </div>
                </div>
            </div>

            <div class="modal-footer border-0 p-4" style="background: #fff;">
                <div class="w-100 d-flex justify-content-between align-items-center">
                    <button type="button" class="btn btn-link text-muted font-weight-bold p-0 text-decoration-none"
                        data-dismiss="modal"><?= __("cancel") ?></button>
                    <button type="submit" class="btn btn-primary px-5 py-3 shadow-lg hover-scale"
                        style="border-radius:16px; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); border:none; font-weight: 700; transition: all 0.3s;">
                        <i class="fas fa-save mr-2"></i><?= __("save") ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
    .hover-scale:hover {
        transform: scale(1.02);
    }

    .letter-spacing-1 {
        letter-spacing: 0.05em;
    }

    .transition-all {
        transition: all 0.2s ease-in-out;
    }

    #p_image_preview {
        transition: transform 0.3s;
    }

    #p_image_preview:hover {
        transform: scale(1.05);
    }

    .shadow-2xl {
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }

    .custom-file-label::after {
        content: "<?= $isTr ? 'Gözat' : 'Browse' ?>";
        background: #6366f1;
        color: white;
        border: none;
        border-radius: 0 10px 10px 0;
    }

    /* DARK MODE ASSET MODAL & FORM TEXT FIXES */
    body.dark-mode label,
    body.dark-mode .modal label {
        color: #e2e8f0 !important;
        font-weight: 600 !important;
    }
    body.dark-mode .modal .text-muted,
    body.dark-mode .modal label.text-muted,
    body.dark-mode .modal small.text-muted {
        color: #cbd5e1 !important;
    }

    /* Form Controls & Inputs in Dark Mode */
    body.dark-mode .modal .form-control,
    body.dark-mode .modal input.form-control,
    body.dark-mode .modal select.form-control,
    body.dark-mode .modal textarea.form-control {
        background-color: #172033 !important;
        color: #f8fafc !important;
        border: 1px solid #2b3952 !important;
    }

    /* Chrome / Safari Autofill White Background Fix in Dark Mode */
    body.dark-mode input:-webkit-autofill,
    body.dark-mode input:-webkit-autofill:hover,
    body.dark-mode input:-webkit-autofill:focus,
    body.dark-mode input:-webkit-autofill:active,
    body.dark-mode textarea:-webkit-autofill,
    body.dark-mode textarea:-webkit-autofill:hover,
    body.dark-mode textarea:-webkit-autofill:focus,
    body.dark-mode select:-webkit-autofill {
        -webkit-box-shadow: 0 0 0px 1000px #172033 inset !important;
        -webkit-text-fill-color: #f8fafc !important;
        caret-color: #f8fafc !important;
        transition: background-color 5000s ease-in-out 0s !important;
    }

    body.dark-mode .modal .form-control:disabled,
    body.dark-mode .modal .form-control[readonly],
    body.dark-mode .modal input:disabled,
    body.dark-mode .modal input[readonly],
    body.dark-mode .modal select:disabled {
        background-color: #111827 !important;
        color: #f1f5f9 !important;
        -webkit-text-fill-color: #f1f5f9 !important;
        border-color: #1f293d !important;
        opacity: 0.95 !important;
    }

    body.dark-mode .modal .select2-container--bootstrap4 .select2-selection__rendered {
        color: #f8fafc !important;
    }

    /* High Contrast Select2 Placeholder ("Seçiniz...") */
    body.dark-mode .select2-container--bootstrap4 .select2-selection--single .select2-selection__placeholder,
    body.dark-mode .select2-selection__placeholder,
    body.dark-mode .form-control::placeholder,
    body.dark-mode input::placeholder,
    body.dark-mode textarea::placeholder {
        color: #cbd5e1 !important;
        opacity: 0.95 !important;
    }

    /* Column Visibility (Sütunlar) Button & Dropdown in Dark Mode */
    body.dark-mode #colVisDropdown,
    body.dark-mode .btn-white,
    body.dark-mode button.btn-white {
        background-color: #172033 !important;
        color: #f8fafc !important;
        border-color: #2b3952 !important;
    }
    body.dark-mode #colVisDropdown i {
        color: #60a5fa !important;
    }
    body.dark-mode .dropdown-menu {
        background-color: #101726 !important;
        border: 1px solid #2b3952 !important;
        color: #f8fafc !important;
    }
    body.dark-mode .dropdown-menu .dropdown-header {
        color: #f8fafc !important;
        border-bottom-color: #2b3952 !important;
    }
    body.dark-mode .dropdown-menu label,
    body.dark-mode .dropdown-menu .custom-control-label {
        color: #e2e8f0 !important;
    }

    body.dark-mode .modal .card.bg-light,
    body.dark-mode .modal .card.bg-white {
        background-color: #131b2e !important;
        border: 1px solid #1f293d !important;
        color: #f8fafc !important;
    }
</style>

<!-- Export Customization Modal -->
<div class="modal fade" id="exportCustomizeModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <div class="modal-header bg-primary text-white border-0 py-3" style="border-radius:20px 20px 0 0;">
                <div class="d-flex align-items-center">
                    <i class="fas fa-file-export fa-lg mr-2"></i>
                    <h5 class="modal-title font-weight-bold mb-0" id="exportModalTitle"><?= __("export_customize_pdf") ?></h5>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="exportType" value="pdf">
                
                <!-- Excel Warning Alert -->
                <div id="excelWarning" class="alert alert-warning d-none shadow-sm mb-4" role="alert" style="font-size: 8.5pt; border-radius: 12px; border-left: 4px solid #f59e0b; line-height: 1.4;">
                    <i class="fas fa-exclamation-triangle mr-2 text-warning"></i>
                    <?= $isTr ? '<strong>Excel:</strong> İndirildikten sonra herkes tarafından hücreleri kolayca düzenlenebilir. Bu durum, teslim/iade raporunun hukuki ve idari geçerliliğini (yani "Zimmet Tutanağı" vasfını) ortadan kaldırır.' : '<strong>Excel:</strong> Once downloaded, cells can be easily edited by anyone. This voids the legal and administrative validity of the delivery/return report (i.e. "Handover Protocol" status).' ?>
                </div>
                
                <!-- Data Scope -->
                <div class="form-group mb-4">
                    <label class="font-weight-bold text-dark mb-2"><i class="fas fa-database text-primary mr-2"></i><?= __("data_scope") ?></label>
                    <div class="custom-control custom-radio mb-2">
                        <input type="radio" id="scope_current" name="exportScope" class="custom-control-input" value="current" checked>
                        <label class="custom-control-label" for="scope_current"><?= __("current_page_only") ?></label>
                    </div>
                    <div class="custom-control custom-radio">
                        <input type="radio" id="scope_all" name="exportScope" class="custom-control-input" value="all">
                        <label class="custom-control-label" for="scope_all"><?= __("all_filtered_records") ?></label>
                    </div>
                </div>

                <!-- Page Orientation (Only for PDF) -->
                <div class="form-group mb-4" id="exportOrientationGroup">
                    <label class="font-weight-bold text-dark mb-2"><i class="fas fa-arrows-alt text-primary mr-2"></i><?= __("page_orientation") ?></label>
                    <div class="d-flex">
                        <div class="custom-control custom-radio mr-4">
                            <input type="radio" id="orient_landscape" name="exportOrientation" class="custom-control-input" value="landscape" checked>
                            <label class="custom-control-label" for="orient_landscape"><?= __("landscape") ?></label>
                        </div>
                        <div class="custom-control custom-radio">
                            <input type="radio" id="orient_portrait" name="exportOrientation" class="custom-control-input" value="portrait">
                            <label class="custom-control-label" for="orient_portrait"><?= __("portrait") ?></label>
                        </div>
                    </div>
                </div>

                <!-- Paper Size (Only for PDF) -->
                <div class="form-group mb-4" id="exportPaperSizeGroup">
                    <label class="font-weight-bold text-dark mb-2"><i class="fas fa-file text-primary mr-2"></i><?= $isTr ? 'Kağıt Boyutu' : 'Paper Size' ?></label>
                    <div class="d-flex">
                        <div class="custom-control custom-radio mr-4">
                            <input type="radio" id="paper_a4" name="exportPaperSize" class="custom-control-input" value="a4" checked>
                            <label class="custom-control-label" for="paper_a4">A4</label>
                        </div>
                        <div class="custom-control custom-radio">
                            <input type="radio" id="paper_a3" name="exportPaperSize" class="custom-control-input" value="a3">
                            <label class="custom-control-label" for="paper_a3">A3</label>
                        </div>
                    </div>
                </div>

                <!-- Report Theme Color -->
                <div class="form-group mb-4" id="exportColorGroup">
                    <label class="font-weight-bold text-dark mb-2"><i class="fas fa-palette text-primary mr-2"></i><?= $isTr ? 'Rapor Tema Rengi' : 'Report Theme Color' ?></label>
                    <div class="d-flex align-items-center">
                        <input type="color" id="exportThemeColor" name="exportThemeColor" class="form-control mr-3" value="#ea580c" style="width: 60px; height: 38px; padding: 2px; border-radius: 8px; cursor: pointer;">
                        <span class="text-muted small"><?= $isTr ? 'Tablo başlıkları için bir renk seçin.' : 'Select a color for the table headers.' ?></span>
                    </div>
                </div>



                <!-- Technical Details Option (Visible when view === 'assets') -->
                <div class="form-group mb-4 d-none" id="exportTechDetailsGroup">
                    <label class="font-weight-bold text-dark mb-2"><i class="fas fa-microchip text-primary mr-2"></i><?= $isTr ? 'Teknik Özellikler' : 'Technical Specifications' ?></label>
                    <div class="custom-control custom-checkbox mb-2">
                        <input type="checkbox" id="exportTechDetails" class="custom-control-input">
                        <label class="custom-control-label" for="exportTechDetails"><?= $isTr ? 'Teknik detayları ayrı sütunlar olarak ekle' : 'Include technical details as separate columns' ?></label>
                    </div>
                    
                    <div class="mt-3 pl-3 pt-2 border-left d-none" id="exportTechSubColumnsContainer" style="border-left-width: 3px !important; border-left-color: #ea580c !important;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small font-weight-bold text-dark"><?= $isTr ? 'Rapora Eklenecek Teknik Özellikleri Seçin:' : 'Select Specs to Include:' ?></span>
                            <div>
                                <button type="button" class="btn btn-xs btn-link p-0 text-primary mr-2" onclick="$('.tech-sub-cb').prop('checked', true);"><?= $isTr ? 'Tümünü Seç' : 'Select All' ?></button>
                                <button type="button" class="btn btn-xs btn-link p-0 text-muted" onclick="$('.tech-sub-cb').prop('checked', false);"><?= $isTr ? 'Temizle' : 'Clear' ?></button>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_ip" value="IP Adresi" checked>
                                    <label class="custom-control-label small" for="tech_col_ip">IP Adresi</label>
                                </div>
                            </div>
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_mac" value="MAC Adresi" checked>
                                    <label class="custom-control-label small" for="tech_col_mac">MAC Adresi</label>
                                </div>
                            </div>
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_cpu" value="İşlemci (CPU)" checked>
                                    <label class="custom-control-label small" for="tech_col_cpu">İşlemci (CPU)</label>
                                </div>
                            </div>
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_ram" value="Bellek (RAM)" checked>
                                    <label class="custom-control-label small" for="tech_col_ram">Bellek (RAM)</label>
                                </div>
                            </div>
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_disk" value="Disk" checked>
                                    <label class="custom-control-label small" for="tech_col_disk">Disk</label>
                                </div>
                            </div>
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_gpu" value="Ekran Kartı (GPU)">
                                    <label class="custom-control-label small" for="tech_col_gpu">Ekran Kartı (GPU)</label>
                                </div>
                            </div>
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_os" value="İşletim Sistemi" checked>
                                    <label class="custom-control-label small" for="tech_col_os">İşletim Sistemi</label>
                                </div>
                            </div>
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_mainboard" value="Anakart">
                                    <label class="custom-control-label small" for="tech_col_mainboard">Anakart</label>
                                </div>
                            </div>
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_monitor" value="Monitör">
                                    <label class="custom-control-label small" for="tech_col_monitor">Monitör</label>
                                </div>
                            </div>
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_antivirus" value="Antivirus">
                                    <label class="custom-control-label small" for="tech_col_antivirus">Antivirus</label>
                                </div>
                            </div>
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_licenses" value="Ürün Anahtarı / Lisanslar" checked>
                                    <label class="custom-control-label small" for="tech_col_licenses"><?= $isTr ? 'Lisanslar / Ürün Anahtarları' : 'Licenses / Product Keys' ?></label>
                                </div>
                            </div>
                            <?php
                            $cfLabelsList = [];
                            try {
                                $cfLabelsList = $pdo->query("SELECT DISTINCT field_label FROM inventory_custom_fields WHERE field_label IS NOT NULL AND field_label != ''")->fetchAll(PDO::FETCH_COLUMN);
                            } catch (Throwable $e) {}
                            foreach ($cfLabelsList as $cfIdx => $cfLabel):
                            ?>
                            <div class="col-6 mb-1">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input tech-sub-cb" id="tech_col_cf_<?= $cfIdx ?>" value="<?= htmlspecialchars($cfLabel) ?>" checked>
                                    <label class="custom-control-label small" for="tech_col_cf_<?= $cfIdx ?>"><?= htmlspecialchars($cfLabel) ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Columns to display -->
                <div class="form-group mb-0">
                    <label class="font-weight-bold text-dark mb-2"><i class="fas fa-columns text-primary mr-2"></i><?= __("columns_to_show") ?></label>
                    <div id="exportColumnsContainer" class="row">
                        <!-- Checkboxes will be dynamically populated here -->
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 bg-light" style="border-radius:0 0 20px 20px;">
                <div class="w-100 d-flex justify-content-between align-items-center">
                    <button type="button" class="btn btn-secondary px-4 rounded-pill" data-dismiss="modal"><?= __("cancel") ?></button>
                    <button type="button" class="btn btn-primary px-4 rounded-pill font-weight-bold shadow-sm" onclick="executeExport()" id="btnExecuteExport">
                        <i class="fas fa-file-pdf mr-2"></i><?= __("create_pdf") ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Supplier Details Modal -->
<div class="modal fade" id="supplierSummaryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <div class="modal-header border-0 py-3" style="border-radius:20px 20px 0 0;">
                <div class="d-flex align-items-center">
                    <div class="p-2 rounded-lg mr-3 supp-header-icon-box" style="background:rgba(99,102,241,0.15);">
                        <i class="fas fa-industry" style="color:#6366f1;"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold mb-0" id="suppModalTitle">Supplier Name</h5>
                        <p class="small mb-0 supp-header-subtext">
                            <?= $isTr ? 'Tedarikçi özeti ve varlık listesi' : 'Supplier summary and asset list' ?></p>
                    </div>
                </div>
                <button type="button" class="close supp-close-btn" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-0" id="suppModalBody" style="border-radius:0 0 20px 20px;">
                <div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x" style="color:#6366f1;"></i></div>
            </div>
        </div>
    </div>
</div>

<?php
$defaultAgreementTr = 'Aşağıda donanımsal detayları belirtilerek tarafıma teslim edilen envanteri, sağlam ve çalışır durumda teslim aldığımı, kullanımına ve temizliğine özen göstereceğimi, arıza veya hata durumunda Bilgi İşlem Personeli\'ni bilgilendireceğimi ve kendi başıma müdahale etmeyeceğimi, iş amacı dışında kullanmayacağımı ve başkasına kullandırmayacağımı, Bilgi İşlem Personeli\'nin izni olmadan donanımsal veya yazılımsal değişiklik yapmayacağımı, yazılım kurmayacağımı ve şifre belirlemeyeceğimi, her türlü aktivitenin (e-posta kayıtları, anlık mesajlaşma yazılımları, ziyaret edilen web siteleri, kopyalanan ve silinen dosyalar, telefon görüşmeleri, donanım kimlikleri, kullanıcı hesapları vb. gibi) Bilgi İşlem birimi tarafından uygun teknik yöntemlerle kayıt altında tutulduğunu ve ihtiyaç durumunda bu kayıtlara bakılabildiğini, bu teslim tutanağı ile birlikte ekte tarafıma teslim edilen "Donanım Kullanma Talimatı"na uyacağımı beyan ve taahhüt ederim.';
$defaultAgreementEn = 'I hereby acknowledge that I have received the assets specified below in good working condition, agree to use them carefully and keep them clean. I agree to notify IT staff in case of any failure or error and will not attempt to repair it myself. I agree not to use the assets for non-work purposes or allow others to use them. I will not make any hardware or software changes, install software, or set passwords without IT authorization. I am aware that all activities (email records, instant messaging, web browsing, file operations, hardware IDs, user accounts, etc.) are monitored and recorded by the IT department for security and audit purposes.';

$activeAgreement = $isTr 
    ? s('inv_signature_agreement_tr', s('inv_signature_agreement', $defaultAgreementTr)) 
    : s('inv_signature_agreement_en', $defaultAgreementEn);
?>
<!-- Signature Modal -->
<div class="modal fade" id="signatureModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <div class="modal-header bg-light border-0 py-3">
                <div class="d-flex align-items-center">
                    <div class="bg-primary-soft p-2 rounded-lg mr-3" style="background: rgba(79, 70, 229, 0.1); border-radius: 8px;">
                        <i class="fas fa-signature text-primary" style="color: #4f46e5;"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold mb-0 text-dark"><?= $isTr ? 'Zimmet Onay & İmza' : 'Asset Signature Approval' ?></h5>
                        <p class="small text-muted mb-0"><?= $isTr ? 'Lütfen sözleşmeyi okuyup imzalayarak onaylayın' : 'Please read the agreement and sign to confirm' ?></p>
                    </div>
                </div>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="sig_asset_id">
                <input type="hidden" id="sig_signature_id">
                <input type="hidden" id="sig_item_type">
                <input type="hidden" id="sig_notes" value="">
                
                <div class="form-group mb-3">
                    <label id="sig_agreement_label" class="small font-weight-bold text-muted text-uppercase mb-2 d-block"><i class="fas fa-file-contract mr-1 text-primary"></i> <?= $isTr ? 'Zimmet Sözleşmesi Metni (Tutanak Legal Metni)' : 'Assignment Agreement Text' ?></label>
                    <div id="sig_agreement_container" class="p-3 border text-justify bg-light text-muted" style="max-height:150px; overflow-y:auto; font-size:11px; line-height:1.6; border-radius:10px; border-color:#cbd5e1 !important; color:#475569 !important;">
                        <?= htmlspecialchars_decode(html_entity_decode((string)$activeAgreement, ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                </div>
                
                <div class="row mb-3" id="signatureCanvasRow">
                    <div class="col-md-6 border-right" id="personnelCanvasContainer">
                        <label class="small font-weight-bold text-muted text-uppercase mb-2 d-block"><?= $isTr ? 'Teslim Alan (Personel)' : 'Personnel Canvas' ?></label>
                        <div class="border rounded bg-white shadow-inner overflow-hidden position-relative" style="height:200px; border-color: #cbd5e1 !important;">
                            <canvas id="signature-pad" style="width:100%; height:100%; touch-action:none;"></canvas>
                            <button type="button" id="clear-signature" class="btn btn-xs btn-outline-secondary position-absolute" style="right:10px; bottom:10px; border-radius:6px; font-weight:600;">
                                <i class="fas fa-eraser mr-1"></i> <?= $isTr ? 'Temizle' : 'Clear' ?>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6" id="adminCanvasContainer">
                        <label class="small font-weight-bold text-muted text-uppercase mb-2 d-block"><?= $isTr ? 'Teslim Eden (Admin)' : 'Admin Canvas' ?></label>
                        <div class="border rounded bg-white shadow-inner overflow-hidden position-relative" style="height:200px; border-color: #cbd5e1 !important;">
                            <canvas id="signature-pad-admin" style="width:100%; height:100%; touch-action:none;"></canvas>
                            <button type="button" id="clear-signature-admin" class="btn btn-xs btn-outline-secondary position-absolute" style="right:10px; bottom:10px; border-radius:6px; font-weight:600;">
                                <i class="fas fa-eraser mr-1"></i> <?= $isTr ? 'Temizle' : 'Clear' ?>
                            </button>
                        </div>
                        <div class="form-group mt-2 mb-0" id="adminNameContainer">
                            <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Teslim Eden Yetkili Adı (İsteğe Bağlı)' : 'Deliverer Name (Optional)' ?></label>
                            <input type="text" id="sig_admin_name" class="form-control form-control-sm bg-light border-0" style="border-radius:6px;" placeholder="<?= $isTr ? 'Örn: İnsan Kaynakları / Diğer Admin' : 'e.g. HR / Other Admin' ?>">
                        </div>
                        <?php if (in_array($current_user_role, [1, 3])): ?>
                        <div class="mt-2 text-right" id="bypassContainer" style="display:none;">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="sig_bypass">
                                <label class="custom-control-label small font-weight-bold text-danger" for="sig_bypass" style="cursor:pointer;"><?= $isTr ? 'Personel İmzasını Atla (Pasif/Yok)' : 'Bypass Personnel Sig' ?></label>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="custom-control custom-checkbox text-left mb-2">
                    <input type="checkbox" class="custom-control-input" id="sig_confirm" style="cursor:pointer;">
                    <label class="custom-control-label small font-weight-bold text-muted" for="sig_confirm" style="cursor:pointer; line-height: 1.5;">
                        <?= $isTr ? 'Yukarıdaki zimmet sözleşmesi metnini okudum, eksiksiz ve çalışır vaziyette teslim aldığımı beyan ve taahhüt ederim.' : 'I declare that I have read the assignment agreement text above and received the asset in fully working condition.' ?>
                    </label>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 bg-light">
                <div class="w-100 d-flex justify-content-between align-items-center">
                    <button type="button" class="btn btn-link text-muted font-weight-bold p-0 text-decoration-none" data-dismiss="modal"><?= __("cancel") ?></button>
                    <button type="button" id="btn_confirm_sig" class="btn btn-success px-4 py-2 shadow-sm" style="border-radius:12px; font-weight: 700; transition: all 0.3s;" disabled>
                        <i class="fas fa-check-circle mr-2"></i><?= $isTr ? 'Onayla ve Kaydet' : 'Confirm & Save' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/return_modal.php'; ?>

<script src="plugins/sweetalert2.all.min.js"></script>
<script src="plugins/jspdf.umd.min.js"></script>
<script src="plugins/jspdf.plugin.autotable.min.js"></script>
<script src="plugins/signature_pad.umd.min.js"></script>

<script>
    if (typeof isTr === 'undefined') var isTr = <?= $isTr ? 'true' : 'false' ?>;
    const inventoryCsrfToken = '<?= csrf_token() ?>';
    window.translateStatusName = function (name) {
        const map = {
            'Hazır': isTr ? 'Hazır' : 'Ready',
            'Ready': isTr ? 'Hazır' : 'Ready',
            'Atanmış': isTr ? 'Atanmış' : 'Assigned',
            'Assigned': isTr ? 'Atanmış' : 'Assigned',
            'Arızalı': isTr ? 'Arızalı' : 'Faulty',
            'Faulty': isTr ? 'Arızalı' : 'Faulty',
            'Hurda': isTr ? 'Hurda' : 'Scrap',
            'Scrap': isTr ? 'Hurda' : 'Scrap',
            'Hurdaya Ayrılmış': isTr ? 'Hurda' : 'Scrap',
            'İmza Bekliyor': isTr ? 'İmza Bekliyor' : 'Pending Signature',
            'Pending Signature': isTr ? 'İmza Bekliyor' : 'Pending Signature',
            'Beklemede': isTr ? 'Beklemede' : 'Pending',
            'Pending': isTr ? 'Beklemede' : 'Pending'
        };
        return map[name] || name;
    };

    // Fix for Bootstrap modal blocking SweetAlert2 inputs
    $(document).on('focusin', function (e) {
        if ($(e.target).closest(".swal2-container").length) {
            e.stopImmediatePropagation();
        }
    });
    if (typeof placeholderImg === 'undefined') {
        var placeholderImg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%23f3f4f6'/%3E%3Ctext x='50%25' y='50%25' dominant-baseline='middle' text-anchor='middle' font-family='sans-serif' font-size='10' fill='%239ca3af'%3E<?= $isTr ? 'Resim Yok' : 'No Image' ?>%3C/text%3E%3C/svg%3E";
    }

    const lookupData = {
        categories: <?= json_encode($all_categories, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
        models: [], // Highly optimized: AJAX autocomplete Select2 used instead
        suppliers: [], // Highly optimized: AJAX autocomplete Select2 used instead
        locations: <?= json_encode($all_locations, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
        companies: <?= json_encode($all_companies, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
        departments: [], // Highly optimized: AJAX autocomplete Select2 used instead
        status_labels: <?= json_encode($all_status_labels, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
        users: [], // Highly optimized: AJAX autocomplete Select2 used instead
        allAssets: [], 
        manufacturers: [] // Highly optimized: AJAX autocomplete Select2 used instead
    };

    // Instance Picker Logic
    window.openInstancePicker = function (name, catId, action) {
        const modal = $('#instancePickerModal');
        const body = $('#instanceListBody');
        const title = $('#instancePickerTitle');
        const subtitle = $('#instancePickerSubtitle');

        let titleText = (isTr ? 'Envanter Detayları / Seri No' : 'Inventory Details / Serial Nos');
        if (action === 'assign') titleText = (isTr ? 'Parça Ata' : 'Assign Instance');
        else if (action === 'checkin') titleText = (isTr ? 'İade Al' : 'Return Instance');
        else if (action === 'transfer') titleText = (isTr ? 'Parça Taşı' : 'Transfer Instance');
        else if (action === 'scrap') titleText = (isTr ? 'Hurdaya Ayır (Seçmeli)' : 'Move to Scrap (Selectable)');

        title.text(titleText);
        subtitle.text(name);
        body.html('<tr><td colspan="4" class="text-center py-4"><i class="fas fa-spinner fa-spin mr-2"></i>' + (isTr ? 'Yükleniyor...' : 'Loading...') + '</td></tr>');

        modal.modal('show');

        $.getJSON('', { get_component_instances: name, cat_id: catId }, function (data) {
            body.empty();
            if (data.length === 0) {
                body.html('<tr><td colspan="4" class="text-center py-4 text-muted">' + (isTr ? 'Kayıt bulunamadı.' : 'No instances found.') + '</td></tr>');
                return;
            }

            data.forEach(function (ins) {
                const isAssigned = !!ins.assigned_to;
                const isDamaged = ins.status == 0;

                let statusHtml = '';
                if (isAssigned) {
                    const icon = ins.assigned_to_type === 'asset' ? 'fa-laptop' : 'fa-user';
                    const linkUrl = ins.assigned_to_type === 'asset' ? 'varlik-detay/' + ins.assigned_to_id + '?view=assets' : 'kullanici-detay/' + ins.assigned_to_id;
                    statusHtml = '<a href="' + linkUrl + '" class="badge badge-soft-primary p-2 no-underline hover-opacity-75"><i class="fas ' + icon + ' mr-1"></i> ' + escHtml(ins.assigned_to) + '</a>';
                } else if (isDamaged) {
                    statusHtml = '<span class="badge badge-soft-danger p-2"><i class="fas fa-exclamation-triangle mr-1"></i> ' + (isTr ? 'Arızalı' : 'Faulty') + '</span>';
                } else {
                    statusHtml = '<span class="badge badge-light p-2 text-success"><i class="fas fa-check-circle mr-1"></i> ' + (isTr ? 'Boşta' : 'Available') + '</span>';
                }

                let btnHtml = '<div class="d-flex align-items-center">';
                const closePicker = "$('#instancePickerModal').modal('hide');";

                if (action === 'scrap') {
                    if (!isAssigned) {
                        btnHtml += '<button class="btn btn-sm btn-warning shadow-sm border-0 px-3" style="border-radius:8px; font-weight:600;" onclick="' + closePicker + 'confirmScrap(' + ins.id + ', \'components\')"><i class="fas fa-dumpster mr-1"></i> ' + (isTr ? 'Hurda' : 'Scrap') + '</button>';
                    } else {
                        btnHtml += '<span class="badge badge-light p-2 text-muted">' + (isTr ? 'Zimmetli' : 'Assigned') + '</span>';
                    }
                } else if (isAssigned) {
                    const escapedAssignedName = (ins.assigned_to || '').replace(/'/g, "\\'");
                    btnHtml += '<button class="btn btn-sm btn-outline-danger shadow-sm px-3 mr-2" style="border-radius:8px; font-weight:600;" onclick="' + closePicker + 'checkInItem(' + ins.id + ', \'components\', \'' + ins.assigned_to_type + '\', \'' + escapedAssignedName + '\')"><i class="fas fa-undo mr-1"></i> ' + (isTr ? 'İade' : 'Return') + '</button>';
                    btnHtml += '<button class="btn btn-sm text-white shadow-sm px-3" style="background:#6366f1; border-radius:8px; font-weight:600;" onclick="' + closePicker + 'checkOutItem(' + ins.id + ', \'components\', 0, true)"><i class="fas fa-exchange-alt mr-1"></i> ' + (isTr ? 'Taşı' : 'Transfer') + '</button>';
                } else if (isDamaged) {
                    btnHtml += '<span class="badge badge-soft-danger p-2" style="font-weight:600;"><i class="fas fa-exclamation-triangle mr-1"></i> ' + (isTr ? 'Arızalı' : 'Faulty') + '</span>';
                } else {
                    btnHtml += '<button class="btn btn-sm btn-success shadow-sm border-0 px-4" style="border-radius:8px; font-weight:600;" onclick="' + closePicker + 'checkOutItem(' + ins.id + ', \'components\', 1)"><i class="fas fa-share mr-1"></i> ' + (isTr ? 'Ata' : 'Assign') + '</button>';
                }

                // Individual Action Icons
                btnHtml += '<div class="ml-auto d-flex">';
                btnHtml += '<button class="btn btn-xs btn-light border ml-1" title="' + (isTr ? 'Düzenle' : 'Edit') + '" onclick="editInstanceSerial(' + ins.id + ', \'' + (ins.serial_no || '').replace(/'/g, "\\'") + '\')"><i class="fas fa-edit text-primary"></i></button>';
                if (!isAssigned) {
                    btnHtml += '<button class="btn btn-xs btn-light border ml-1" title="' + (isTr ? 'Sil' : 'Delete') + '" onclick="deleteInstance(' + ins.id + ')"><i class="fas fa-trash text-danger"></i></button>';
                }
                btnHtml += '</div>';
                btnHtml += '</div>';

                const row = '<tr>' +
                    '<td class="pl-4"><span class="text-muted small">#' + ins.id + '</span></td>' +
                    '<td class="font-weight-bold">' + escHtml(ins.serial_no || '—') + '</td>' +
                    '<td>' + statusHtml + '</td>' +
                    '<td class="text-right pr-4">' + btnHtml + '</td>' +
                    '</tr>';
                body.append(row);
            });
        });
    };

    window.editInstanceSerial = function (id, currentSerial) {
        Swal.fire({
            title: isTr ? 'Seri No Düzenle' : 'Edit Serial No',
            text: isTr ? 'Sadece bu parçaya ait seri numarasını günceller.' : 'Updates the serial number for this specific instance only.',
            input: 'text',
            inputValue: currentSerial,
            showCancelButton: true,
            confirmButtonText: isTr ? 'Kaydet' : 'Save',
            cancelButtonText: isTr ? 'İptal' : 'Cancel',
            inputAttributes: { autocapitalize: 'off', autofocus: 'true' }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: isTr ? 'Kaydediliyor...' : 'Saving...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                $.ajax({
                    url: '',
                    type: 'POST',
                    data: {
                        action: 'update_instance_serial',
                        ajax_action: 1,
                        asset_id: id,
                        serial_no: result.value,
                        csrf_token: inventoryCsrfToken
                    },
                    headers: {
                        'X-CSRF-TOKEN': inventoryCsrfToken
                    },
                    dataType: 'json',
                    success: function (res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', title: isTr ? 'Başarılı' : 'Success', timer: 1000, showConfirmButton: false }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire(isTr ? 'Hata' : 'Error', res.message || 'Error', 'error');
                        }
                    },
                });
            }
        });
    };

    window.deleteInstance = function (id) {
        Swal.fire({
            title: isTr ? 'Emin misiniz?' : 'Are you sure?',
            text: isTr ? 'Bu parça kalıcı olarak silinecektir.' : 'This instance will be permanently deleted.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: isTr ? 'Evet, sil' : 'Yes, delete',
            cancelButtonText: isTr ? 'İptal' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="csrf_token" value="' + inventoryCsrfToken + '">' +
                    '<input type="hidden" name="action" value="delete">' +
                    '<input type="hidden" name="view" value="components">' +
                    '<input type="hidden" name="asset_id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        });
    };

    function resetForm(view = 'assets') {
        const form = $('#assetForm');
        if (form.length) {
            form[0].reset();
            form.removeData('current-item');
        }
        $('#asset_id').val('');
        const titles = { 'assets': '<?= __("fixed_assets") ?>', 'licenses': '<?= __("licenses") ?>', 'accessories': '<?= __("accessories") ?>', 'consumables': '<?= __("consumables") ?>', 'components': '<?= __("components") ?>' };
        $('#modalTitle').html('<i class="fas fa-plus-circle mr-2"></i>' + (titles[view] || 'Item'));
        renderDynamicForm(view);
    }

    function renderDynamicForm(view) {
        const container = $('#dynamicFormFields');
        if (!container.length) return;
        let html = '';
        // Safer string construction to avoid embedded template-literal parsing issues
        const quickAddBtnInternal = function (type) {
            return '<button type="button" class="btn btn-xs btn-link p-0 ml-1 text-primary" onclick=\'addPredefined(' + JSON.stringify(type) + ', ' + JSON.stringify(view) + ')\'><i class="fas fa-plus-circle"></i></button>';
        };

        if (view === 'assets') {
            var parts = [];
            // Section 1: Basic Info
            parts.push('<div class="row"><div class="col-md-8"><div class="card border-0 bg-light mb-4" style="border-radius:20px;"><div class="card-body">');
            parts.push('<h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-info-circle mr-2"></i><?= __("basic_info") ?></h6><div class="row">');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold"><?= __("asset_name") ?> *</label><input type="text" name="name" id="name" class="form-control border-0 shadow-sm" required style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold"><?= __("asset_tag") ?> *</label><input type="text" name="asset_tag" id="asset_tag" class="form-control border-0 shadow-sm" required style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("model") ?> ' + quickAddBtnInternal('models') + '</label><select name="model_id" id="model_id" class="form-control select2-ajax" data-type="models"><option value=""><?= __("select") ?>...</option></select></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("manufacturer") ?> ' + quickAddBtnInternal('manufacturers') + '</label><select name="manufacturer_id" id="manufacturer_id" class="form-control select2-ajax" data-type="manufacturers"><option value=""><?= __("select") ?>...</option></select></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("category") ?> ' + quickAddBtnInternal('categories') + '</label><select name="category_id" id="category_id" class="form-control select2" onchange="onTypeChange()"><option value=""><?= __("select") ?>...</option>');

            parts.push(renderCategoryOptions('asset'));
            parts.push('</select></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("status") ?> ' + quickAddBtnInternal('status_labels') + '</label><select name="status_id" id="status" class="form-control select2"><option value=""><?= __("select") ?>...</option>');
            parts.push(lookupData.status_labels.filter(function(s) { 
                const nameLower = (s.name || '').toLowerCase().replace(/i̇/g, 'i').replace(/ı/g, 'i');
                return s.type !== 'pending' && nameLower.indexOf('imza bekliyor') === -1 && nameLower.indexOf('pending signature') === -1; 
            }).map(function (s) { 
                return '<option value="' + s.id + '">' + escHtml(window.translateStatusName(s.name)) + '</option>'; 
            }).join(''));
            parts.push('</select></div></div></div></div>');

            // Section 2: Purchase & Warranty
            parts.push('<div class="card border-0 bg-light mb-4" style="border-radius:20px;"><div class="card-body">');
            parts.push('<h6 class="font-weight-bold text-success mb-3"><i class="fas fa-shopping-cart mr-2"></i><?= __("purchase_info") ?></h6><div class="row">');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("supplier") ?> ' + quickAddBtnInternal('suppliers') + '</label><select name="supplier_id" id="supplier_id" class="form-control select2-ajax" data-type="suppliers"><option value=""><?= __("select") ?>...</option></select></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold"><?= __("purchase_date") ?></label><input type="date" name="purchase_date" id="purchase_date" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold"><?= __("purchase_cost") ?></label><div class="input-group shadow-sm" style="border-radius:10px; overflow:hidden;"><input type="number" step="0.01" name="purchase_cost" id="purchase_cost" class="form-control border-0"><select name="purchase_currency" id="purchase_currency" class="form-control border-0 bg-white" style="flex: 0 0 55px; padding-left: 5px;"><option value="TRY">₺</option><option value="USD">$</option><option value="EUR">€</option></select></div></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold"><?= __("order_number") ?></label><input type="text" name="order_number" id="order_number" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold"><?= __("warranty") ?> (<?= $isTr ? "Ay" : "Months" ?>)</label><input type="number" name="warranty_months" id="warranty_months" class="form-control border-0 shadow-sm" value="0" style="border-radius:10px;"></div></div></div></div>');

            // Section 3: Deployment
            parts.push('<div class="card border-0 bg-light mb-4" style="border-radius:20px;"><div class="card-body">');
            parts.push('<h6 class="font-weight-bold text-warning mb-3"><i class="fas fa-barcode mr-2"></i><?= $isTr ? "Seri No Bilgileri" : __("deployment_info") ?></h6><div class="row">');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("company") ?> ' + quickAddBtnInternal('companies') + '</label><select name="company_id" id="company_id" class="form-control select2"><option value=""><?= __("select") ?>...</option>');
            parts.push(lookupData.companies.map(function (c) { return '<option value="' + c.id + '">' + escHtml(c.name) + '</option>'; }).join(''));
            parts.push('</select></div>');

            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("department") ?> ' + quickAddBtnInternal('departments') + '</label><select name="department_id" id="department_id" class="form-control select2-ajax" data-type="departments"><option value=""><?= __("select") ?>...</option></select></div>');

            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("serial_no") ?></label><input type="text" name="serial_no" id="serial_no" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            parts.push('</div></div></div>');

            // NEW: Notes moved here to fill the gap
            parts.push('<div class="form-group mb-2" id="notesContainer">');
            parts.push('<label class="small font-weight-bold text-muted mb-1"><?= __("notes") ?></label>');
            parts.push('<textarea name="notes" id="notes" class="form-control bg-light border-0 shadow-none" rows="2" placeholder="<?= __("asset_notes_placeholder") ?>" style="border-radius:15px;"></textarea>');
            parts.push('</div>');

            parts.push('</div><div class="col-md-4">');
            // Image Preview
            parts.push('<div class="card bg-white border-0 shadow-sm mb-4" style="border-radius:20px;"><div class="card-body text-center">');
            parts.push('<label class="small font-weight-bold text-muted mb-3 text-uppercase letter-spacing-1"><?= $isTr ? 'Cihaz Resmi' : 'Asset Image' ?></label>');
            parts.push('<div id="asset_image_preview_container" class="mb-4 text-center" style="display:none;"><img id="asset_image_preview" src="" class="img-fluid rounded border shadow-sm" style="max-height:180px; object-fit:contain;" onerror="this.src=placeholderImg"><div id="asset_image_type_badge" class="mt-2 small"></div></div>');
            parts.push('<div class="custom-file mb-2"><input type="file" name="image" id="asset_image" class="custom-file-input" onchange="$(this).next(\'.custom-file-label\').html(this.files[0].name)"><label class="custom-file-label border-0 bg-light" for="asset_image" style="border-radius:10px;"><?= $isTr ? "Seç..." : "Choose" ?></label></div>');
            parts.push('</div></div>');

            // Tech Specs & Custom Fields (Containers)
            parts.push('<div id="technicalSpecsSection" style="display:none;"><div class="card border-0 bg-white shadow-sm mb-4" style="border-radius:20px;"><div class="card-body">');
            parts.push('<h6 class="font-weight-bold text-info mb-3"><i class="fas fa-microchip mr-2"></i><?= __("technical_specs") ?></h6><div id="specsContainer" class="row"></div></div></div></div>');
            parts.push('<div id="customFieldsCard" class="card border-0 bg-white shadow-sm mb-4" style="border-radius:20px; display:none;"><div class="card-body">');
            parts.push('<h6 class="font-weight-bold text-muted mb-3"><i class="fas fa-tags mr-2"></i><?= __("custom_fields") ?></h6><div id="dynamic-custom-fields-container" class="row"></div></div></div>');

            parts.push('</div></div>');
            html = parts.join('');
        } else if (view === 'licenses') {
            var parts = [];
            parts.push('<div class="card border-0 bg-light mb-4" style="border-radius:20px;"><div class="card-body">');
            parts.push('<h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-info-circle mr-2"></i><?= __("basic_info") ?></h6><div class="row">');
            parts.push('<div class="col-md-12 form-group mb-3"><label class="small font-weight-bold"><?= $isTr ? 'Yazılım / Lisans Adı' : 'Software / License Name' ?> *</label><input type="text" name="name" id="name" class="form-control border-0 shadow-sm" required style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("category") ?> ' + quickAddBtnInternal('categories') + '</label><select name="category_id" id="category_id" class="form-control select2"><option value=""><?= __("select") ?>...</option>');
            parts.push(renderCategoryOptions('license'));
            parts.push('</select></div>');
            parts.push('<div class="col-md-6 mb-3">' +
                '<div id="license_image_preview_container" class="mb-2 text-center" style="display:none;"><img id="license_image_preview" src="" class="img-thumbnail" style="max-height:100px; border-radius:10px;"></div>' +
                '<label class="small font-weight-bold text-uppercase">' + (isTr ? 'RESİM' : 'IMAGE') + '</label>' +
                '<div class="custom-file shadow-sm overflow-hidden" style="border-radius:10px;"><input type="file" name="image" class="custom-file-input" onchange="$(this).next(\'.custom-file-label\').html(this.files[0].name)"><label class="custom-file-label">' + (isTr ? 'Dosya Seç...' : 'Choose File...') + '</label></div>' +
                '</div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold"><?= __("seats") ?></label><input type="number" name="seats" id="seats" class="form-control border-0 shadow-sm" value="1" style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold"><?= __("license_key") ?></label><input type="text" name="license_key" id="license_key" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold"><?= __("expire_date") ?></label><input type="date" name="expire_date" id="expire_date" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold"><?= __("email") ?></label><input type="email" name="license_email" id="license_email" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("manufacturer") ?> ' + quickAddBtnInternal('manufacturers') + '</label><select name="manufacturer_id" id="manufacturer_id" class="form-control select2-ajax" data-type="manufacturers"><option value=""><?= __("select") ?>...</option></select></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("department") ?> ' + quickAddBtnInternal('departments') + '</label><select name="department_id" id="department_id" class="form-control select2-ajax" data-type="departments"><option value=""><?= __("select") ?>...</option></select></div>');
            parts.push('</div></div></div>');
            parts.push('<div class="card border-0 bg-light mb-4" style="border-radius:20px;"><div class="card-body">');
            parts.push('<h6 class="font-weight-bold text-success mb-3"><i class="fas fa-shopping-cart mr-2"></i><?= __("purchase_info") ?></h6><div class="row">');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("supplier") ?> ' + quickAddBtnInternal('suppliers') + '</label><select name="supplier_id" id="supplier_id" class="form-control select2-ajax" data-type="suppliers"><option value=""><?= __("select") ?>...</option></select></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("company") ?> ' + quickAddBtnInternal('companies') + '</label><select name="company_id" id="company_id" class="form-control select2"><option value=""><?= __("select") ?>...</option>');
            parts.push(lookupData.companies.map(function (c) { return '<option value="' + c.id + '">' + escHtml(c.name) + '</option>'; }).join(''));
            parts.push('</select></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold"><?= __("purchase_date") ?></label><input type="date" name="purchase_date" id="purchase_date" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold"><?= __("purchase_cost") ?></label><div class="input-group shadow-sm" style="border-radius:10px; overflow:hidden;"><input type="number" step="0.01" name="purchase_cost" id="purchase_cost" class="form-control border-0"><select name="purchase_currency" id="purchase_currency" class="form-control border-0 bg-white" style="flex: 0 0 55px; padding-left: 5px;"><option value="TRY">₺</option><option value="USD">$</option><option value="EUR">€</option></select></div></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold"><?= __("order_number") ?></label><input type="text" name="order_number" id="order_number" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            parts.push('</div></div></div>');

            parts.push('<div class="form-group mb-0" id="notesContainer">');
            parts.push('<label class="small font-weight-bold text-muted mb-1"><?= __("notes") ?></label>');
            parts.push('<textarea name="notes" id="notes" class="form-control bg-light border-0 shadow-none" rows="2" placeholder="<?= __("asset_notes_placeholder") ?>" style="border-radius:10px;"></textarea>');
            parts.push('</div>');

            html = parts.join('');
        } else {
            var parts = [];
            parts.push('<div class="card border-0 bg-light mb-4" style="border-radius:20px;"><div class="card-body">');
            parts.push('<h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-info-circle mr-2"></i><?= __("basic_info") ?></h6><div class="row">');
            var labelName = (view === 'accessories') ? (isTr ? 'Aksesuar Adı' : 'Accessory Name') : ((view === 'consumables') ? (isTr ? 'Sarf Malzemesi Adı' : 'Consumable Name') : (isTr ? 'Bileşen Adı' : 'Component Name'));
            parts.push('<div class="col-md-12 form-group mb-3"><label class="small font-weight-bold">' + labelName + ' *</label><input type="text" name="name" id="name" class="form-control border-0 shadow-sm" required style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("category") ?> ' + quickAddBtnInternal('categories') + '</label><select name="category_id" id="category_id" class="form-control select2"><option value=""><?= __("select") ?>...</option>');
            var optType = (view === 'accessories') ? 'accessory' : ((view === 'consumables') ? 'consumable' : 'component');
            parts.push(renderCategoryOptions(optType));
            parts.push('</select></div>');
            parts.push('<div class="col-md-6 mb-3">' +
                '<div id="' + (view === 'accessories' ? 'accessory' : (view === 'consumables' ? 'consumable' : 'component')) + '_image_preview_container" class="mb-2 text-center" style="display:none;"><img id="' + (view === 'accessories' ? 'accessory' : (view === 'consumables' ? 'consumable' : 'component')) + '_image_preview" src="" class="img-thumbnail" style="max-height:100px; border-radius:10px;"></div>' +
                '<label class="small font-weight-bold text-uppercase">' + (isTr ? 'RESİM' : 'IMAGE') + '</label>' +
                '<div class="custom-file shadow-sm overflow-hidden" style="border-radius:10px;"><input type="file" name="image" class="custom-file-input" onchange="$(this).next(\'.custom-file-label\').html(this.files[0].name)"><label class="custom-file-label">' + (isTr ? 'Dosya Seç...' : 'Choose File...') + '</label></div>' +
                '</div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold"><?= __("quantity") ?></label><input type="number" name="quantity" id="quantity" class="form-control border-0 shadow-sm" value="1" style="border-radius:10px;"></div>');
            if (view === 'consumables') {
                parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold"><?= __("min_qty") ?? ($isTr ? "Min. Miktar" : "Min. Qty") ?> <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip" title="<?= $isTr ? "Sarf malzeme sto\u011fu bu miktar\u0131n alt\u0131na d\u00fc\u015ft\u00fc\u011f\u00fcnde uyar\u0131 al\u0131rs\u0131n\u0131z." : "Receive an alert when stock falls below this quantity." ?>"></i></label><input type="number" name="min_qty" id="min_qty" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            }
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold"><?= __("serial_no") ?></label><input type="text" name="serial_no" id="serial_no" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("manufacturer") ?> ' + quickAddBtnInternal('manufacturers') + '</label><select name="manufacturer_id" id="manufacturer_id" class="form-control select2-ajax" data-type="manufacturers"><option value=""><?= __("select") ?>...</option></select></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("department") ?> ' + quickAddBtnInternal('departments') + '</label><select name="department_id" id="department_id" class="form-control select2-ajax" data-type="departments"><option value=""><?= __("select") ?>...</option></select></div>');
            parts.push('</div></div></div>');
            parts.push('<div class="card border-0 bg-light mb-4" style="border-radius:20px;"><div class="card-body">');
            parts.push('<h6 class="font-weight-bold text-success mb-3"><i class="fas fa-shopping-cart mr-2"></i><?= __("purchase_info") ?></h6><div class="row">');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("supplier") ?> ' + quickAddBtnInternal('suppliers') + '</label><select name="supplier_id" id="supplier_id" class="form-control select2-ajax" data-type="suppliers"><option value=""><?= __("select") ?>...</option></select></div>');
            parts.push('<div class="col-md-6 form-group mb-3"><label class="small font-weight-bold d-flex align-items-center"><?= __("company") ?> ' + quickAddBtnInternal('companies') + '</label><select name="company_id" id="company_id" class="form-control select2"><option value=""><?= __("select") ?>...</option>');
            parts.push(lookupData.companies.map(function (c) { return '<option value="' + c.id + '">' + escHtml(c.name) + '</option>'; }).join(''));
            parts.push('</select></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold"><?= __("purchase_date") ?></label><input type="date" name="purchase_date" id="purchase_date" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold"><?= __("purchase_cost") ?></label><div class="input-group shadow-sm" style="border-radius:10px; overflow:hidden;"><input type="number" step="0.01" name="purchase_cost" id="purchase_cost" class="form-control border-0"><select name="purchase_currency" id="purchase_currency" class="form-control border-0 bg-white" style="flex: 0 0 55px; padding-left: 5px;"><option value="TRY">₺</option><option value="USD">$</option><option value="EUR">€</option></select></div></div>');
            parts.push('<div class="col-md-4 form-group mb-3"><label class="small font-weight-bold"><?= __("order_number") ?></label><input type="text" name="order_number" id="order_number" class="form-control border-0 shadow-sm" style="border-radius:10px;"></div>');
            parts.push('</div></div></div>');

            parts.push('<div class="form-group mb-0" id="notesContainer">');
            parts.push('<label class="small font-weight-bold text-muted mb-1"><?= __("notes") ?></label>');
            parts.push('<textarea name="notes" id="notes" class="form-control bg-light border-0 shadow-none" rows="2" placeholder="<?= __("asset_notes_placeholder") ?>" style="border-radius:10px;"></textarea>');
            parts.push('</div>');

            html = parts.join('');
        }
        container.html(html);
        const select2Lang = {
            noResults: function () { return "<?= $isTr ? 'Sonuç bulunamadı' : 'No results found' ?>"; },
            searching: function () { return "<?= $isTr ? 'Aranıyor...' : 'Searching...' ?>"; }
        };
        $('.select2').select2({ theme: 'bootstrap4', width: '100%', language: select2Lang });

        // Dynamic paginated AJAX Select2 initialization for large lists
        $('.select2-ajax').each(function () {
            const $el = $(this);
            const type = $el.data('type');
            $el.select2({
                theme: 'bootstrap4',
                width: '100%',
                language: select2Lang,
                ajax: {
                    url: 'varliklar' + (window.location.search || ''),
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            search_select2: type,
                            q: params.term,
                            page: params.page || 1
                        };
                    },
                    processResults: function (data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.results,
                            pagination: {
                                more: data.pagination.more
                            }
                        };
                    },
                    cache: false
                },
                placeholder: isTr ? 'Seçiniz...' : 'Select...',
                allowClear: true,
                minimumInputLength: 0
            });
        });
    }

    function addAssetByView(view) {
        if (view === 'predefined') {
            const urlParams = new URLSearchParams(window.location.search);
            const type = urlParams.get('type') || 'categories';
            const context = urlParams.get('cat_id') || '';
            addPredefined(type, context);
            return;
        }
        resetForm(view);
        $('#assetModal').modal('show');
    }

    // ENHANCEMENT: Load accessory instances for transfer selection
    function loadAccessoryInstances(itemId, view) {
        const select = $('#assign_selected_instance');
        select.empty().append('<option value="">' + (isTr ? 'Yükleniyor...' : 'Loading...') + '</option>');
        
        fetch('varliklar?get_accessory_instances=' + itemId + '&view=' + view + '&_=' + new Date().getTime())
            .then(r => r.json())
            .then(data => {
                select.empty();
                const instances = data.instances || [];
                if (instances && Array.isArray(instances) && instances.length > 0) {
                    let assignedCount = 0;
                    let lastAssignedVal = '';
                    instances.forEach((instance, idx) => {
                        let label = '';
                        if (instance.type === 'stock') {
                            label = (isTr ? 'BOŞ STOK' : 'IN STOCK') + ' (' + instance.quantity + ' ' + (isTr ? 'Adet' : 'Units') + ')';
                        } else {
                            label = (instance.assigned_to || '???') + ' (' + instance.quantity + ' ' + (isTr ? 'Adet' : 'Units') + ')';
                            assignedCount++;
                            lastAssignedVal = instance.id;
                        }
                        const option = document.createElement('option');
                        option.value = instance.id; // 'stock' or the checkout ID
                        option.textContent = label;
                        option.dataset.type = instance.type;
                        option.dataset.qty = instance.quantity;
                        select.append(option);
                    });
                    select.prepend('<option value="">' + (isTr ? 'Kaynak Seçiniz...' : 'Select Source...') + '</option>');
                    
                    // Auto-select if transferring and exactly 1 assigned option exists
                    const isTransfer = $('#assign_is_transfer').val() === '1';
                    if (isTransfer && assignedCount === 1) {
                        select.val(lastAssignedVal).trigger('change');
                    }
                } else {
                    select.append('<option value="">' + (isTr ? 'Kullanılabilir birim yok' : 'No available units') + '</option>');
                }
                select.trigger('change');
            })
            .catch(err => {
                console.error('Error loading instances:', err);
                select.empty().append('<option value="">' + (isTr ? 'Hata oluştu' : 'Error occurred') + '</option>');
            });
    }

    window.checkInItem = function (id, view, targetType = 'user', assignedName = '') {
        if (view === 'consumables' || view === 'accessories' || view === 'licenses') {
            $.get('varliklar', { get_assignments: id, assign_view: view }, function(data) {
                if (!data || data.length === 0) {
                    Swal.fire(isTr ? 'Atanmış kayıt bulunamadı.' : 'No assignments found.', '', 'info');
                    return;
                }
                
                // If there is only 1 active assignment, bypass selection list entirely
                if (data.length === 1) {
                    const as = data[0];
                    const assetOrUserTargetType = as.asset_id ? 'asset' : 'user';
                    if (assetOrUserTargetType === 'asset') {
                        window.directCheckin(as.id, id, view, as.assigned_to);
                    } else {
                        openReturnModal(id, view, 'user', as.assigned_to, as.id);
                        setTimeout(() => { $('#return_asset_id').data('checkout_id', as.id); }, 100);
                    }
                    return;
                }

                let listHtml = `<div class="text-left mt-3" style="max-height:300px; overflow-y:auto;">`;
                data.forEach(as => {
                    const icon = as.asset_id ? 'fa-laptop' : 'fa-user';
                    const qtyStr = (view === 'consumables' || view === 'accessories') ? (as.quantity + ' ' + (isTr ? 'Adet' : 'Units') + ' • ') : '';
                    const assetOrUserTargetType = as.asset_id ? 'asset' : 'user';
                    const escapedAssignedTo = (as.assigned_to || '').replace(/'/g, "\\'");
                    listHtml += `
                        <div class="assignment-item d-flex align-items-center p-3 mb-2 border cursor-pointer hover-shadow" 
                             style="border-radius:12px; transition:all 0.2s; background:#f8f9fa;"
                             onclick="selectAssignmentReturn(${as.id}, ${id}, '${view}', '${assetOrUserTargetType}', '${escapedAssignedTo}')">
                            <div class="mr-3 bg-white shadow-sm d-flex align-items-center justify-content-center" 
                                 style="width:40px; height:40px; border-radius:10px; color:#6366f1;">
                                <i class="fas ${icon}"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="font-weight-bold text-dark" style="font-size:14px;">${as.assigned_to}</div>
                                <div class="text-muted small">${qtyStr}${as.date}</div>
                            </div>
                            <div class="ml-2 text-primary">
                                <i class="fas fa-chevron-right"></i>
                            </div>
                        </div>`;
                });
                listHtml += `</div>`;

                Swal.fire({
                    title: isTr ? 'İade Alınacak Atamayı Seçin' : 'Select Assignment to Return',
                    html: listHtml,
                    showConfirmButton: false,
                    showCancelButton: true,
                    cancelButtonText: isTr ? 'İptal' : 'Cancel',
                    customClass: { popup: 'modern-swal-popup' }
                });

                window.selectAssignmentReturn = function(checkoutId, assetId, view, tType = 'user', aName = '') {
                    Swal.close();
                    if (tType === 'asset') {
                        window.directCheckin(checkoutId, assetId, view, aName);
                    } else {
                        setTimeout(() => {
                            openReturnModal(assetId, view, tType, aName, checkoutId);
                            setTimeout(() => { $('#return_asset_id').data('checkout_id', checkoutId); }, 100);
                        }, 300);
                    }
                };
            });
            return;
        }
        
        if (targetType === 'asset') {
            window.directCheckin(null, id, view, assignedName);
        } else {
            openReturnModal(id, view, targetType, assignedName);
        }
    }

    window.checkOutItem = function (id, view, available = 1, isTransfer = false) {
        console.log('checkOutItem called:', id, view, available, isTransfer);
        $('#assign_asset_id').val(id);
        $('#assign_view').val(view);
        $('#assign_is_transfer').val(isTransfer ? '1' : '0');
        $('#assignTitle').text(isTransfer ? (isTr ? 'Taşı / Aktar' : 'Transfer') : (isTr ? 'Zimmetle' : 'Check Out'));

        // Reset Source Assignment Selection
        $('#sourceAssignmentGroup').addClass('d-none');
        $('#assign_source_checkout_id').empty();
        
        // ENHANCEMENT: Instance picker for accessories/consumables
        const isAccCons = (view === 'accessories' || view === 'consumables');
        if (isAccCons && isTransfer) {
            $('#instanceSelectionGroup').removeClass('d-none');
            loadAccessoryInstances(id, view);
        } else {
            $('#instanceSelectionGroup').addClass('d-none');
        }

        // Listener for instance selection to update max quantity
        $('#assign_selected_instance').off('change').on('change', function() {
            const opt = $(this).find('option:selected');
            if (opt.val()) {
                const max = parseInt(opt.data('qty') || 1);
                $('#assign_quantity').val(1).attr('max', max).data('max', max);
                // If it's a checkout instance, set it as source
                if (opt.data('type') === 'assigned') {
                    // Populate select options if not present
                    if ($('#assign_source_checkout_id option[value="' + opt.val() + '"]').length === 0) {
                        $('#assign_source_checkout_id').append('<option value="' + opt.val() + '">' + opt.text() + '</option>');
                    }
                    $('#assign_source_checkout_id').val(opt.val()).trigger('change');
                    $('#assign_is_transfer').val('1');
                } else {
                    if ($('#assign_source_checkout_id option[value="free"]').length === 0) {
                        $('#assign_source_checkout_id').append('<option value="free">Free</option>');
                    }
                    $('#assign_source_checkout_id').val('free').trigger('change');
                    $('#assign_is_transfer').val('0'); // Coming from stock
                }
            }
        });

        let sourceName = '-';
        let currentAssign = '';
        
        // Find the source name and current assignment from the current table row
        window.activeAssignmentId = 0;
        window.activeAssignmentType = '';
        const row = $('button[onclick*="checkOutItem(' + id + '"]').closest('tr');
        if (row.length) {
            sourceName = row.find('td:nth-child(2)').text().trim() || row.find('td:nth-child(1)').text().trim();
            
            // Try to find current assignment IDs stored in data attributes of the row (or from context)
            const userLink = row.find('.col-user a[href*="kullanici-detay"]');
            const assetLink = row.find('.col-user a[href*="varlik-detay"]');
            
            if (userLink.length) {
                window.activeAssignmentId = userLink.attr('href').split('/').pop().split('?')[0];
                window.activeAssignmentType = 'user';
            } else if (assetLink.length) {
                window.activeAssignmentId = assetLink.attr('href').split('/').pop().split('?')[0];
                window.activeAssignmentType = 'asset';
            }
            
            // Try to find current assignment in the row (assuming it's in a specific column or badge)
            const assignBadge = row.find('.badge-soft-primary, .badge-soft-info, .badge-assignment').first();
            if (assignBadge.length) {
                currentAssign = ' <span class="text-muted small">(' + assignBadge.text().trim() + ')</span>';
            }
        }
        $('#sourcePreviewName').html(sourceName + currentAssign);
        $('#targetPreviewName').text('<?= $isTr ? "Hedef Seçin..." : "Select Target..." ?>');

        // Reset toggle
        $('.target-type-btn').removeClass('active');
        $('.target-type-btn[data-type="user"]').addClass('active');
        updateAssignTargetList('user');

        // Show/Hide quantity for non-assets
        if (view === 'assets' || view === 'licenses') {
            $('#assignQtyGroup').addClass('d-none');
            $('#deductStockGroup').addClass('d-none');
        } else if (view === 'components') {
            $('#assignQtyGroup').removeClass('d-none');
            $('#deductStockGroup').addClass('d-none');
            const maxAllowedComp = Number(available) > 0 ? Number(available) : 999999;
            $('#assign_quantity').val(1).attr('max', maxAllowedComp).data('max', maxAllowedComp);
            // Fetch component server info (asset_id, total_qty) to support client-side validation
            fetch('varliklar?get_component_info=' + id + '&_=' + new Date().getTime())
                .then(r => r.json())
                .then(data => {
                    if (data && typeof data === 'object') {
                        $('#assign_component_asset_id').val(data.asset_id || '');
                        if (data.total_qty && Number(available) === 0) {
                            $('#assign_quantity').attr('max', Number(data.total_qty)).data('max', Number(data.total_qty));
                        }
                    }
                }).catch(() => { });
        } else {
            $('#assignQtyGroup').removeClass('d-none');
            $('#deductStockGroup').removeClass('d-none');
            const maxAllowed = Number(available) > 0 ? Number(available) : 999999;
            $('#assign_quantity').val(1).attr('max', maxAllowed).data('max', maxAllowed);
        }

        // License Specific: Handle multiple seats/assignments
        if (view === 'licenses') {
            $('#sourceAssignmentGroup').removeClass('d-none');
            $('#assign_source_checkout_id').empty();
            
            if (!isTransfer) {
                // REGULAR ASSIGN: Only show "Free Seat"
                $('#assign_source_checkout_id').append('<option value="free" selected>' + (isTr ? '--- Boş Koltuk / Free Seat ---' : '--- Free Seat ---') + '</option>');
            } else {
                // TRANSFER: Only show occupied seats, fetching them via AJAX below
            }
            
            $.get('varliklar', { get_assignments: id, assign_view: view }, function(data) {
                if (data && data.length > 0) {
                    let assignedCount = 0;
                    let lastAssignedVal = '';
                    data.forEach(function(as) {
                        if (isTransfer) {
                            const label = (as.user_name || as.asset_name || '???') + ' (' + as.date + ')';
                            $('#assign_source_checkout_id').append('<option value="' + as.id + '">' + escHtml(label) + '</option>');
                            assignedCount++;
                            lastAssignedVal = as.id;
                        }
                    });
                    
                    // Auto-select if transferring and exactly 1 assignment exists
                    if (isTransfer && assignedCount === 1) {
                        $('#assign_source_checkout_id').val(lastAssignedVal).trigger('change');
                    }
                }
                
                if (isTransfer && $('#assign_source_checkout_id option').length === 0) {
                    $('#assign_source_checkout_id').append('<option value="">' + (isTr ? '--- Aktif Atama Yok ---' : '--- No Active Assignments ---') + '</option>');
                }

                $('#assign_source_checkout_id').trigger('change');
            });
        }

        // Hide paperAssignGroup for consumables view
        if (view === 'consumables') {
            $('#paperAssignGroup').addClass('d-none');
            $('#assign_paper_only').prop('checked', false);
        }

        $('#assignmentModal').modal('show');
    }

    // Form validation and AJAX submission for assignment modal
    $('#assignmentModal form').on('submit', function (e) {
        e.preventDefault();

        const qty = parseInt($('#assign_quantity').val());
        const max = parseInt($('#assign_quantity').data('max'));
        const view = $('#assign_view').val();
        const targetType = $('#assign_target_type_hidden').val();
        const targetId = parseInt($('#assign_target_id').val() || 0);
        const sourceId = parseInt($('#assign_asset_id').val() || 0);

        // Validation for Transfer Mode
        const isTransferMode = $('#assign_is_transfer').val() === '1';
        if (isTransferMode) {
            if (view === 'licenses') {
                const srcVal = $('#assign_source_checkout_id').val();
                if (!srcVal || srcVal === 'free' || srcVal === '') {
                    Swal.fire({
                        icon: 'warning',
                        title: isTr ? 'Kaynak Seçilmedi' : 'Source Not Selected',
                        text: isTr ? 'Lütfen transfer edilecek kaynak atamayı seçin.' : 'Please select the source assignment to transfer.'
                    });
                    return false;
                }
            } else if (view === 'accessories' || view === 'consumables') {
                const srcVal = $('#assign_selected_instance').val();
                const opt = $('#assign_selected_instance option:selected');
                if (!srcVal || srcVal === 'stock' || srcVal === '' || opt.data('type') !== 'assigned') {
                    Swal.fire({
                        icon: 'warning',
                        title: isTr ? 'Kaynak Seçilmedi' : 'Source Not Selected',
                        text: isTr ? 'Lütfen transfer edilecek kaynak atamayı seçin.' : 'Please select the source assignment to transfer.'
                    });
                    return false;
                }
            }
        }

        // Prevent assigning an asset to the same asset
        if (view === 'assets' && targetType === 'asset' && targetId > 0 && sourceId > 0 && targetId === sourceId) {
            Swal.fire({ icon: 'warning', title: isTr ? 'Hatalı Hedef' : 'Invalid Target', text: isTr ? 'Aynı cihaza atama/taşıma yapılamaz.' : 'Cannot assign/transfer to the same device.' });
            return false;
        }

        // Prevent assigning a component to the same device it is already attached to
        if (view === 'components' && targetType === 'asset' && targetId > 0) {
            const compAsset = parseInt($('#assign_component_asset_id').val() || 0);
            if (compAsset > 0 && compAsset === targetId) {
                Swal.fire({ icon: 'warning', title: isTr ? 'Hatalı Hedef' : 'Invalid Target', text: isTr ? 'Seçili bileşen zaten bu cihaza bağlı.' : 'Selected component is already attached to the target device.' });
                return false;
            }
        }

        if (view !== 'assets' && view !== 'licenses' && qty > max) {
            Swal.fire({
                icon: 'warning',
                title: isTr ? 'Yetersiz Stok!' : 'Insufficient Stock!',
                text: (isTr ? 'Mevcut stok: ' : 'Available stock: ') + max,
                confirmButtonColor: '#6366f1'
            });
            return false;
        }

        // Beautiful SweetAlert2 Loader
        Swal.fire({
            title: isTr ? 'İşlem Yapılıyor...' : 'Processing...',
            text: isTr ? 'Zimmet kaydı oluşturuluyor, lütfen bekleyin.' : 'Creating assignment record, please wait.',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        const formData = $(this).serializeArray();
        formData.push({ name: 'is_ajax', value: '1' });

        $.ajax({
            url: 'varliklar',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    $('#assignmentModal').modal('hide');
                    if (res.download_url) {
                        Swal.fire({
                            icon: 'success',
                            title: isTr ? 'Zimmet Başarıyla Oluşturuldu!' : 'Assignment Created Successfully!',
                            html: (isTr
                                ? '<p class="mb-3">Kağıt imza tutanağı hazırlandı. Lütfen istediğiniz formatı seçerek indirin veya yazdırın.</p>'
                                : '<p class="mb-3">Paper signature form is ready. Please choose the format to download or print.</p>')
                                + '<div class="d-flex justify-content-center gap-2 flex-wrap" style="gap:10px;">'
                                + '<button id="swalPdfBtn" class="swal2-confirm swal2-styled" style="background:#3085d6;"><i class="fas fa-file-pdf mr-2"></i>' + (isTr ? 'PDF Yazdır' : 'Print PDF') + '</button>'
                                + (res.excel_url ? '<button id="swalXlsBtn" class="swal2-confirm swal2-styled" style="background:#1d6f42;"><i class="fas fa-file-excel mr-2"></i>' + (isTr ? 'Excel İndir' : 'Download Excel') + '</button>' : '')
                                + '<button id="swalCloseBtn" class="swal2-cancel swal2-styled" style="background:#aaa;">' + (isTr ? 'Kapat' : 'Close') + '</button>'
                                + '</div>',
                            showConfirmButton: false,
                            showCancelButton: false,
                            allowOutsideClick: false,
                            didOpen: () => {
                                document.getElementById('swalPdfBtn').addEventListener('click', () => {
                                    window.open(res.download_url, '_blank');
                                });
                                if (res.excel_url && document.getElementById('swalXlsBtn')) {
                                    document.getElementById('swalXlsBtn').addEventListener('click', () => {
                                        window.open(res.excel_url, '_blank');
                                    });
                                }
                                document.getElementById('swalCloseBtn').addEventListener('click', () => {
                                    Swal.close();
                                    window.location.reload();
                                });
                            }
                        }).then(() => {
                            window.location.reload();
                        });
                    } else if (res.redirect) {
                        Swal.fire({
                            icon: 'success',
                            title: isTr ? 'Zimmetlendi!' : 'Assigned!',
                            text: res.message || (isTr ? 'İşlem başarıyla tamamlandı.' : 'Successfully completed.'),
                            timer: 2000,
                            showConfirmButton: false
                        }).then((result) => {
                            window.location = res.redirect;
                        });
                    } else {
                        Swal.fire({
                            icon: 'success',
                            title: isTr ? 'Zimmetlendi!' : 'Assigned!',
                            text: res.message || (isTr ? 'İşlem başarıyla tamamlandı.' : 'Successfully completed.'),
                            timer: 2000,
                            showConfirmButton: false
                        }).then((result) => {
                            window.location.reload();
                        });
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: isTr ? 'Hata!' : 'Error!',
                        text: res.message || (isTr ? 'İşlem başarısız oldu.' : 'Action failed.')
                    });
                }
            },
            error: function () {
                Swal.fire({
                    icon: 'error',
                    title: isTr ? 'Hata!' : 'Error!',
                    text: isTr ? 'İnternet bağlantısı veya sunucu hatası.' : 'Network or server error.'
                });
            }
        });
    });

    // Handle Modern Toggle Buttons
    $(document).on('click', '.target-type-btn', function () {
        const type = $(this).data('type');
        $('.target-type-btn').removeClass('active');
        $(this).addClass('active');
        updateAssignTargetList(type);
    });

    // Update Preview on instance selection change
    $(document).on('change', '#assign_selected_instance', function () {
        const text = $(this).find('option:selected').text();
        if (text && !text.includes('Seçiniz') && !text.includes('Select') && !text.includes('Loading')) {
            // Update source preview to show selected instance
            const baseName = $('#sourcePreviewName').text().split(' •')[0] || '—';
            $('#sourcePreviewName').html(baseName + ' <span class="text-muted small">(' + text + ')</span>').addClass('animate__animated animate__fadeIn');
            setTimeout(() => $('#sourcePreviewName').removeClass('animate__animated animate__fadeIn'), 500);
        }
    });

    // Update Preview on selection change
    $(document).on('change', '#assign_target_id', function () {
        const text = $(this).find('option:selected').text();
        if (text && !text.includes('Seçiniz') && !text.includes('Select')) {
            $('#targetPreviewName').text(text).addClass('animate__animated animate__fadeIn');
            setTimeout(() => $('#targetPreviewName').removeClass('animate__animated animate__fadeIn'), 500);
        } else {
            $('#targetPreviewName').text('<?= $isTr ? "Hedef Seçin..." : "Select Target..." ?>');
        }
    });

    // Auto-populate Category and Manufacturer when Model changes
    $(document).on('change', '#model_id', function() {
        const modelId = $(this).val();
        if (modelId) {
            $.getJSON('varliklar', { get_model_details: modelId }, function(data) {
                if (data && data.category_id) {
                    if ($('#category_id').find("option[value='" + data.category_id + "']").length) {
                        $('#category_id').val(data.category_id).trigger('change');
                    } else if (data.category_name) {
                        var newOption = new Option(data.category_name, data.category_id, true, true);
                        $('#category_id').append(newOption).trigger('change');
                    }
                }
                if (data && data.manufacturer_id) {
                    if ($('#manufacturer_id').find("option[value='" + data.manufacturer_id + "']").length) {
                        $('#manufacturer_id').val(data.manufacturer_id).trigger('change');
                    } else if (data.manufacturer_name) {
                        var newOption = new Option(data.manufacturer_name, data.manufacturer_id, true, true);
                        $('#manufacturer_id').append(newOption).trigger('change');
                    }
                }
            });
        }
    });

    function updateAssignTargetList(type) {
        const select = $('#assign_target_id');
        const label = $('#assignLabel');
        const view = $('#assign_view').val();
        $('#assign_target_type_hidden').val(type);

        // Show paperAssignGroup only for user targets AND when not in consumables view
        if (type === 'user' && view !== 'consumables') {
            $('#paperAssignGroup').removeClass('d-none');
        } else {
            $('#paperAssignGroup').addClass('d-none');
            $('#assign_paper_only').prop('checked', false);
        }

        label.text(type === 'user' ? '<?= __("user") ?>' : '<?= __("asset") ?>');
        const listType = (type === 'user') ? 'users' : 'assets';

        if (select.hasClass("select2-hidden-accessible")) {
            select.select2('destroy');
        }
        select.empty().append('<option value=""><?= $isTr ? "Seçiniz..." : "Select..." ?></option>');

        const select2Lang = {
            noResults: function () { return "<?= $isTr ? 'Sonuç bulunamadı' : 'No results found' ?>"; },
            searching: function () { return "<?= $isTr ? 'Aranıyor...' : 'Searching...' ?>"; }
        };
        select.select2({
            theme: 'bootstrap4',
            width: '100%',
            language: select2Lang,
            ajax: {
                url: 'varliklar' + (window.location.search || ''),
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        search_select2: listType,
                        q: params.term,
                        page: params.page || 1
                    };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    if (!data || !data.results) {
                        return { results: [] };
                    }
                    // Prevention: don't show the same item if it's already assigned
                    let filtered = data.results.filter(function (item) {
                        if (view === 'assets' && type === 'asset' && item.id == $('#assign_asset_id').val()) return false;
                        if (typeof window.activeAssignmentId !== 'undefined' && window.activeAssignmentType === type && item.id == window.activeAssignmentId) return false;
                        return true;
                    });
                    return {
                        results: filtered,
                        pagination: {
                            more: data.pagination ? data.pagination.more : false
                        }
                    };
                },
                cache: false
            },
            placeholder: isTr ? 'Aramak için yazın...' : 'Type to search...',
            allowClear: true,
            minimumInputLength: 0
        });
        select.trigger('change');
    }

    $(document).on('change', 'input[name="target_type_toggle"]', function () {
        updateAssignTargetList($(this).val());
    });

    function replenishStock(id, name, view = 'consumables') {
        if (view === 'components') {
            Swal.fire({
                title: isTr ? 'Stok Ekle' : 'Add Stock',
                html: '<div class="text-left">' +
                    '<label class="small font-weight-bold">' + (isTr ? 'Miktar' : 'Quantity') + '</label>' +
                    '<input type="number" id="swal-qty" class="form-control mb-2" value="1" min="1" oninput="handleStockQtyChange(this.value)">' +
                    '<div id="serial-field-container">' +
                    '  <label class="small font-weight-bold">' + (isTr ? 'Seri No (Sadece 1 adet için)' : 'Serial No (Only for 1 unit)') + '</label>' +
                    '  <input type="text" id="swal-serial" class="form-control">' +
                    '</div>' +
                    '<div id="serial-bulk-warning" class="alert alert-info p-2 small mt-2" style="display:none;">' +
                    '  <i class="fas fa-info-circle mr-1"></i> ' + (isTr ? '1\'den fazla girişte seri no pasif olur.' : 'Serial number is disabled for bulk entry.') +
                    '</div>' +
                    '</div>',
                showCancelButton: true,
                confirmButtonText: isTr ? 'Ekle' : 'Add',
                didOpen: () => {
                    window.handleStockQtyChange = function(val) {
                        const sCont = document.getElementById('serial-field-container');
                        const sWarn = document.getElementById('serial-bulk-warning');
                        const sInput = document.getElementById('swal-serial');
                        if (val == 1) {
                            sCont.style.display = 'block';
                            sWarn.style.display = 'none';
                        } else {
                            sCont.style.display = 'none';
                            sWarn.style.display = 'block';
                            sInput.value = '';
                        }
                    };
                },
                preConfirm: () => {
                    const qty = document.getElementById('swal-qty').value;
                    const serial = document.getElementById('swal-serial').value;
                    if (!qty || qty < 1) { Swal.showValidationMessage(isTr ? 'Geçerli bir miktar girin' : 'Enter a valid quantity'); return false; }
                    if (qty > 1 && serial) { Swal.showValidationMessage(isTr ? 'Toplu girişte seri no girilemez' : 'Cannot enter serial for bulk'); return false; }
                    return { qty: qty, serial: serial };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">' +
                        '<input type="hidden" name="action" value="replenish_stock">' +
                        '<input type="hidden" name="asset_id" value="' + id + '">' +
                        '<input type="hidden" name="view" value="' + view + '">' +
                        '<input type="hidden" name="quantity" value="' + result.value.qty + '">' +
                        '<input type="hidden" name="serial_no" value="' + result.value.serial + '">';
                    document.body.appendChild(form);
                    form.submit();
                }
            });
            return;
        }

        Swal.fire({
            title: isTr ? 'Stok Ekle' : 'Add Stock',
            text: (name ? name + ' - ' : '') + (isTr ? 'Miktar girin:' : 'Enter quantity:'),
            input: 'number',
            inputValue: 1,
            showCancelButton: true,
            confirmButtonText: '<?= __("save") ?>',
            cancelButtonText: '<?= __("cancel") ?>'
        }).then((result) => {
            if (result.isConfirmed && result.value != 0) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="replenish_stock"><input type="hidden" name="asset_id" value="' + id + '"><input type="hidden" name="view" value="' + view + '"><input type="hidden" name="quantity" value="' + result.value + '">';
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    function confirmRestore(id, view = 'assets') {
        Swal.fire({
            title: isTr ? 'Geri Yükle' : 'Restore',
            text: isTr ? 'Bu öğeyi geri yüklemek istediğinize emin misiniz?' : 'Are you sure you want to restore this item?',
            icon: 'question', showCancelButton: true,
            confirmButtonText: isTr ? 'Evet' : 'Yes', cancelButtonText: isTr ? 'Hayır' : 'No'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="view" value="' + view + '"><input type="hidden" name="asset_id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    function confirmScrap(id, view = 'assets', maxQty = 1, catId = 0) {
        if ((view === 'accessories' || view === 'components') && maxQty > 1) {
            // Direct selection mode for pooled items
            const itemName = document.querySelector('[onclick*="confirmScrap(' + id + '"]').closest('tr').querySelector('.col-name').innerText.trim();
            openInstancePicker(itemName, catId, 'scrap');
            return;
        }

        if (view === 'assets') {
            $.post('varliklar', { action: 'check_signature_type', asset_id: id, csrf_token: '<?= csrf_token() ?>' }, function(res) {
                if (res.assigned) {
                    if (res.requires_digital) {
                        Swal.fire({
                            icon: 'error',
                            title: isTr ? 'Hata!' : 'Error!',
                            text: isTr 
                                ? 'Bu cihaz dijital onaylı/imzalı zimmet altındadır. Hurdaya ayırmadan önce lütfen "Geri Al" (İade) işlemini başlatarak personelin dijital imzasını tamamlayın.' 
                                : 'This device is under digitally signed assignment. Before scrapping, please first initiate "Check In" to complete the digital signature.',
                            confirmButtonColor: '#3085d6'
                        });
                    } else {
                        Swal.fire({
                            title: isTr ? 'Zimmet Otomatik Geri Alınsın mı?' : 'Should Assignment Be Checked In Automatically?',
                            html: isTr 
                                ? 'Bu cihaz şu anda kağıt/ıslak imza ile zimmetli. Hurdaya ayırırsanız zimmet kaydı otomatik olarak geri alınacaktır.<br><br>Zimmet otomatik olarak geri alınsın mı?' 
                                : 'This device is currently assigned with paper/wet signature. Scrapping it will automatically check in the assignment.<br><br>Should the assignment be checked in automatically?',
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: isTr ? 'Evet, Geri Al ve Hurdaya Ayır' : 'Yes, Check In & Scrap',
                            cancelButtonText: isTr ? 'Hayır, İptal Et' : 'No, Cancel',
                            confirmButtonColor: '#d33',
                            cancelButtonColor: '#3085d6'
                        }).then((r) => {
                            if (r.isConfirmed) {
                                submitScrapForm(id, view);
                            } else {
                                Swal.fire({
                                    icon: 'info',
                                    text: isTr 
                                        ? 'Cihazı hurdaya ayırabilmek için önce zimmeti manuel olarak geri almalısınız.' 
                                        : 'To scrap the device, you must check in the assignment manually first.'
                                });
                            }
                        });
                    }
                } else {
                    Swal.fire({
                        title: isTr ? 'Hurdaya Ayır' : 'Move to Scrap',
                        text: isTr ? 'Bu öğeyi hurdaya ayırmak istediğinize emin misiniz?' : 'Are you sure you want to scrap this item?',
                        icon: 'warning', showCancelButton: true,
                        confirmButtonColor: '#d33',
                        confirmButtonText: isTr ? 'Evet, Hurdaya Ayır' : 'Yes, Scrap it',
                        cancelButtonText: isTr ? 'İptal' : 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            submitScrapForm(id, view);
                        }
                    });
                }
            });
        } else {
            Swal.fire({
                title: isTr ? 'Hurdaya Ayır' : 'Move to Scrap',
                text: isTr ? 'Bu öğeyi hurdaya ayırmak istediğinize emin misiniz?' : 'Are you sure you want to scrap this item?',
                icon: 'warning', showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: isTr ? 'Evet, Hurdaya Ayır' : 'Yes, Scrap it',
                cancelButtonText: isTr ? 'İptal' : 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitScrapForm(id, view);
                }
            });
        }
    }

    function submitScrapForm(id, view, qty = 1) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="scrap"><input type="hidden" name="view" value="' + view + '"><input type="hidden" name="asset_id" value="' + id + '"><input type="hidden" name="quantity" value="' + qty + '">';
        document.body.appendChild(form);
        form.submit();
    }

    function setSelect2AjaxValue($select, id, text) {
        if (!$select.length) return;
        if (!id) {
            $select.val('').trigger('change');
            return;
        }
        if ($select.find("option[value='" + id + "']").length) {
            $select.val(id).trigger('change');
        } else {
            const newOption = new Option(text || ('ID: ' + id), id, true, true);
            $select.append(newOption).trigger('change');
        }
    }

    window.editAsset = function (data, view = 'assets') {
        const currentView = view;
        resetForm(currentView);

        const targetForm = $('#assetForm');
        if (targetForm.length) {
            targetForm.data('current-item', data);
        }

        $('#technicalSpecsSection').hide();
        $('#dynamic-custom-fields-container').empty();
        $('#specsContainer').empty();

        const itemName = data.name || data.software_name || 'Item';
        $('#modalTitle').html('<i class="fas fa-edit mr-2"></i>' + (isTr ? 'Düzenle' : 'Edit') + ': ' + itemName);
        $('#asset_id').val(data.id);

        if (typeof data.name !== 'undefined') $('#name').val(data.name);
        else if (typeof data.software_name !== 'undefined') $('#name').val(data.software_name);

        if ($('#serial_no').length) $('#serial_no').val(data.serial_no || data.model_no || data.item_no);
        if ($('#company_id').length) $('#company_id').val(data.company_id).trigger('change');
        if ($('#department_id').length) setSelect2AjaxValue($('#department_id'), data.department_id, data.dept_name);
        if ($('#license_key').length) $('#license_key').val(data.license_key);
        if ($('#seats').length) $('#seats').val(data.seats || data.total_qty);
        if ($('#expire_date').length) $('#expire_date').val(data.expire_date);
        if ($('#model_id').length) setSelect2AjaxValue($('#model_id'), data.model_id, data.model_name);
        if ($('#category_id').length) $('#category_id').val(data.category_id).trigger('change');
        if ($('#manufacturer_id').length) setSelect2AjaxValue($('#manufacturer_id'), data.manufacturer_id, data.manufacturer_name);
        if ($('#asset_tag').length) $('#asset_tag').val(data.asset_tag);
        if ($('#purchase_cost').length) $('#purchase_cost').val(data.purchase_cost);
        if ($('#purchase_currency').length) $('#purchase_currency').val(data.purchase_currency || 'TRY');
        if ($('#location').length) $('#location').val(data.location);
        if ($('#status').length) {
            let statusVal = data.status_id;
            if (!statusVal) {
                const defStatus = lookupData.status_labels.find(s => s.is_default == 1);
                statusVal = defStatus ? defStatus.id : 3;
            }
            
            // If the status is pending (which is filtered out of the dropdown options),
            // dynamically append the option so it can be rendered and selected properly.
            const matchedStatus = lookupData.status_labels.find(function(x) { return x.id == statusVal; });
            if (matchedStatus) {
                const nameLower = (matchedStatus.name || '').toLowerCase().replace(/i̇/g, 'i').replace(/ı/g, 'i');
                if (matchedStatus.type === 'pending' || nameLower.indexOf('imza bekliyor') !== -1 || nameLower.indexOf('pending signature') !== -1) {
                    if (!$('#status option[value="' + statusVal + '"]').length) {
                        $('#status').append(new Option(window.translateStatusName(matchedStatus.name), statusVal, true, true));
                    }
                }
            }
            $('#status').data('previous-val', statusVal).val(statusVal).trigger('change');
        }
        if ($('#assigned_user_id').length) setSelect2AjaxValue($('#assigned_user_id'), data.assigned_user_id, data.assigned_user);
        if ($('#ip_address').length) $('#ip_address').val(data.ip_address);
        if ($('#mac_address').length) $('#mac_address').val(data.mac_address);
        if ($('#ip_secondary').length) $('#ip_secondary').val(data.ip_secondary);
        if ($('#cpu').length) $('#cpu').val(data.cpu || '');
        if ($('#ram').length) $('#ram').val(data.ram || '');
        if ($('#gpu').length) $('#gpu').val(data.gpu || '');
        if ($('#monitor').length) $('#monitor').val(data.monitor || '');
        if ($('#os').length) $('#os').val(data.os || '');
        if ($('#notes').length) $('#notes').val(data.notes || '');
        if ($('#warranty_months').length) $('#warranty_months').val(data.warranty_months || 0);
        if ($('#order_number').length) $('#order_number').val(data.order_no || data.order_number || '');
        if ($('#purchase_date').length) $('#purchase_date').val(data.purchase_date || '');
        if ($('#purchase_cost').length) $('#purchase_cost').val(data.purchase_cost || '');
        if ($('#supplier_id').length) setSelect2AjaxValue($('#supplier_id'), data.supplier_id, data.supplier_name);
        if ($('#quantity').length) $('#quantity').val(data.total_qty || 1);
        if ($('#min_qty').length) $('#min_qty').val(data.min_qty || '');
        if ($('#item_no').length) $('#item_no').val(data.item_no || '');
        if ($('#license_email').length) $('#license_email').val(data.license_email || '');
        if ($('#license_name').length) $('#license_name').val(data.license_name || '');

        if (currentView === 'accessories' || currentView === 'components') {
            if ($('#serial_no').length) $('#serial_no').val(data.serial_no || data.model_no || '');
        }
        if ($('#asset_id_assigned').length) $('#asset_id_assigned').val(data.asset_id).trigger('change');

        if ($('#ip_address').length) $('#ip_address').val(data.ip_address);
        if ($('#ip_secondary').length) $('#ip_secondary').val(data.ip_secondary);
        if ($('#company_id').length) $('#company_id').val(data.company_id).trigger('change');
        if ($('#department_id').length) $('#department_id').val(data.department_id).trigger('change');
        if ($('#location').length) $('#location').val(data.location).trigger('change');

        const hasAssetImg = !!data.image;
        if (hasAssetImg || data.model_image) {
            const rawImg = hasAssetImg ? data.image : data.model_image;
            const imgUrl = 'public/' + (rawImg.indexOf('assets-') === 0 ? 'uploads/assets/' : (rawImg.indexOf('models-') === 0 ? 'uploads/models/' : '')) + rawImg;
            $('#asset_image_preview').attr('src', imgUrl);
            $('#asset_image_preview_container').show();
            if (hasAssetImg) {
                $('#asset_image_type_badge').html('<span class="text-success"><i class="fas fa-check-circle mr-1"></i>' + (isTr ? 'Cihaza Özel Resim' : 'Asset-Specific Image') + '</span>');
            } else {
                $('#asset_image_type_badge').html('<span class="text-info"><i class="fas fa-info-circle mr-1"></i>' + (isTr ? 'Model Varsayılan Resmi' : 'Model Default Image') + '</span>');
            }
        } else {
            $('#asset_image_preview_container').hide();
        }

        const previewMap = { 'accessories': 'accessory_image_preview', 'consumables': 'consumable_image_preview', 'components': 'component_image_preview', 'licenses': 'license_image_preview' };
        const previewId = previewMap[currentView];
        if (previewId) {
            let finalImgSrc = '';
            if (data.image) finalImgSrc = 'public/uploads/' + currentView + '/' + data.image;
            else if (data.category_image) finalImgSrc = 'public/uploads/categories/' + data.category_image;

            if (finalImgSrc) {
                $('#' + previewId).attr('src', finalImgSrc).show();
                $('#' + previewId + '_container').show();
            } else {
                $('#' + previewId + '_container').hide();
            }
        }

        if (data.custom_fields) {
            setTimeout(() => {
                const fields = typeof data.custom_fields === 'string' ? JSON.parse(data.custom_fields) : data.custom_fields;
                for (const key in fields) {
                    const $f = $('[name="custom_fields[' + key + ']"]');
                    if ($f.length) $f.val(fields[key]);
                }
            }, 500);
        }

        $('#assetModal').modal('show');
    }

    window.cloneAsset = function (data, view = 'assets') {
        editAsset(data, view);
        $('#asset_id').val('');
        $('#asset_tag').val('');
        $('#serial_no').val('');
        const itemName = data.name || data.software_name || 'Item';
        $('#modalTitle').html('<i class="fas fa-copy mr-2"></i>' + (isTr ? 'Kopyala' : 'Copy') + ': ' + itemName);
        Swal.fire({
            toast: true, position: 'top-end', icon: 'info',
            title: isTr ? 'Kopyalama Modu: Yeni bir demirbaş no ve seri no giriniz.' : 'Copy Mode: Enter a new asset tag and serial number.',
            showConfirmButton: false, timer: 3000
        });
    }

    // --- CRITICAL UI HELPERS (Defined at top to prevent ReferenceErrors) ---
    function quickAddBtn(type, context = '') {
        const labels = <?= json_encode(['suppliers' => __('supplier'), 'manufacturers' => __('manufacturer'), 'companies' => __('company'), 'departments' => __('department'), 'categories' => __('category')], JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
        const title = (labels[type] || type) + " " + (typeof isTr !== 'undefined' && isTr ? 'Hızlı Ekle' : 'Quick Add');
        const ctxArg = context ? ", '" + context + "'" : (typeof currentView !== 'undefined' ? ", '" + currentView + "'" : "");
        return '<button type="button" class="btn btn-xs btn-link p-0 ml-1 text-primary" onclick="addPredefined(\'' + type + '\'' + ctxArg + ')" title="' + title + '"><i class="fas fa-plus-circle"></i></button>';
    }



    window.togglePredefinedImageDeletion = function(willDelete) {
        if (willDelete) {
            $('#p_remove_image').prop('checked', true);
            $('#p_image_deleted_overlay').removeClass('d-none').addClass('d-flex');
            $('#p_delete_img_btn').addClass('d-none');
        } else {
            $('#p_remove_image').prop('checked', false);
            $('#p_image_deleted_overlay').removeClass('d-flex').addClass('d-none');
            if ($('#p_image_preview').attr('src')) {
                $('#p_delete_img_btn').removeClass('d-none');
                $('#p_image_preview').removeClass('d-none');
                $('#p_image_placeholder').addClass('d-none');
            } else {
                $('#p_delete_img_btn').addClass('d-none');
                $('#p_image_preview').addClass('d-none');
                $('#p_image_placeholder').removeClass('d-none');
            }
        }
    };

    window.editPredefined = function (type, data = {}) {
        const form = $('#predefinedForm');
        if (!form.length) return;
        form[0].reset();

        const isNew = !data || !data.id;
        $('#p_type').val(type);
        $('#p_id').val(isNew ? '' : data.id);
        $('#p_context').val('predefined');

        const typeLabels = {
            'categories': isTr ? 'Kategori' : 'Category',
            'models': isTr ? 'Model' : 'Model',
            'manufacturers': isTr ? 'Üretici' : 'Manufacturer',
            'suppliers': isTr ? 'Tedarikçi' : 'Supplier',
            'companies': isTr ? 'Şirket' : 'Company',
            'departments': isTr ? 'Bölüm' : 'Department',
            'status_labels': isTr ? 'Durum Etiketi' : 'Status Label',
            'custom_fields': isTr ? 'Özel Alan' : 'Custom Field'
        };

        const typeLabel = typeLabels[type] || type;
        const itemName = isNew ? (isTr ? 'Yeni ' + typeLabel : 'New ' + typeLabel) : (data.name || data.bolum_adi || data.software_name || 'Item');
        $('#p_title').html('<i class="fas ' + (isNew ? 'fa-plus' : 'fa-edit') + ' mr-2"></i>' + (isNew ? '' : (isTr ? 'Düzenle' : 'Edit') + ': ') + itemName);

        const nameLabelText = (type === 'custom_fields') ? (isTr ? 'Alan Etiketi (Görünen İsim)' : 'Field Label') : (isTr ? 'İsim' : 'Name');
        $('#p_main_label').text(nameLabelText);

        $('#p_name').val(data.name || data.bolum_adi || '');
        if ($('#p_name_en').length) $('#p_name_en').val(data.name_en || '');
        if ($('#p_notes').length) $('#p_notes').val(data.notes || data.description || '');

        // Hide all specific sections first and disable their inputs to prevent serialization conflicts
        $('#p_status_fields, #p_supplier_fields, #p_company_fields, #p_dept_fields, #p_model_fields, #p_custom_fields').addClass('d-none').find(':input').prop('disabled', true);
        $('#p_image_group, #p_inventory_type_group, #p_parent_group').addClass('d-none').find(':input').prop('disabled', true);

        // Reset image removal state
        $('#p_remove_image').prop('checked', false);
        $('#p_image_deleted_overlay').removeClass('d-flex').addClass('d-none');
        $('#p_delete_img_btn').addClass('d-none');
        $('#p_image').val('');
        if ($('#p_image').next('.custom-file-label').length) {
            $('#p_image').next('.custom-file-label').html(isTr ? 'Dosya Seç...' : 'Browse');
        }

        if (type === 'status_labels') {
            $('#p_status_fields').removeClass('d-none').find(':input').prop('disabled', false);
            $('#p_color').val(data.color || '#3b82f6');
            $('#p_status_type').val(data.type || 'deployable');
            $('#p_show_in_nav').prop('checked', isNew ? true : !!parseInt(data.show_in_nav));
            $('#p_is_default').prop('checked', !!parseInt(data.is_default));
        } else if (type === 'suppliers') {
            $('#p_supplier_fields').removeClass('d-none').find(':input').prop('disabled', false);
            $('#p_supp_contact').val(data.contact_person || '');
            $('#p_supp_phone').val(data.phone || '');
            $('#p_supp_email').val(data.email || '');
            $('#p_supp_website').val(data.website || '');
            $('#p_supp_address').val(data.address || '');
            $('#p_supp_city').val(data.city || '');
            $('#p_supp_country').val(data.country || '');
            $('#p_supp_zip').val(data.zip || '');
            $('#p_image_group').removeClass('d-none').find(':input').prop('disabled', false);
        } else if (type === 'companies') {
            $('#p_company_fields').removeClass('d-none').find(':input').prop('disabled', false);
            $('#p_comp_phone').val(data.phone || '');
            $('#p_comp_website').val(data.website || '');
            $('#p_comp_tax').val(data.tax_number || '');
            $('#p_comp_address').val(data.address || '');
            $('#p_image_group').removeClass('d-none').find(':input').prop('disabled', false);
        } else if (type === 'departments') {
            $('#p_dept_fields').removeClass('d-none').find(':input').prop('disabled', false);
            $('#p_dept_responsible').val(data.responsible_person || '');
        } else if (type === 'models') {
            $('#p_model_fields').removeClass('d-none').find(':input').prop('disabled', false);
            $('#p_model_category_id').val(data.category_id || '').trigger('change');
            $('#p_model_manufacturer_id').val(data.manufacturer_id || '').trigger('change');
            $('#p_model_number').val(data.model_number || '');
            $('#p_model_min_amt').val(data.min_amt == 0 ? '' : data.min_amt);
            $('#p_model_field_group').val(data.field_group || '').trigger('change');
            $('#p_model_show_serial').prop('checked', !!parseInt(data.show_serial));
            $('#p_model_eol').val(data.eol == 0 ? '' : data.eol);
            $('#p_model_depreciation_id').val(data.depreciation_id || '').trigger('change');
            $('#p_image_group').removeClass('d-none').find(':input').prop('disabled', false);
        } else if (type === 'categories') {
            $('#p_parent_group').removeClass('d-none').find(':input').prop('disabled', false);
            $('#p_inventory_type_group').removeClass('d-none').find(':input').prop('disabled', false);
            
            let defaultType = 'asset';
            const urlView = (new URLSearchParams(window.location.search).get('view') || '').toLowerCase();
            if (urlView === 'licenses') defaultType = 'license';
            else if (urlView === 'accessories') defaultType = 'accessory';
            else if (urlView === 'consumables') defaultType = 'consumable';
            else if (urlView === 'components') defaultType = 'component';
            else if (urlView === 'assets') defaultType = 'asset';

            let invType = data.type || defaultType;
            if (invType === 'licenses') invType = 'license';
            else if (invType === 'accessories') invType = 'accessory';
            else if (invType === 'consumables') invType = 'consumable';
            else if (invType === 'components') invType = 'component';
            else if (invType === 'assets') invType = 'asset';

            $('#p_inventory_type_select').val(invType);
            rebuildParentOptions(invType);
            $('#p_parent_id').val(data.parent_id || '');
            $('#p_image_group').removeClass('d-none').find(':input').prop('disabled', false);
        } else if (type === 'custom_fields') {
            $('#p_custom_fields').removeClass('d-none').find(':input').prop('disabled', false);
            $('#p_field_category_id').val(data.category_id || '');
            $('#p_field_name').val(data.field_name || '');
            $('#p_field_group').val(data.field_group || '');
            $('#p_field_type').val(data.field_type || 'text').trigger('change');
            $('#p_status_field_btn').prop('checked', isNew ? true : !!parseInt(data.status));
            $('#p_options').val(data.options || '');
        } else if (type === 'manufacturers') {
            $('#p_image_group').removeClass('d-none').find(':input').prop('disabled', false);
        }

        // Hide right column if no sidebar content is needed
        if (type === 'custom_fields' || type === 'departments' || type === 'status_labels') {
            $('#p_right_col').addClass('d-none');
            $('#p_left_col').removeClass('col-lg-7').addClass('col-lg-12');
        } else {
            $('#p_right_col').removeClass('d-none');
            $('#p_left_col').removeClass('col-lg-12').addClass('col-lg-7');
        }

        if (!isNew && data.image) {
            let folder = type;
            if (type === 'models') folder = 'models';
            else if (type === 'categories') folder = 'categories';
            else if (type === 'suppliers') folder = 'suppliers';
            else if (type === 'companies') folder = 'companies';
            else if (type === 'manufacturers') folder = 'manufacturers';

            $('#p_image_preview').attr('src', 'public/uploads/' + folder + '/' + data.image).removeClass('d-none');
            $('#p_image_placeholder').addClass('d-none');
            $('#p_image_preview_container').removeClass('d-none');
            $('#p_delete_img_btn').removeClass('d-none');
        } else {
            $('#p_image_preview').attr('src', '').addClass('d-none');
            $('#p_delete_img_btn').addClass('d-none');
            $('#p_image_placeholder').removeClass('d-none');
            if (type === 'suppliers' || type === 'companies' || type === 'manufacturers' || type === 'models' || type === 'categories') {
                $('#p_image_preview_container').removeClass('d-none');
            } else {
                $('#p_image_preview_container').addClass('d-none');
            }
        }

        $('#predefinedModal').modal('show');
    };

    window.addPredefined = function (type, context = '') {
        let initialData = {};
        if (type === 'categories') {
            let catType = '';
            if (context) {
                const ctxLower = context.toLowerCase();
                if (ctxLower.includes('license')) catType = 'license';
                else if (ctxLower.includes('accessor')) catType = 'accessory';
                else if (ctxLower.includes('consumable')) catType = 'consumable';
                else if (ctxLower.includes('component')) catType = 'component';
                else if (ctxLower.includes('asset')) catType = 'asset';
            }
            if (!catType) {
                const urlView = (new URLSearchParams(window.location.search).get('view') || '').toLowerCase();
                if (urlView === 'licenses') catType = 'license';
                else if (urlView === 'accessories') catType = 'accessory';
                else if (urlView === 'consumables') catType = 'consumable';
                else if (urlView === 'components') catType = 'component';
                else if (urlView === 'assets') catType = 'asset';
            }
            if (!catType && typeof currentView !== 'undefined' && currentView) {
                const curLower = currentView.toLowerCase();
                if (curLower.includes('license')) catType = 'license';
                else if (curLower.includes('accessor')) catType = 'accessory';
                else if (curLower.includes('consumable')) catType = 'consumable';
                else if (curLower.includes('component')) catType = 'component';
                else if (curLower.includes('asset')) catType = 'asset';
            }
            if (catType) {
                initialData.type = catType;
            }
        }
        editPredefined(type, initialData);
        if (type === 'custom_fields' && context) {
            $('#p_field_category_id').val(context);
        }
    };

    $(document).on('change', '#p_inventory_type_select', function () {
        rebuildParentOptions($(this).val());
    });

    $(document).on('change', '#p_image', function () {
        const file = this.files[0];
        if (file) {
            $(this).next('.custom-file-label').html(file.name);
            const objectUrl = URL.createObjectURL(file);
            $('#p_image_preview').attr('src', objectUrl).removeClass('d-none');
            $('#p_image_placeholder').addClass('d-none');
            $('#p_image_preview_container').removeClass('d-none');
            togglePredefinedImageDeletion(false);
            $('#p_delete_img_btn').removeClass('d-none');
        }
    });

    $(document).on('change', '#p_field_type', function () {
        if ($(this).val() === 'select') {
            $('#p_options_group').removeClass('d-none');
        } else {
            $('#p_options_group').addClass('d-none');
        }
    });




    function categoryMatches(category, expectedType) {
        if (!category) return false;
        const normalized = String(category.normalized_type || category.type || '').toLowerCase();
        return normalized === expectedType;
    }

    function populateCategorySelect() {
        if (typeof lookupData === 'undefined' || !lookupData.categories) return;
        const view = '<?= $view ?>';
        const select = $('#category_id');
        if (!select.length) return;

        const currentVal = select.val();
        select.empty().append('<option value=""><?= __("select") ?>...</option>');

        let typeFilter = 'asset';
        if (view === 'licenses') typeFilter = 'license';
        else if (view === 'accessories') typeFilter = 'accessory';
        else if (view === 'consumables') typeFilter = 'consumable';
        else if (view === 'components') typeFilter = 'component';

        const optsHtml = renderCategoryOptions(typeFilter);
        select.append(optsHtml);

        if (currentVal) select.val(currentVal);
    }

    function deleteLog(id) {
        Swal.fire({
            title: '<?= __("are_you_sure") ?>',
            text: 'Bu hareket kaydını silmek istediğinizden emin misiniz?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: '<?= __("yes_delete") ?>',
            cancelButtonText: '<?= __("cancel") ?>'
        }).then((res) => {
            if (res.isConfirmed) {
                $.post('varliklar', { action: 'delete_log', log_id: id, csrf_token: '<?= csrf_token() ?>' }, function (resp) {
                    location.reload();
                });
            }
        });
    }

    function deleteSelectedLogs() {
        const ids = $('.selectLogItem:checked').map(function () { return $(this).val(); }).get().join(',');
        if (!ids) return;

        Swal.fire({
            title: '<?= __("are_you_sure") ?>',
            text: 'Seçilen tüm hareket kayıtlarını silmek istediğinizden emin misiniz?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: '<?= __("yes_delete") ?>',
            cancelButtonText: '<?= __("cancel") ?>'
        }).then((res) => {
            if (res.isConfirmed) {
                $.post('varliklar', { action: 'delete_multiple_logs', ids: ids, csrf_token: '<?= csrf_token() ?>' }, function (resp) {
                    location.reload();
                });
            }
        });
    }

    function renderStructuredSpecs(category, existingData = {}) {
        const container = document.getElementById('structuredSpecs');
        if (!container) return;
        const fields = deviceSchema[category];
        if (!fields || fields.length === 0) {
            container.innerHTML = '';
            container.style.display = 'none';
            return;
        }
        container.style.display = 'block';
        let html = '<label class="spec-title"><i class="fas fa-sliders-h mr-1"></i>' + category + ' <?= __("properties") ?></label><div class="row">';
        fields.forEach(f => {
            let val = existingData[f.key] || existingData[f.label] || '';
            if (f.type === 'select') {
                let options = f.options.map(o => '<option value="' + o + '" ' + (o == val ? 'selected' : '') + '>' + (o || '-- <?= __("select") ?> --') + '</option>').join('');
                html += '<div class="col-md-4 col-6 mb-2"><label class="small text-muted mb-1" style="font-weight:600;">' + f.label + '</label><select name="spec[' + f.key + ']" class="form-control form-control-sm border bg-light select2-tags" style="width:100%;">' + options + '</select></div>';
            } else {
                html += '<div class="col-md-4 col-6 mb-2"><label class="small text-muted mb-1" style="font-weight:600;">' + f.label + '</label><input type="text" name="spec[' + f.key + ']" class="form-control form-control-sm border bg-light" value="' + escHtml(val) + '" placeholder="' + (f.placeholder || '') + '"></div>';
            }
        });
        html += '</div>';
        container.innerHTML = html;
        const select2Lang = {
            noResults: function () { return "<?= $isTr ? 'Sonuç bulunamadı' : 'No results found' ?>"; },
            searching: function () { return "<?= $isTr ? 'Aranıyor...' : 'Searching...' ?>"; }
        };
        setTimeout(() => $('.select2-tags').select2({ tags: true, theme: 'bootstrap4', width: '100%', language: select2Lang }), 50);
    }


    function onTypeChange() {
        const typeSelect = $('#category_id');
        const catId = typeSelect.val();
        const assetId = $('#asset_id').val();
        const typeText = typeSelect.find('option:selected').text().trim();
        const techSection = $('#technicalSpecsSection');
        const container = $('#dynamic-custom-fields-container');

        if (!catId) {
            techSection.slideUp();
            container.empty();
            return;
        }

        // Immediate clear to prevent stale data
        container.html('<div class="col-md-12 text-center py-3"><i class="fas fa-spinner fa-spin mr-2 text-primary"></i> <span class="text-muted small"><?= $isTr ? "Yükleniyor..." : "Loading..." ?></span></div>');

        $.getJSON('varliklar?fetch_custom_fields=1&_=' + new Date().getTime(), { cat_id: catId, asset_id: assetId }, function (res) {
            container.empty();
            if (res.fields && res.fields.length > 0) {
                $('#customFieldsCard').show();
                techSection.hide();
                let currentGroup = "";
                let html = "";
                res.fields.forEach(f => {
                    const groupName = f.field_group || 'General';
                    if (groupName !== currentGroup) {
                        currentGroup = groupName;
                        html += '<div class="col-12"><h6 class="font-weight-bold text-primary border-bottom pb-2 mb-3 mt-4" style="font-size:0.85rem;"><i class="fas fa-microchip mr-1"></i> ' + escHtml(groupName) + '</h6></div>';
                    }
                    const val = (res.values && res.values[f.id]) ? res.values[f.id] : '';
                    let inputHTML = '';
                    const label = f.field_label || f.field_name || 'Field';
                    if (f.field_type === 'select') {
                        const opts = (f.options || '').split(',');
                        inputHTML = '<select name="custom_fields[' + f.id + ']" class="form-control shadow-sm border-light" style="border-radius:10px;"><option value=""><?= $isTr ? "Seçiniz..." : "Select..." ?></option>' + opts.filter(o => o.trim()).map(o => '<option value="' + o.trim() + '" ' + (String(val) === String(o.trim()) ? "selected" : "") + '>' + o.trim() + '</option>').join('') + '</select>';
                    } else {
                        inputHTML = '<input type="' + f.field_type + '" name="custom_fields[' + f.id + ']" class="form-control shadow-sm border-light" value="' + val + '" placeholder="' + label + '" autocomplete="new-password" style="border-radius:10px;">';
                    }
                    html += '<div class="col-12 form-group mb-3"><label class="small font-weight-bold text-muted mb-1">' + label + '</label>' + inputHTML + '</div>';
                });
                container.html(html);
            } else {
                $('#customFieldsCard').hide();
                container.empty();
            }
        });
    }

    function addSpecRow(key = '', val = '') {
        const id = 'spec_' + Math.random().toString(36).substr(2, 9);
        const html = '<div class="row mb-2 spec-row align-items-center p-1" id="' + id + '">' +
            '<div class="col-5 pl-2"><input type="text" name="spec_key[]" class="form-control form-control-sm border-0 bg-transparent font-weight-600" placeholder="<?= __("Açıklama") ?>" value="' + escHtml(key) + '"></div>' +
            '<div class="col-6"><input type="text" name="spec_val[]" class="form-control form-control-sm border-0 border-left bg-transparent" placeholder="<?= __("Değer") ?>" value="' + escHtml(val) + '" style="border-left: 2px solid #eee !important; border-radius:0;"></div>' +
            '<div class="col-1 p-0 text-center"><button type="button" class="btn btn-sm text-danger opacity-50" onclick="$(\'#' + id + '\').fadeOut(200, function(){ $(this).remove(); })"><i class="fas fa-times"></i></button></div>' +
            '</div>';
        $('#specsContainer').append(html);
    }

    function togglePredefinedGroup(groupKey) {
        const rows = $('.rows-' + groupKey);
        const header = $('.predefined-group-header[data-group="' + groupKey + '"]');
        const icon = header.find('.toggle-icon');
        rows.toggle();
        const isVisible = rows.is(':visible');
        icon.toggleClass('fa-chevron-right', !isVisible).toggleClass('fa-chevron-down', isVisible);

        let states = {};
        try { states = JSON.parse(localStorage.getItem('predefined_groups') || '{}'); } catch (e) { }
        states[groupKey] = isVisible;
        localStorage.setItem('predefined_groups', JSON.stringify(states));
    }

    function restoreGroupStates() {
        let states = {};
        try { states = JSON.parse(localStorage.getItem('predefined_groups') || '{}'); } catch (e) { }
        Object.keys(states).forEach(key => {
            if (states[key]) {
                const rows = $('.rows-' + key);
                rows.show();
                $('.predefined-group-header[data-group="' + key + '"]').find('.toggle-icon').removeClass('fa-chevron-right').addClass('fa-chevron-down');
            }
        });
    }

    $(document).ready(function () {
        $(document).on('change', '#model_id', function() {
            const selectedModelId = $(this).val();
            if (selectedModelId && typeof lookupData !== 'undefined' && lookupData.models) {
                const model = lookupData.models.find(m => String(m.id) === String(selectedModelId));
                if (model) {
                    if (model.category_id) {
                        const currentCatId = $('#category_id').val();
                        if (String(currentCatId) !== String(model.category_id)) {
                            $('#category_id').val(model.category_id).trigger('change');
                        }
                    }
                    if (model.manufacturer_id) {
                        const currentManId = $('#manufacturer_id').val();
                        if (String(currentManId) !== String(model.manufacturer_id)) {
                            $('#manufacturer_id').val(model.manufacturer_id).trigger('change');
                        }
                    }
                }
            }
        });

        $(document).on('change', '#assign_source_checkout_id', function () {
            const val = $(this).val();
            if (val === 'free') {
                $('#sourcePreviewName').html('--- ' + (isTr ? 'Boş Koltuk' : 'Free Seat') + ' ---');
            } else {
                const text = $(this).find('option:selected').text();
                $('#sourcePreviewName').html(text);
            }
        });

        // Fix for dropdown menu clipping in responsive tables:
        // When a dropdown is shown, append it to body and position it absolute.
        $(document).on('show.bs.dropdown', '.table-responsive .dropdown', function() {
            var $dropdown = $(this).find('.dropdown-menu');
            var $button = $(this).find('[data-toggle="dropdown"]');
            
            $dropdown.data('original-parent', $(this));
            $('body').append($dropdown);
            
            var offset = $button.offset();
            var buttonHeight = $button.outerHeight();
            var dropdownHeight = $dropdown.outerHeight();
            var dropdownWidth = $dropdown.outerWidth();
            var windowWidth = $(window).width();
            var windowHeight = $(window).height();
            var scrollTop = $(window).scrollTop();
            
            var left = offset.left;
            if (left + dropdownWidth > windowWidth) {
                left = offset.left + $button.outerWidth() - dropdownWidth;
            }
            
            var top = offset.top + buttonHeight;
            if (top + dropdownHeight > windowHeight + scrollTop && offset.top - dropdownHeight >= scrollTop) {
                top = offset.top - dropdownHeight;
            }
            
            $dropdown.css({
                position: 'absolute',
                top: top + 'px',
                left: left + 'px',
                display: 'block',
                margin: '0',
                pointerEvents: 'auto'
            });
        });
        
        $(document).on('hide.bs.dropdown', '.table-responsive .dropdown', function() {
            var $dropdown = $('body > .dropdown-menu');
            if ($dropdown.length) {
                var $parent = $dropdown.data('original-parent');
                if ($parent) {
                    $parent.append($dropdown.css({
                        position: '',
                        top: '',
                        left: '',
                        display: '',
                        margin: '',
                        pointerEvents: ''
                    }));
                }
            }
        });

        restoreGroupStates();

        window.copyToClipboard = function(text, element) {
            if (!text) return;
            
            function fallbackCopy(txt) {
                var textArea = document.createElement("textarea");
                textArea.value = txt;
                textArea.style.position = "fixed";
                textArea.style.left = "-999999px";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    var successful = document.execCommand('copy');
                    if (successful) {
                        showFeedback();
                    } else {
                        console.error('Fallback copy failed');
                    }
                } catch (err) {
                    console.error('Fallback copy error', err);
                }
                document.body.removeChild(textArea);
            }
            
            function showFeedback() {
                if (element) {
                    var $el = $(element);
                    var originalText = $el.text();
                    $el.text(isTr ? 'Kopyalandı!' : 'Copied!').addClass('text-success');
                    setTimeout(function() {
                        $el.text(originalText).removeClass('text-success');
                    }, 1500);
                } else {
                    Swal.fire({
                        icon: 'success',
                        title: isTr ? 'Kopyalandı!' : 'Copied!',
                        timer: 1000,
                        showConfirmButton: false
                    });
                }
            }
            
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    showFeedback();
                }).catch(function() {
                    fallbackCopy(text);
                });
            } else {
                fallbackCopy(text);
            }
        };
    });

    function renderCategoryOptions(expectedType) {
        const cats = lookupData.categories.filter(c => String(c.type || '').toLowerCase() === expectedType);
        const parents = cats.filter(c => !c.parent_id);
        const children = cats.filter(c => c.parent_id);
        let html = '';
        parents.forEach(p => {
            const label = isTr ? p.name : (p.name_en || p.name);
            const ch = children.filter(x => parseInt(x.parent_id) === parseInt(p.id));
            if (ch.length > 0) {
                html += '<optgroup label="' + label + '">';
                ch.forEach(c => {
                    const text = isTr ? c.name : (c.name_en || c.name);
                    html += '<option value="' + c.id + '">' + text + '</option>';
                });
                html += '</optgroup>';
            } else {
                html += '<option value="' + p.id + '">' + label + '</option>';
            }
        });
        return html;
    }

    function rebuildParentOptions(expectedType) {
        const $sel = $('#p_parent_id');
        if (!$sel.length) return;
        const placeholder = '<?= $isTr ? 'Üst kategori seçin' : 'Select parent category' ?>';
        $sel.empty().append('<option value="">-- ' + placeholder + ' --</option>');
        const parents = (lookupData.categories || []).filter(c => !c.parent_id && categoryMatches(c, expectedType));
        parents.forEach(p => {
            const label = isTr ? p.name : (p.name_en || p.name);
            $sel.append('<option value="' + p.id + '" data-type="' + (p.type || '') + '">' + escHtml(label) + '</option>');
        });
    }

    function confirmDelete(id, assignedTo = '', linkedSummary = '', itemName = '', curView = 'assets') {
        const isAssigned = (assignedTo && assignedTo !== '' && assignedTo !== '—' && assignedTo !== 'null' && assignedTo !== 'undefined');
        const hasLinks = (linkedSummary && linkedSummary !== '' && linkedSummary !== '—' && linkedSummary !== 'null' && linkedSummary !== 'undefined');
        if ((isAssigned || hasLinks) && curView !== 'consumables') {
            let msg = '<strong>' + itemName + '</strong> <?= $isTr ? "şu anda silinemez." : "cannot be deleted at this time." ?><br><br>';
            if (isAssigned) msg += '<?= $isTr ? "Bu varlık şu anda" : "This item is currently assigned to" ?> <strong>' + assignedTo + '</strong> <?= $isTr ? "üzerinde zimmetlidir." : "." ?><br>';
            if (hasLinks) msg += '<div class="alert alert-warning py-2 px-3 small text-left mt-2" style="border-radius:10px; border:none; background:rgba(245,158,11,0.1); color:#92400e;"><i class="fas fa-link mr-2"></i><strong><?= $isTr ? "Bağlı Nesneler:" : "Linked Items:" ?></strong><br>' + linkedSummary + '</div>';
            msg += '<br><?= $isTr ? "Silme işlemini gerçekleştirmek için önce zimmetleri iade almanız (Check-in) gerekmektedir." : "To proceed with deletion, you must first check-in (return) all assignments." ?>';

            Swal.fire({
                html: msg,
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#0891b2',
                cancelButtonColor: '#64748b',
                confirmButtonText: '<?= $isTr ? 'Detaylara Git ve İade Al' : 'Go to Details & Return' ?>',
                cancelButtonText: '<?= __("close") ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    let hash = '';
                    if (curView === 'assets') hash = '#tab-zimmet';
                    else if (curView === 'accessories' || curView === 'licenses') hash = '#tab-related-mixed';
                    else if (curView === 'consumables') hash = '#tab-usage';
                    window.location.href = 'varlik-detay/' + id + '?view=' + curView + hash;
                }
            });
            return;
        }

        let mainMsg = itemName ? '<strong>' + itemName + '</strong>' : '<?= $isTr ? "Bu varlığı" : "this asset" ?>';
        let text = 'Bu ' + mainMsg + ' <?= $isTr ? "kalıcı olarak silmek istediğinizden emin misiniz? Bu işlem geri alınamaz." : "are you sure you want to delete permanently? This action cannot be undone." ?>';

        Swal.fire({
            title: '<?= __("are_you_sure") ?>',
            html: text,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: '<?= __("yes_delete") ?>',
            cancelButtonText: '<?= __("cancel") ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                const type = '<?= $_GET['type'] ?? '' ?>';
                form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="view" value="<?= $view ?>"><input type="hidden" name="type" value="' + type + '"><input type="hidden" name="asset_id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    // Functions moved to top to avoid ReferenceErrors


    $(document).on('change', '#selectAll, #selectAllGeneric', function () {
        $('.selectItem').prop('checked', $(this).is(':checked'));
    });

    function filterAssetTable() {
        const query = ($('#assetSearch').val() || '').toLowerCase().trim();
        const catFilter = ($('#filterCategory').val() || '').toString().trim();
        const supFilter = ($('#filterSupplier').val() || '').toLowerCase();

        // Filter Assets/Main table
        $('table tbody tr:not(.no-filter)').each(function () {
            const row = $(this);
            const rowText = (row.text() || "").toLowerCase();
            const searchTerms = (row.data('search-terms') || "").toString().toLowerCase();
            
            const matchesQuery = query === '' || rowText.indexOf(query) > -1 || searchTerms.indexOf(query) > -1;
            const rowCatId = row.data('category-id') || row.find('.col-category a').data('category-id');
            const supVal = row.find('.col-supplier').text().toLowerCase().trim();

            let matchesCat = true;
            if (catFilter !== '') {
                const filterId = parseInt(catFilter);
                if (!isNaN(filterId)) {
                    const isChild = (lookupData.categories || []).some(c => parseInt(c.id) === parseInt(rowCatId) && parseInt(c.parent_id) === filterId);
                    matchesCat = (parseInt(rowCatId) === filterId) || isChild;
                }
            }
            const matchesSup = supFilter === '' || supVal.indexOf(supFilter) > -1;

            const isVisible = matchesQuery && matchesCat && matchesSup;
            row.toggle(isVisible);

            const urlParams = new URLSearchParams(window.location.search);
            const hid = urlParams.get('highlight_id');
            if (query !== '') {
                if (isVisible) {
                    row.addClass('row-highlight-pulse');
                } else {
                    row.removeClass('row-highlight-pulse');
                }
            } else {
                const rowId = row.data('id');
                if (hid && String(rowId) === String(hid)) {
                    row.addClass('row-highlight-pulse');
                } else {
                    row.removeClass('row-highlight-pulse');
                }
            }
        });

        // Filter History Logs if they have data-category-id
        if (catFilter !== '') {
            const filterId = parseInt(catFilter);
            $('#history-card table tbody tr').each(function() {
                const row = $(this);
                const rowCatId = parseInt(row.data('category-id'));
                if (!isNaN(rowCatId)) {
                    const isChild = (lookupData.categories || []).some(c => parseInt(c.id) === rowCatId && parseInt(c.parent_id) === filterId);
                    row.toggle(rowCatId === filterId || isChild);
                }
            });
        } else {
            $('#history-card table tbody tr').show();
        }
    }



    function toggleAllCols(show) {
        $('.col-vis-toggle').each(function () {
            $(this).prop('checked', show).trigger('change');
        });
    }

    window.toggleFullScreen = function() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch((err) => {
                console.log(`Error attempting to enable fullscreen: ${err.message} (${err.name})`);
            });
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        }
    }

    function trToEn(text) {
        return text ? text.replace(/Ğ/g, 'G').replace(/Ü/g, 'U').replace(/Ş/g, 'S').replace(/İ/g, 'I').replace(/Ö/g, 'O').replace(/Ç/g, 'C')
                          .replace(/ğ/g, 'g').replace(/ü/g, 'u').replace(/ş/g, 's').replace(/ı/g, 'i').replace(/ö/g, 'o').replace(/ç/g, 'c') : '';
    }

    function openExportModal(type) {
        $('#exportType').val(type);
        
        const isPdf = type === 'pdf';
        if(isPdf) {
            $('#exportModalTitle').text('<?= __("export_customize_pdf") ?>');
            $('#btnExecuteExport').html('<i class="fas fa-file-pdf mr-2"></i><?= __("create_pdf") ?>').removeClass('btn-success').addClass('btn-danger');
            $('#exportOrientationGroup').show();
            $('#exportPaperSizeGroup').show();
            $('#btnExecuteExport i').removeClass().addClass('fas fa-file-pdf mr-2');
            $('#excelWarning').addClass('d-none');
        } else {
            $('#exportModalTitle').text('<?= __("export_customize_excel") ?>');
            $('#btnExecuteExport').html('<i class="fas fa-file-excel mr-2"></i><?= __("create_excel") ?>').removeClass('btn-danger').addClass('btn-success');
            $('#exportOrientationGroup').hide();
            $('#exportPaperSizeGroup').hide();
            $('#btnExecuteExport i').removeClass().addClass('fas fa-file-excel mr-2');
            $('#excelWarning').removeClass('d-none');
        }

        if ('<?= $view ?>' === 'assets') {
            $('#exportTechDetailsGroup').removeClass('d-none');
            $('#exportTechDetails').prop('checked', true);
            $('#exportTechSubColumnsContainer').removeClass('d-none');
        } else {
            $('#exportTechDetailsGroup').addClass('d-none');
            $('#exportTechDetails').prop('checked', false);
            $('#exportTechSubColumnsContainer').addClass('d-none');
        }

        $('#exportTechDetails').off('change').on('change', function() {
            $('#exportTechSubColumnsContainer').toggleClass('d-none', !this.checked);
        });

        let $table = $('table').filter(function() {
            return $(this).find('tbody tr[data-id]').length > 0;
        }).first();
        if ($table.length === 0) {
            $table = $('table:not(.no-export)').filter(function() {
                return $(this).css('display') !== 'none' && $(this).closest('div').css('display') !== 'none';
            }).first();
            if ($table.length === 0) $table = $('table').first();
        }

        let blacklist = ['işlemler', 'actions', 'cihaz resmi', 'device image', 'i̇şlemler', 'nav', 'def', 'seçenekler', 'options', 'simge', 'kategori'];
        let html = '';
        
        $table.find('thead tr:first-child th').each(function(index) {
            let $th = $(this);
            let titleText = $th.text().trim();
            let lowerTitle = titleText.toLowerCase();
            
            if (!$th.hasClass('no-export') && !blacklist.includes(lowerTitle) && titleText !== '') {
                let checked = $th.css('display') !== 'none' ? 'checked' : '';
                html += `
                <div class="col-md-6 mb-2">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input export-col-cb" id="exp_col_${index}" value="${index}" data-title="${escHtml(titleText)}" ${checked}>
                        <label class="custom-control-label" for="exp_col_${index}">${escHtml(titleText)}</label>
                    </div>
                </div>`;
            }
        });
        
        $('#exportColumnsContainer').html(html);
        $('#exportCustomizeModal').modal('show');
    }

    function executeExport() {
        const type = $('#exportType').val();
        const scope = $('input[name="exportScope"]:checked').val();
        const orientation = $('input[name="exportOrientation"]:checked').val() === 'landscape' ? 'l' : 'p';
        const paperSize = $('input[name="exportPaperSize"]:checked').val() || 'a4';
        const themeColor = $('#exportThemeColor').val() || '#ea580c';
        const includeTechDetails = $('#exportTechDetails').is(':checked');
        
        let selectedColIndexes = [];
        let selectedColTitles = [];
        $('.export-col-cb:checked').each(function() {
            selectedColIndexes.push(parseInt($(this).val()));
            selectedColTitles.push($(this).data('title'));
        });
        
        if (selectedColIndexes.length === 0) {
            Swal.fire('<?= $isTr ? "Uyarı" : "Warning" ?>', '<?= $isTr ? "Lütfen en az bir sütun seçin." : "Please select at least one column." ?>', 'warning');
            return;
        }

        let selectedTechKeys = [];
        if (includeTechDetails) {
            $('.tech-sub-cb:checked').each(function() {
                selectedTechKeys.push($(this).val());
            });
        }

        const btn = $('#btnExecuteExport');
        const origBtnHtml = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i><?= __("loading") ?>...');

        function proceedWithTable($targetTable) {
            if (includeTechDetails) {
                let ids = [];
                $targetTable.find('tbody tr').each(function() {
                    let $tr = $(this);
                    if ($tr.hasClass('no_records_found') || $tr.find('.dataTables_empty').length || $tr.css('display') === 'none') return;
                    let id = $tr.attr('data-id');
                    if (id) ids.push(id);
                });
                
                if (ids.length > 0) {
                    $.get(window.location.pathname, { get_assets_tech_details: ids.join(','), route: 'varliklar' }, function(techDetailsMap) {
                        let unCheckedTechKeys = [];
                        $('.tech-sub-cb:not(:checked)').each(function() {
                            unCheckedTechKeys.push($(this).val().toLowerCase());
                        });

                        let finalTechKeys = [];
                        for (let assetId in techDetailsMap) {
                            for (let k in techDetailsMap[assetId]) {
                                let kLower = k.toLowerCase();
                                if (!unCheckedTechKeys.includes(kLower) && !finalTechKeys.includes(k)) {
                                    finalTechKeys.push(k);
                                }
                            }
                        }

                        let filteredTechMap = {};
                        for (let assetId in techDetailsMap) {
                            filteredTechMap[assetId] = {};
                            finalTechKeys.forEach(k => {
                                filteredTechMap[assetId][k] = techDetailsMap[assetId][k] !== undefined ? techDetailsMap[assetId][k] : '-';
                            });
                        }
                        
                        generateExportFile(type, orientation, selectedColIndexes, selectedColTitles, $targetTable, function() {
                            btn.prop('disabled', false).html(origBtnHtml);
                            $('#exportCustomizeModal').modal('hide');
                        }, filteredTechMap, finalTechKeys, paperSize, themeColor);
                    }, 'json').fail(function() {
                        generateExportFile(type, orientation, selectedColIndexes, selectedColTitles, $targetTable, function() {
                            btn.prop('disabled', false).html(origBtnHtml);
                            $('#exportCustomizeModal').modal('hide');
                        }, null, null, paperSize, themeColor);
                    });
                } else {
                    generateExportFile(type, orientation, selectedColIndexes, selectedColTitles, $targetTable, function() {
                        btn.prop('disabled', false).html(origBtnHtml);
                        $('#exportCustomizeModal').modal('hide');
                    }, null, null, paperSize, themeColor);
                }
            } else {
                generateExportFile(type, orientation, selectedColIndexes, selectedColTitles, $targetTable, function() {
                    btn.prop('disabled', false).html(origBtnHtml);
                    $('#exportCustomizeModal').modal('hide');
                }, null, null, paperSize, themeColor);
            }
        }

        if (scope === 'all') {
            let url = new URL(window.location.href);
            url.searchParams.set('limit', '10000');
            url.searchParams.set('page', '1');
            
            $.get(url.href, function(htmlResponse) {
                let parser = new DOMParser();
                let doc = parser.parseFromString(htmlResponse, 'text/html');
                let $remoteTable = $(doc).find('table').filter(function() {
                    return $(this).find('tbody tr[data-id]').length > 0;
                }).first();
                if ($remoteTable.length === 0) {
                    $remoteTable = $(doc).find('table:not(.no-export)').first();
                    if ($remoteTable.length === 0) $remoteTable = $(doc).find('table').first();
                }
                
                proceedWithTable($remoteTable);
            }).fail(function() {
                Swal.fire('Error', '<?= $isTr ? "Veriler alınırken hata oluştu." : "Failed to fetch data." ?>', 'error');
                btn.prop('disabled', false).html(origBtnHtml);
            });
        } else {
            let $localTable = $('table').filter(function() {
                return $(this).find('tbody tr[data-id]').length > 0;
            }).first();
            if ($localTable.length === 0) {
                $localTable = $('table:not(.no-export)').filter(function() {
                    return $(this).css('display') !== 'none' && $(this).closest('div').css('display') !== 'none';
                }).first();
                if ($localTable.length === 0) $localTable = $('table').first();
            }
            
            proceedWithTable($localTable);
        }
    }

    function generateExportFile(type, orientation, colIndexes, colTitles, $table, onComplete, techDetailsMap = null, allTechKeys = null, paperSize = 'a4', themeColor = '#ea580c') {
        let rows = [];
        
        $table.find('tbody tr').each(function() {
            let $tr = $(this);
            if ($tr.hasClass('no_records_found') || $tr.find('.dataTables_empty').length || $tr.css('display') === 'none') return;
            
            let $cols = $tr.find('td');
            if ($cols.length === 0 || ($cols.length === 1 && $cols.first().attr('colspan') > 1)) return;
            
            let rowData = [];
            for (let j = 0; j < colIndexes.length; j++) {
                let cellIndex = colIndexes[j];
                let $cell = $cols.eq(cellIndex);
                if ($cell.length) {
                    // Special handling for status cells: extract status text + badge text cleanly
                    if ($cell.hasClass('col-status')) {
                        let statusText = $cell.find('a').first().text().replace(/\s+/g, ' ').trim();
                        let badgeText = $cell.find('.badge').first().text().replace(/\s+/g, ' ').trim();
                        let cellVal = statusText;
                        if (badgeText) cellVal += ' (' + badgeText + ')';
                        rowData.push(cellVal || $cell.text().replace(/\s+/g, ' ').trim());
                    } else {
                        rowData.push($cell.text().replace(/\s+/g, ' ').trim());
                    }
                } else {
                    rowData.push('');
                }
            }
            
            if (techDetailsMap && allTechKeys) {
                let assetId = $tr.attr('data-id');
                let assetTech = techDetailsMap[assetId] || {};
                allTechKeys.forEach(key => {
                    rowData.push(assetTech[key] || '-');
                });
            }
            
            if (rowData.length > 0) rows.push(rowData);
        });

        let exportColTitles = [...colTitles];
        if (allTechKeys) {
            exportColTitles = exportColTitles.concat(allTechKeys);
        }

        const view = '<?= $view ?>';
        const isTr = <?= $isTr ? 'true' : 'false' ?>;
        const companyName = <?= json_encode(s('company_name') ?: 'Eaprimus') ?>;
        
        let titleTr = "";
        let titleEn = "";
        let filePrefix = "Rapor";
        
        if (view === 'assets') {
            titleTr = companyName + " Envanter Listesi";
            titleEn = companyName + " Inventory List";
            filePrefix = "Envanter_Listesi";
        } else if (view === 'licenses') {
            titleTr = companyName + " Lisans Listesi";
            titleEn = companyName + " License List";
            filePrefix = "Lisans_Listesi";
        } else if (view === 'accessories') {
            titleTr = companyName + " Aksesuar Listesi";
            titleEn = companyName + " Accessory List";
            filePrefix = "Aksesuar_Listesi";
        } else if (view === 'consumables') {
            titleTr = companyName + " Sarf Malzemesi Listesi";
            titleEn = companyName + " Consumable List";
            filePrefix = "Sarf_Malzemesi_Listesi";
        } else if (view === 'components') {
            titleTr = companyName + " Bileşen Listesi";
            titleEn = companyName + " Component List";
            filePrefix = "Bilesen_Listesi";
        } else {
            titleTr = companyName + " Raporu";
            titleEn = companyName + " Report";
            filePrefix = "Rapor";
        }
        
        let finalTitle = isTr ? titleTr : titleEn;
        const dateText = "<?= date('d.m.Y H:i') ?>";
        let filename = filePrefix + "_" + dateText.replace(/[: ]/g, "_");

        function hexToRgb(hex) {
            let result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? [
                parseInt(result[1], 16),
                parseInt(result[2], 16),
                parseInt(result[3], 16)
            ] : [234, 88, 12];
        }

        if (type === 'pdf') {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF(orientation, 'pt', paperSize);
            const fontUrl = 'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.66/fonts/Roboto/Roboto-Regular.ttf';
            
            fetch(fontUrl)
                .then(res => res.arrayBuffer())
                .then(buffer => {
                    let binary = '';
                    const bytes = new Uint8Array(buffer);
                    for (let i = 0; i < bytes.byteLength; i++) {
                        binary += String.fromCharCode(bytes[i]);
                    }
                    const base64Font = window.btoa(binary);
                    doc.addFileToVFS('Roboto-Regular.ttf', base64Font);
                    doc.addFont('Roboto-Regular.ttf', 'Roboto', 'normal');
                    
                    renderAndSavePDF(doc, true);
                })
                .catch(err => {
                    console.error('Roboto font load failed, falling back to standard font with trToEn.', err);
                    renderAndSavePDF(doc, false);
                });
                
            function renderAndSavePDF(doc, useUnicode) {
                if (useUnicode) {
                    doc.setFont('Roboto');
                } else {
                    doc.setFont('helvetica');
                }
                
                doc.setFontSize(18);
                doc.text(useUnicode ? finalTitle : trToEn(finalTitle), 40, 40);
                
                doc.setFontSize(10);
                doc.setTextColor(100);
                doc.text(dateText, 40, 60);

                let head = [ exportColTitles.map(t => useUnicode ? t : trToEn(t)) ];
                let body = rows.map(r => r.map(c => useUnicode ? c : trToEn(c)));

                // Calculate dynamic font size and cell padding based on number of columns and available page width
                let availWidth = doc.internal.pageSize.width - 80;
                let numCols = exportColTitles.length;
                let avgColWidth = availWidth / numCols;
                
                let fontSize = 8;
                if (avgColWidth < 22) fontSize = 4.5;
                else if (avgColWidth < 28) fontSize = 5.5;
                else if (avgColWidth < 38) fontSize = 6.5;
                else if (avgColWidth < 50) fontSize = 7.5;
                
                let cellPadding = 4;
                if (fontSize <= 4.8) cellPadding = 1.0;
                else if (fontSize <= 5.8) cellPadding = 1.5;
                else if (fontSize <= 6.8) cellPadding = 2.0;
                else if (fontSize <= 7.8) cellPadding = 3.0;

                let columnStyles = {};
                let licenseColIndex = exportColTitles.findIndex(t => t.includes('Ürün Anahtarı') || t.includes('Product Key') || t.includes('Lisans') || t.includes('License'));
                if (licenseColIndex !== -1) {
                    columnStyles[licenseColIndex] = {
                        textColor: [0, 67, 201], // #0043c9 blue color
                        fontStyle: 'bold'
                    };
                }

                doc.autoTable({
                    head: head,
                    body: body,
                    startY: 80,
                    margin: { left: 20, right: 20, top: 80, bottom: 30 },
                    theme: 'striped',
                    headStyles: { fillColor: hexToRgb(themeColor) },
                    columnStyles: columnStyles,
                    styles: { 
                        font: useUnicode ? 'Roboto' : 'helvetica',
                        fontSize: fontSize, 
                        cellPadding: cellPadding, 
                        overflow: 'linebreak'
                    }
                });

                doc.save(filename + '.pdf');
                if (onComplete) onComplete();
            }
        } else {
            // Excel Export (Formatlı .xls HTML Table)
            let html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
            html += '<head><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Rapor</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--><meta charset="utf-8"></head><body>';
            html += '<table border="1" style="border-collapse:collapse; font-family:\'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size:10pt;">';
            
            // Header Row
            html += '<thead><tr style="background-color:' + themeColor + '; color:#ffffff; font-weight:bold; height:30px;">';
            exportColTitles.forEach(t => {
                html += `<th style="padding:5px 10px; border:1px solid #cccccc; text-align:left;">${escHtml(t)}</th>`;
            });
            html += '</tr></thead><tbody>';
            
            // Data Rows
            let licenseColIndex = exportColTitles.findIndex(t => t.includes('Ürün Anahtarı') || t.includes('Product Key') || t.includes('Lisans') || t.includes('License'));
            rows.forEach(r => {
                html += '<tr style="height:22px;">';
                r.forEach((c, idx) => {
                    let cellStyle = 'padding:5px 10px; border:1px solid #cccccc; text-align:left;';
                    if (idx === licenseColIndex && c !== '-') {
                        cellStyle += 'color:#0043c9; font-weight:bold;';
                    }
                    html += `<td style="${cellStyle}">${escHtml(c).replace(/\n/g, '<br />')}</td>`;
                });
                html += '</tr>';
            });
            html += '</tbody></table></body></html>';

            let blob = new Blob(["\uFEFF" + html], { type: "application/vnd.ms-excel;charset=utf-8" });
            let downloadLink = document.createElement("a");
            downloadLink.download = filename + ".xls";
            downloadLink.href = window.URL.createObjectURL(blob);
            downloadLink.style.display = "none";
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
            
            if (onComplete) onComplete();
        }
    }

    function confirmEmptyTrash() {
        Swal.fire({
            title: '<?= $isTr ? "Çöpü Boşalt" : "Empty Trash" ?>?',
            text: '<?= $isTr ? "Çöp kutusundaki tüm öğeler kalıcı olarak silinecektir. Bu işlem geri alınamaz!" : "All items in the trash will be permanently deleted. This action cannot be undone!" ?>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: '<?= $isTr ? "Evet, Temizle" : "Yes, Empty" ?>',
            cancelButtonText: '<?= __("cancel") ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="empty_trash"><input type="hidden" name="view" value="<?= $view ?>"><input type="hidden" name="type" value="<?= $_GET['type'] ?? '' ?>">';
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    function confirmPermanentDelete(id, view) {
        Swal.fire({
            title: '<?= $isTr ? "Kalıcı Olarak Sil" : "Permanently Delete" ?>?',
            text: '<?= $isTr ? "Bu öğe sistemden tamamen silinecek ve geri alınamayacak!" : "This item will be completely removed from the system and cannot be recovered!" ?>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: '<?= $isTr ? "Evet, Kalıcı Sil" : "Yes, Delete Permanently" ?>',
            cancelButtonText: '<?= __("cancel") ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                const type = '<?= $_GET['type'] ?? '' ?>';
                form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="view" value="' + view + '"><input type="hidden" name="type" value="' + type + '"><input type="hidden" name="asset_id" value="' + id + '"><input type="hidden" name="view_deleted" value="1">';
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    function applyBulkAction(type) {
        const action = type || $('#bulkAction').val();
        if (!action) return;

        let ids = [];
        $('.selectItem:checked').each(function () {
            ids.push($(this).val());
        });

        if (ids.length === 0) {
            Swal.fire('<?= $isTr ? "Uyarı" : "Warning" ?>', '<?= $isTr ? "Lütfen işlem yapılacak öğeleri seçin." : "Please select items." ?>', 'warning');
            return;
        }

        if (action === 'delete') {
            Swal.fire({
                title: '<?= $isTr ? "Toplu Silme" : "Bulk Delete" ?>',
                text: ids.length + ' <?= $isTr ? "adet öğeyi silmek istediğinizden emin misiniz?" : "items will be deleted. Are you sure?" ?>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<?= $isTr ? "Evet, Sil" : "Yes, Delete" ?>',
                cancelButtonText: '<?= __("Vazgeç") ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="bulk_delete"><input type="hidden" name="view" value="<?= $view ?>"><input type="hidden" name="ids" value="' + ids.join(',') + '"><input type="hidden" name="view_deleted" value="<?= $show_deleted ? '1' : '0' ?>">';
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    }



    window.showTimeline = function (assetId, itemType = 'asset') {
        const titleMap = {
            'asset': 'Cihaz Geçmişi',
            'license': 'Lisans Geçmişi',
            'accessory': 'Aksesuar Geçmişi',
            'consumable': 'Sarf Malzemesi Geçmişi',
            'component': 'Bileşen Geçmişi'
        };
        $('#timelineModal .modal-title').html('<i class="fas fa-history mr-2"></i>' + (titleMap[itemType] || 'Varlık Geçmişi'));
        $('#timelineContent').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2 text-dark"><?= __("loading") ?>...</p></div>');
        $('#timelineModal').modal('show');

        fetch('varliklar?timeline_asset_id=' + assetId + '&timeline_type=' + itemType)
            .then(r => r.json())
            .then(logs => {
                if (!logs || !logs.length) {
                    $('#timelineContent').html('<div class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x"></i><p class="mt-2"><?= addslashes(__("no_asset_logs_found")) ?></p></div>');
                    return;
                }

                const iconMap = {
                    'created': { icon: 'fa-plus-circle', color: '#10b981' },
                    'updated': { icon: 'fa-edit', color: '#3b82f6' },
                    'assigned': { icon: 'fa-user-check', color: '#f59e0b' },
                    'checkin': { icon: 'fa-undo', color: '#8b5cf6' },
                    'checkout': { icon: 'fa-share', color: '#f59e0b' },
                    'maintenance': { icon: 'fa-tools', color: '#f59e0b' },
                    'ticket_opened': { icon: 'fa-ticket-alt', color: '#ef4444' },
                    'deleted': { icon: 'fa-trash', color: '#ef4444' },
                    'restored': { icon: 'fa-trash-restore', color: '#10b981' }
                };

                if (typeof isTr === 'undefined') var isTr = <?= $isTr ? 'true' : 'false' ?>;
                const translateJS = (desc) => {
                    if (isTr) return desc;
                    const rules = [
                        [/sarf malzemesi (.*) cihazına atandı\. \((\d+) Miktar\)/gi, 'consumable assigned to $1. ($2 QTY)'],
                        [/Güncellenenler: Miktar: (\d+) -> (\d+)/gi, 'Updates: Quantity: $1 -> $2'],
                        [/(.*) bu cihaza atandı\. \((\d+) Adet\)/gi, '$1 assigned to this device. ($2 Pcs)'],
                        [/Sarf malzeme çöp kutusuna taşındı\./gi, 'Consumable moved to trash.'],
                        [/Devir: (.*) -> (.*)/gi, 'Handover: $1 -> $2'],
                        [/Cihaza atandı/gi, 'Assigned to device'],
                        [/Miktar: /gi, 'Quantity: '],
                        [/Güncellenenler: /gi, 'Updates: '],
                        [/Silindi/gi, 'Deleted'],
                        [/Atandı/gi, 'Assigned']
                    ];
                    rules.forEach(([regex, replacement]) => {
                        desc = desc.replace(regex, replacement);
                    });
                    return desc;
                };

                let html = '<div style="padding: 10px;">';
                logs.forEach(log => {
                    const ic = iconMap[log.event_type] || { icon: 'fa-circle', color: '#999' };
                    html += '<div class="timeline-item d-flex gap-3 mb-3" style="position:relative;">' +
                        '<div class="timeline-dot-container" style="min-width:40px; text-align:center; position:relative; z-index:2;">' +
                        '<div class="timeline-dot shadow-sm d-flex align-items-center justify-content-center" style="background:' + ic.color + '; width:28px; height:28px; border-radius:50%; border:3px solid #fff; color:#fff; font-size:12px;">' +
                        '<i class="fas ' + ic.icon + '"></i>' +
                        '</div>' +
                        '</div>' +
                        '<div class="timeline-body p-3 border-0 shadow-sm rounded-lg bg-white flex-grow-1" style="font-size:13px; margin-left:10px; border-left:4px solid ' + ic.color + ' !important;">' +
                        '<div class="d-flex justify-content-between mb-2">' +
                        '<span class="font-weight-bold text-dark" style="font-size:14px;">' + escHtml(translateJS(log.event_description)) + '</span>' +
                        '<small class="text-muted bg-light px-2 py-1 rounded" style="font-size:11px;"><i class="fas fa-clock mr-1"></i>' + log.created_at + '</small>' +
                        '</div>' +
                        '<div class="d-flex align-items-center text-muted small">' +
                        '<div class="bg-light rounded-circle mr-2 d-flex align-items-center justify-content-center" style="width:20px; height:20px; font-size:10px;">' +
                        '<i class="fas fa-user"></i>' +
                        '</div>' +
                        escHtml(log.performer_name || 'System') +
                        '</div>' +
                        <?php if (in_array((int)$current_user_role, [1, 3])): ?>
                        '<div class="mt-2 text-right border-top pt-2">' +
                        '<button class="btn btn-xs btn-link text-danger p-0 font-weight-bold" onclick="deleteLog(' + log.id + ')"><i class="fas fa-eye-slash mr-1"></i><?= $isTr ? "Gizle" : "Hide" ?></button>' +
                        '</div>' +
                        <?php endif; ?>
                        '</div>' +
                        '</div>';
                });
                html += '</div>';
                $('#timelineContent').html(html);
            })
            .catch(err => {
                $('#timelineContent').html('<div class="alert alert-danger m-3">Veri yüklenemedi.</div>');
            });
    }

    // Predefined Modal AJAX Submit
    $('#predefinedForm').on('submit', function (e) {
        if ($('#p_context').val() === 'predefined' && !$('#assetModal').is(':visible')) {
            // Standard behavior for predefined page: let it submit normally
            return;
        }

        e.preventDefault();
        const formData = new FormData(this);
        const submitBtn = $(this).find('button[type="submit"]');
        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i><?= __("saving") ?>...');

        const newName = $('#p_name').val();

        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
            .then(response => response.json().catch(() => ({ success: true }))) // fallback to true if not json
            .then((res) => {
                const type = $('#p_type').val();
                const typeLabels = {
                    'categories':    isTr ? 'Kategori'       : 'Category',
                    'models':        isTr ? 'Model'          : 'Model',
                    'manufacturers': isTr ? 'Üretici'        : 'Manufacturer',
                    'suppliers':     isTr ? 'Tedarikçi'      : 'Supplier',
                    'companies':     isTr ? 'Şirket'         : 'Company',
                    'departments':   isTr ? 'Bölüm'          : 'Department',
                    'status_labels': isTr ? 'Durum Etiketi'  : 'Status Label',
                    'custom_fields': isTr ? 'Özel Alan'      : 'Custom Field'
                };
                const typeLabel = typeLabels[type] || type;

                Swal.fire({
                    icon: 'success',
                    title: '<?= $isTr ? "Başarılı" : "Success" ?>',
                    text: typeLabel + ' <?= $isTr ? "başarıyla kaydedildi." : "saved successfully." ?>',
                    timer: 1500,
                    showConfirmButton: false
                });

                $('#predefinedModal').modal('hide');
                submitBtn.prop('disabled', false).html('<i class="fas fa-save mr-2"></i><?= __("save") ?>');

                // Refresh relevant dropdowns in the main Asset Modal and select the new item
                if (type === 'models') refreshDropdown('model_id', newName);
                if (type === 'suppliers') refreshDropdown('supplier_id', newName);
                if (type === 'categories') refreshDropdown('category_id', newName);
                if (type === 'manufacturers') refreshDropdown('manufacturer_id', newName);
                if (type === 'locations') refreshDropdown('location', newName);
                if (type === 'companies') refreshDropdown('company_id', newName);
                if (type === 'departments') refreshDropdown('department_id', newName);
                if (type === 'status_labels') refreshDropdown('status_id', newName);
            })
            .catch(err => {
                console.error('Save failed:', err);
                submitBtn.prop('disabled', false).html('<i class="fas fa-save mr-2"></i><?= __("save") ?>');
                Swal.fire('<?= __("error") ?>', '<?= $isTr ? "Kayıt sırasında bir hata oluştu." : "Error while saving." ?>', 'error');
            });
    });

    function refreshDropdown(selectId, selectNewName = '') {
        const typeMap = {
            'model_id': 'models',
            'supplier_id': 'suppliers',
            'category_id': 'categories',
            'manufacturer_id': 'manufacturers',
            'location': 'locations',
            'company_id': 'companies',
            'department_id': 'departments',
            'status_id': 'status_labels'
        };
        const type = typeMap[selectId];
        if (!type) return;

        fetch('varliklar?get_list=' + type)
            .then(r => r.json())
            .then(data => {
                const select = $('#' + selectId);
                const currentVal = select.val();
                select.empty();
                select.append('<option value=""><?= $isTr ? "Seçiniz..." : "Select..." ?></option>');
                let foundNewId = null;
                data.forEach(item => {
                    select.append('<option value="' + item.id + '">' + item.name + '</option>');
                    if (selectNewName && item.name.toLowerCase().trim() === selectNewName.toLowerCase().trim()) {
                        foundNewId = item.id;
                    }
                });
                if (foundNewId) {
                    select.val(foundNewId);
                } else {
                    select.val(currentVal);
                }
                if (select.hasClass('select2-hidden-accessible')) {
                    select.trigger('change');
                }
            });
    }

    // Initial render on modal open
    $('#assetModal').on('shown.bs.modal', function () {
        if (!$('#asset_id').val()) {
            renderStructuredSpecs($('#type').val());
        }
    });

    window.linkGroupToCategories = function(groupName, activeCategoryIds) {
        if (!activeCategoryIds) activeCategoryIds = [];
        
        let catOptions = '';
        (lookupData.categories || []).forEach(c => {
            if (c.type === 'asset' || !c.type) {
                let checked = activeCategoryIds.includes(c.id.toString()) ? 'checked' : '';
                catOptions += `
                <div class="custom-control custom-checkbox text-left mb-2">
                    <input type="checkbox" class="custom-control-input" id="link_cat_${c.id}" name="cat_ids[]" value="${c.id}" ${checked}>
                    <label class="custom-control-label font-weight-bold text-dark" for="link_cat_${c.id}" style="cursor:pointer;">${escHtml(c.name)}</label>
                </div>`;
            }
        });

        let desc = typeof isTr !== 'undefined' && isTr 
            ? "Bu gruptaki özel alanlar seçtiğiniz kategorilere bağlanır. Böylece sadece bu kategorilerdeki varlıklara işlem yaparken bu alanlar formda görünür."
            : "Custom fields in this group will be linked to the selected categories, appearing only when managing assets in these categories.";

        let formHtml = `
            <form id="linkGroupForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="${inventoryCsrfToken}">
                <input type="hidden" name="action" value="update_group_links">
                <input type="hidden" name="group_name" value="${escHtml(groupName)}">
                
                <div class="alert alert-info text-left small mb-3 shadow-sm rounded-lg" style="background-color: #f0fdf4; border-left: 4px solid #22c55e; color: #166534;">
                    <i class="fas fa-lightbulb mr-1"></i> ${desc}
                </div>

                <div class="p-3 border rounded text-left shadow-sm" style="max-height: 250px; overflow-y: auto; background: #fff;">
                    ${catOptions}
                    ${catOptions === '' ? '<div class="text-muted small">No categories found.</div>' : ''}
                </div>
            </form>
        `;

        Swal.fire({
            title: typeof isTr !== 'undefined' && isTr ? 'Kategorilerle İlişkilendir' : 'Link to Categories',
            html: formHtml,
            showCancelButton: true,
            confirmButtonText: typeof isTr !== 'undefined' && isTr ? 'Kaydet' : 'Save',
            cancelButtonText: typeof isTr !== 'undefined' && isTr ? 'İptal' : 'Cancel',
            confirmButtonColor: '#4f46e5',
            customClass: {
                popup: 'rounded-xl shadow-lg'
            },
            preConfirm: () => {
                document.getElementById('linkGroupForm').submit();
            }
        });
    };

    window.renameGroup = function(oldName) {
        Swal.fire({
            title: typeof isTr !== 'undefined' && isTr ? 'Grubu Yeniden Adlandır' : 'Rename Group',
            input: 'text',
            inputValue: oldName,
            showCancelButton: true,
            confirmButtonText: typeof isTr !== 'undefined' && isTr ? 'Kaydet' : 'Save',
            cancelButtonText: typeof isTr !== 'undefined' && isTr ? 'İptal' : 'Cancel',
            inputValidator: (value) => {
                if (!value) return typeof isTr !== 'undefined' && isTr ? 'Grup adı boş olamaz!' : 'Group name cannot be empty!';
            }
        }).then((res) => {
            if (res.isConfirmed && res.value !== oldName) {
                const form = document.createElement("form");
                form.method = "POST";
                form.style.display = "none";
                form.innerHTML = '<input type="hidden" name="csrf_token" value="' + inventoryCsrfToken + '">' +
                    '<input type="hidden" name="action" value="rename_field_group">' +
                    '<input type="hidden" name="old_group" value="' + escHtml(oldName) + '">' +
                    '<input type="hidden" name="new_group" value="' + escHtml(res.value) + '">';
                document.body.appendChild(form);
                form.submit();
            }
        });
    };

    $(document).ready(function () {
        // --- COLUMN VISIBILITY LOGIC ---
        const savedCols = JSON.parse(localStorage.getItem('inventory_columns') || '{}');

        // Apply saved state
        $('.col-vis-toggle').each(function () {
            const col = $(this).data('column');
            if (savedCols[col] !== undefined) {
                if (savedCols[col] === false) {
                    $(this).prop('checked', false);
                    $('.' + col).addClass('d-none');
                } else {
                    $(this).prop('checked', true);
                    $('.' + col).removeClass('d-none');
                }
            } else {
                const isChecked = $(this).is(':checked');
                if (!isChecked) {
                    $('.' + col).addClass('d-none');
                }
            }
        });

        $('.col-vis-toggle').on('change', function () {
            const col = $(this).data('column');
            const isChecked = $(this).is(':checked');
            if (isChecked) {
                $('.' + col).removeClass('d-none');
                savedCols[col] = true;
            } else {
                $('.' + col).addClass('d-none');
                savedCols[col] = false;
            }
            localStorage.setItem('inventory_columns', JSON.stringify(savedCols));
        });

        // Initial collapse for Predefined Views
        if ('<?= $view ?>' === 'predefined') {
            const typesToCollapse = ['categories', 'manufacturers', 'custom_fields'];
            if (typesToCollapse.includes('<?= $predefinedType ?? '' ?>')) {
                $('.predefined-group-header').each(function () {
                    const g = $(this).data('group');
                    const isSavedOpen = localStorage.getItem('predefined_group_' + g) === 'open';
                    if (!isSavedOpen) {
                        // Collapse by default
                        $('.rows-' + g).hide();
                        $(this).find('.toggle-icon').removeClass('fa-chevron-down').addClass('fa-chevron-right');
                    } else {
                        $('.rows-' + g).show();
                        $(this).find('.toggle-icon').removeClass('fa-chevron-right').addClass('fa-chevron-down');
                    }
                });
            }
        }

        // Trigger filter on load
        filterAssetTable();

        // Auto scroll to highlighted item from recent activity 
        const urlParams = new URLSearchParams(window.location.search);
        const hid = urlParams.get('highlight_id');
        if (hid) {
            setTimeout(function () {
                const targetRow = $('tr[data-id="' + hid + '"]');
                if (targetRow.length) {
                    $('html, body').animate({
                        scrollTop: targetRow.offset().top - 200
                    }, 600);
                    targetRow.addClass('row-highlight-pulse');

                    // If it's a category inside a group, expand it
                    const parentGroup = targetRow.closest('tr').prevAll('.predefined-group-header').first();
                    if (parentGroup.length) {
                        const gKey = parentGroup.data('group');
                        const rows = $('.rows-' + gKey);
                        if (rows.length && rows.first().is(':hidden')) {
                            parentGroup.click(); // Expand
                        }
                    }
                }
            }, 800);
        }

        if (typeof populateCategorySelect === 'function') {
            populateCategorySelect();
        }

        <?php if (($_GET['action'] ?? '') == 'new'): ?>
            (function() {
                let attempts = 0;
                function triggerNew() {
                    const modal = $('#assetModal');
                    if (typeof addAssetByView === 'function' && modal.length && typeof modal.modal === 'function') {
                        addAssetByView('<?= $view ?>');
                    } else if (attempts < 20) {
                        attempts++;
                        setTimeout(triggerNew, 150);
                    }
                }
                setTimeout(triggerNew, 200);
            })();
        <?php elseif (($_GET['action'] ?? '') == 'edit' && isset($_GET['id'])): ?>
            <?php
            $editIdVal = intval($_GET['id']);
            $map = [
                'assets' => [
                    'table' => 'assets',
                    'joins' => "LEFT JOIN users u ON a.assigned_user_id = u.id LEFT JOIN asset_models m ON a.model_id = m.id LEFT JOIN asset_categories c ON a.category_id = c.id LEFT JOIN asset_status_labels sl ON a.status_id = sl.id LEFT JOIN bolumler dept ON a.department_id = dept.id LEFT JOIN asset_suppliers supp ON a.supplier_id = supp.id LEFT JOIN asset_manufacturers manu ON a.manufacturer_id = manu.id",
                    'fields' => "a.*, u.fullname as assigned_user, m.name as model_name, m.image as model_image, c.name as category_name, c.type as type, sl.name as status_label_name, dept.bolum_adi as dept_name, supp.name as supplier_name, manu.name as manufacturer_name"
                ],
                'licenses' => [
                    'table' => 'asset_licenses',
                    'joins' => "LEFT JOIN asset_categories c ON a.category_id = c.id LEFT JOIN bolumler dept ON a.department_id = dept.id LEFT JOIN asset_suppliers supp ON a.supplier_id = supp.id LEFT JOIN asset_manufacturers manu ON a.manufacturer_id = manu.id",
                    'fields' => "a.*, c.name as category_name, dept.bolum_adi as dept_name, supp.name as supplier_name, manu.name as manufacturer_name"
                ],
                'accessories' => [
                    'table' => 'asset_accessories',
                    'joins' => "LEFT JOIN asset_categories c ON a.category_id = c.id LEFT JOIN bolumler dept ON a.department_id = dept.id LEFT JOIN asset_suppliers supp ON a.supplier_id = supp.id LEFT JOIN asset_manufacturers manu ON a.manufacturer_id = manu.id",
                    'fields' => "a.*, c.name as category_name, dept.bolum_adi as dept_name, supp.name as supplier_name, manu.name as manufacturer_name"
                ],
                'consumables' => [
                    'table' => 'asset_consumables',
                    'joins' => "LEFT JOIN asset_categories c ON a.category_id = c.id LEFT JOIN bolumler dept ON a.department_id = dept.id LEFT JOIN asset_suppliers supp ON a.supplier_id = supp.id LEFT JOIN asset_manufacturers manu ON a.manufacturer_id = manu.id",
                    'fields' => "a.*, c.name as category_name, dept.bolum_adi as dept_name, supp.name as supplier_name, manu.name as manufacturer_name"
                ],
                'components' => [
                    'table' => 'asset_components',
                    'joins' => "LEFT JOIN asset_categories c ON a.category_id = c.id LEFT JOIN bolumler dept ON a.department_id = dept.id LEFT JOIN asset_suppliers supp ON a.supplier_id = supp.id LEFT JOIN asset_manufacturers manu ON a.manufacturer_id = manu.id",
                    'fields' => "a.*, c.name as category_name, dept.bolum_adi as dept_name, supp.name as supplier_name, manu.name as manufacturer_name"
                ],
            ];
            $m = $map[$view] ?? $map['assets'];
            $tbl = $m['table'];
            $j = $m['joins'];
            $fieldsStr = $m['fields'];
            
            $eaInfo = $pdo->query("SELECT $fieldsStr FROM $tbl a $j WHERE a.id = $editIdVal")->fetch(PDO::FETCH_ASSOC);
            if ($eaInfo && $view !== 'assets') {
                $nameField = ($view === 'licenses') ? 'software_name' : 'name';
                $eaInfo['name'] = $eaInfo[$nameField] ?? '';
            }
            if ($eaInfo):
                ?>
                (function() {
                    let attempts = 0;
                    function triggerEdit() {
                        const modal = $('#assetModal');
                        if (typeof editAsset === 'function' && modal.length && typeof modal.modal === 'function') {
                            <?php if (($_GET['clone'] ?? '0') == '1'): ?>
                                cloneAsset(<?= json_encode($eaInfo, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, '<?= $view ?>');
                            <?php else: ?>
                                editAsset(<?= json_encode($eaInfo, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, '<?= $view ?>');
                            <?php endif; ?>
                        } else if (attempts < 20) {
                            attempts++;
                            setTimeout(triggerEdit, 150);
                        }
                    }
                    setTimeout(triggerEdit, 200);
                })();
            <?php endif; ?>
        <?php elseif (($_GET['action'] ?? '') == 'assign' && isset($_GET['id'])): ?>
            (function() {
                let attempts = 0;
                function triggerAssign() {
                    if (typeof checkOutItem === 'function') {
                        checkOutItem(<?= intval($_GET['id']) ?>, '<?= $view ?>', 1);
                    } else if (attempts < 20) {
                        attempts++;
                        setTimeout(triggerAssign, 150);
                    }
                }
                setTimeout(triggerAssign, 200);
            })();
        <?php endif; ?>


        // --- CONSUAMBLE LOGS JS ---
        window.deleteSelectedConsLogs = function () {
            const ids = Array.from(document.querySelectorAll(".selectConsLog:checked")).map(cb => cb.value);
            if (ids.length === 0) return;

            Swal.fire({
                title: '<?= $isTr ? "Emin misiniz?" : "Are you sure?" ?>',
                text: '<?= $isTr ? "Seçilen hareketleri silmek istediğinize emin misiniz?" : "Are you sure you want to delete selected logs?" ?>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: '<?= $isTr ? "Evet, Sil" : "Yes, Delete" ?>',
                cancelButtonText: '<?= __("cancel") ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    const checked = document.querySelectorAll(".selectConsLog:checked");
                    const ids = Array.from(checked).map(cb => cb.value);
                    const types = Array.from(checked).map(cb => cb.dataset.type || 'checkout');

                    const form = document.createElement("form");
                    form.method = "POST";
                    form.style.display = "none";
                    form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">' +
                        '<input type="hidden" name="action" value="bulk_delete_consumable_logs">' +
                        '<input type="hidden" name="ids" value="' + ids.join(',') + '">' +
                        '<input type="hidden" name="types" value="' + types.join(',') + '">' +
                        '<input type="hidden" name="view" value="consumables">';
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        };

        window.clearAllConsLogs = function () {
            Swal.fire({
                title: '<?= $isTr ? "Tümünü Temizle?" : "Clear All?" ?>',
                text: '<?= $isTr ? "TÜM sarf malzeme hareket geçmişini temizlemek istediğinize emin misiniz?" : "Are you sure you want to clear ALL consumable history?" ?>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: '<?= $isTr ? "Evet, Tümünü Sil" : "Yes, Clear All" ?>',
                cancelButtonText: '<?= __("cancel") ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement("form");
                    form.method = "POST";
                    form.style.display = "none";
                    form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">' +
                        '<input type="hidden" name="action" value="bulk_delete_consumable_logs">' +
                        '<input type="hidden" name="ids" value="all">' +
                        '<input type="hidden" name="view" value="consumables">';
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        };
        $(document).on("change", "#selectAllConsLogs", function () {
            $(".selectConsLog").prop("checked", $(this).prop("checked"));
            toggleConsLogBtn();
        });
        $(document).on("change", ".selectConsLog", function () { toggleConsLogBtn(); });
        window.togglePredefinedGroup = togglePredefinedGroup;

        function toggleConsLogBtn() {
            const checked = $(".selectConsLog:checked").length;
            if (checked > 0) $("#btnDeleteConsLogs").removeClass("d-none");
            else $("#btnDeleteConsLogs").addClass("d-none");
        }

        $(document).on('change', '#status', function () {
            const id = $(this).val();
            const assetId = $('#asset_id').val();
            if (!assetId || !id) return;

            const s = lookupData.status_labels.find(x => x.id == id);
            if (s && s.type !== 'deployable') {
                const selectElement = $(this);
                const previousVal = selectElement.data('previous-val') || '';
                
                $.post('varliklar', { action: 'check_signature_type', asset_id: assetId, csrf_token: '<?= csrf_token() ?>' }, function(res) {
                    if (res.assigned) {
                        if (res.requires_digital) {
                            Swal.fire({
                                icon: 'error',
                                title: isTr ? 'Hata!' : 'Error!',
                                text: isTr 
                                    ? 'Bu cihaz dijital onaylı/imzalı zimmet altındadır. Durumunu ' + window.translateStatusName(s.name) + ' yapmadan önce lütfen "Geri Al" (İade) işlemini başlatarak personelin dijital imzasını tamamlayın.' 
                                    : 'This device is under digitally signed assignment. Before changing status to ' + window.translateStatusName(s.name) + ', please first initiate "Check In" to complete the digital signature.',
                                confirmButtonColor: '#3085d6'
                            }).then(() => {
                                selectElement.val(previousVal).trigger('change.select2'); // Revert Select2 value
                            });
                        } else {
                            Swal.fire({
                                title: isTr ? 'Zimmet Otomatik Geri Alınsın mı?' : 'Should Assignment Be Checked In Automatically?',
                                html: isTr 
                                    ? 'Bu cihaz şu anda kağıt/ıslak imza ile zimmetli. Durumu <strong>' + window.translateStatusName(s.name) + '</strong> olarak değiştirirseniz zimmet kaydı otomatik olarak geri alınacaktır.<br><br>Zimmet otomatik olarak geri alınsın mı?' 
                                    : 'This device is currently assigned with paper/wet signature. Changing status to <strong>' + window.translateStatusName(s.name) + '</strong> will automatically check in the assignment.<br><br>Should the assignment be checked in automatically?',
                                icon: 'question',
                                showCancelButton: true,
                                confirmButtonText: isTr ? 'Evet, Geri Al ve Güncelle' : 'Yes, Check In & Update',
                                cancelButtonText: isTr ? 'Hayır, İptal Et' : 'No, Cancel',
                                confirmButtonColor: '#f59e0b',
                                cancelButtonColor: '#3085d6'
                            }).then((r) => {
                                if (r.isConfirmed) {
                                    selectElement.data('previous-val', id);
                                } else {
                                    selectElement.val(previousVal).trigger('change.select2');
                                }
                            });
                        }
                    } else {
                        selectElement.data('previous-val', id);
                    }
                });
            } else {
                $(this).data('previous-val', id);
            }
        });

        window.viewSupplierSummary = async function (id) {
            $('#suppModalBody').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x" style="color:#6366f1;"></i></div>');
            $('#supplierSummaryModal').modal('show');

            try {
                const resp = await fetch('varliklar?supplier_summary_id=' + id);
                const data = await resp.json();

                if (data.error) {
                    Swal.fire('Hata', data.error, 'error');
                    return;
                }

                const s = data.supplier;
                $('#suppModalTitle').text(s.name);

                const suppImgHtml = s.image
                    ? '<div class="mb-4" style="border-radius:16px; overflow:hidden; background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,0.2); padding:8px;">'
                      + '<img src="public/uploads/suppliers/' + s.image + '" style="width:100%; max-height:140px; object-fit:contain; border-radius:10px; display:block;">'
                      + '</div>'
                    : '<div class="mb-4 d-flex flex-column align-items-center justify-content-center" style="height:110px; border-radius:16px; background:rgba(99,102,241,0.07); border:2px dashed rgba(129,140,248,0.35);">'
                      + '<i class="fas fa-truck fa-2x mb-2" style="color:#6366f1; opacity:0.6;"></i>'
                      + '<span class="text-xs supp-sub-val"><?= $isTr ? "Görsel Eklenmemiş" : "No Image" ?></span>'
                      + '</div>';

                let html = '<div class="row no-gutters" style="min-height:460px;">'
                    + '<div class="col-lg-4 p-4 supp-side-panel" style="border-radius:0 0 0 20px;">'
                    + suppImgHtml
                    + '<div class="mb-3" style="border-bottom:1px solid rgba(125,125,125,0.15); padding-bottom:10px;">'
                    + '<span class="text-xs font-weight-bold text-uppercase" style="color:#6366f1; letter-spacing:1px;"><i class="fas fa-address-card mr-1"></i><?= $isTr ? "İletişim Bilgileri" : "Contact Info" ?></span>'
                    + '</div>'
                    + '<ul class="list-unstyled mb-4">'
                    + '<li class="mb-3 d-flex align-items-center"><div class="mr-2" style="width:28px;height:28px;border-radius:8px;background:rgba(99,102,241,0.15);display:flex;align-items:center;justify-content:center;"><i class="fas fa-user-tie" style="color:#6366f1;font-size:11px;"></i></div><div><div class="text-xs supp-label"><?= $isTr ? "İlgili Kişi" : "Contact" ?></div><div class="font-weight-bold supp-val">' + (s.contact_person || '—') + '</div></div></li>'
                    + '<li class="mb-3 d-flex align-items-center"><div class="mr-2" style="width:28px;height:28px;border-radius:8px;background:rgba(34,197,94,0.12);display:flex;align-items:center;justify-content:center;"><i class="fas fa-phone" style="color:#16a34a;font-size:11px;"></i></div><div><div class="text-xs supp-label"><?= $isTr ? "Telefon" : "Phone" ?></div><div class="supp-val">' + (s.phone || '—') + '</div></div></li>'
                    + '<li class="mb-3 d-flex align-items-center"><div class="mr-2" style="width:28px;height:28px;border-radius:8px;background:rgba(14,165,233,0.12);display:flex;align-items:center;justify-content:center;"><i class="fas fa-envelope" style="color:#0284c7;font-size:11px;"></i></div><div><div class="text-xs supp-label">E-posta</div><div class="supp-val">' + (s.email || '—') + '</div></div></li>'
                    + '<li class="mb-3 d-flex align-items-center"><div class="mr-2" style="width:28px;height:28px;border-radius:8px;background:rgba(234,179,8,0.12);display:flex;align-items:center;justify-content:center;"><i class="fas fa-globe" style="color:#ca8a04;font-size:11px;"></i></div><div><div class="text-xs supp-label"><?= $isTr ? "Web Sitesi" : "Website" ?></div><div class="supp-val">' + (s.website ? '<a href="' + s.website + '" target="_blank" style="color:#6366f1;">' + s.website + '</a>' : '—') + '</div></div></li>'
                    + '</ul>'
                    + '<div class="mb-2" style="border-bottom:1px solid rgba(125,125,125,0.15); padding-bottom:10px;">'
                    + '<span class="text-xs font-weight-bold text-uppercase" style="color:#ef4444; letter-spacing:1px;"><i class="fas fa-map-marker-alt mr-1"></i><?= $isTr ? "Konum" : "Location" ?></span>'
                    + '</div>'
                    + '<p class="small mb-3 supp-val">'
                    + (s.address || '—') + '<br>'
                    + '<span class="supp-sub-val">' + (s.zip ? s.zip + ' ' : '') + (s.city || '') + ' ' + (s.country || '') + '</span>'
                    + '</p>'
                    + (s.notes ? '<div class="p-3 rounded small supp-notes-box"><i class="fas fa-sticky-note mr-2"></i>' + s.notes + '</div>' : '')
                    + '</div>'
                    + '<div class="col-lg-8 p-4" style="border-radius:0 0 20px 0;">'
                    + '<ul class="nav nav-pills mb-4" id="supp-tabs" style="gap:8px;">'
                    + '<li class="nav-item"><a class="nav-link active" href="#supp-assets" data-toggle="tab"><?= $isTr ? "Demirbaşlar" : "Assets" ?> <span class="badge">' + data.assets.length + '</span></a></li>'
                    + '<li class="nav-item"><a class="nav-link" href="#supp-licenses" data-toggle="tab"><?= $isTr ? "Lisanslar" : "Licenses" ?> <span class="badge">' + data.licenses.length + '</span></a></li>'
                    + '<li class="nav-item"><a class="nav-link" href="#supp-consumables" data-toggle="tab"><?= $isTr ? "Sarf/Aksesuar" : "Consumables/Acc" ?> <span class="badge">' + (data.consumables.length + data.accessories.length + data.components.length) + '</span></a></li>'
                    + '</ul>'
                    + '<div class="tab-content" style="max-height:420px; overflow-y:auto;">'
                    + '<div class="tab-pane active fade show" id="supp-assets">'
                    + '<table class="table table-sm small">'
                    + '<thead><tr><th><?= $isTr ? "Demirbaş" : "Asset" ?></th><th>TAG</th><th><?= $isTr ? "Durum" : "Status" ?></th></tr></thead>'
                    + '<tbody>';

                if (data.assets.length) {
                    data.assets.forEach(function (a) {
                        html += '<tr>'
                            + '<td><div class="font-weight-bold supp-val">' + a.name + '</div><div class="text-xs supp-sub-val">' + (a.model_name || '') + '</div></td>'
                            + '<td><code style="background:rgba(99,102,241,0.12);color:#6366f1;padding:2px 6px;border-radius:6px;font-weight:600;">' + (a.asset_tag || '—') + '</code></td>'
                            + '<td><span class="badge" style="background:rgba(99,102,241,0.15);color:#6366f1;border-radius:8px;padding:4px 10px;font-weight:600;">' + (a.status_name || '—') + '</span></td>'
                            + '</tr>';
                    });
                } else {
                    html += '<tr><td colspan="3" class="text-center py-5 supp-sub-val"><i class="fas fa-box-open fa-2x mb-2 d-block" style="opacity:0.3;"></i><?= $isTr ? "Demirbaş bulunamadı" : "No assets found" ?></td></tr>';
                }

                html += '</tbody></table></div>'
                    + '<div class="tab-pane fade" id="supp-licenses">'
                    + '<table class="table table-sm small">'
                    + '<thead><tr><th><?= $isTr ? "Yazılım" : "Software" ?></th><th><?= $isTr ? "Anahtar" : "Key" ?></th><th class="text-center"><?= $isTr ? "Miktar" : "Qty" ?></th></tr></thead>'
                    + '<tbody>';

                if (data.licenses.length) {
                    data.licenses.forEach(function (l) {
                        html += '<tr>'
                            + '<td class="font-weight-bold" style="color:#6366f1;">' + l.name + '</td>'
                            + '<td><code style="background:rgba(148,163,184,0.15);color:#64748b;padding:2px 6px;border-radius:6px;">' + (l.license_key || '—') + '</code></td>'
                            + '<td class="text-center"><span class="badge" style="background:rgba(14,165,233,0.15);color:#0284c7;border-radius:8px;padding:4px 10px;font-weight:600;">' + l.quantity + '</span></td>'
                            + '</tr>';
                    });
                } else {
                    html += '<tr><td colspan="3" class="text-center py-5 supp-sub-val"><i class="fas fa-key fa-2x mb-2 d-block" style="opacity:0.3;"></i><?= $isTr ? "Lisans bulunamadı" : "No licenses found" ?></td></tr>';
                }

                html += '</tbody></table></div>'
                    + '<div class="tab-pane fade" id="supp-consumables">'
                    + '<table class="table table-sm small">'
                    + '<thead><tr><th><?= $isTr ? "Ürün" : "Item" ?></th><th><?= $isTr ? "Tür" : "Type" ?></th><th class="text-center"><?= $isTr ? "Stok" : "Stock" ?></th></tr></thead>'
                    + '<tbody>';

                var allCons = data.consumables.concat(data.accessories).concat(data.components);
                if (allCons.length) {
                    data.consumables.forEach(function (c) {
                        html += '<tr><td class="font-weight-bold supp-val">' + c.name + '</td><td><span class="badge" style="background:rgba(99,102,241,0.15);color:#6366f1;border-radius:8px;"><?= $isTr ? "Sarf" : "Consumable" ?></span></td><td class="text-center"><strong style="color:#6366f1;">' + c.quantity + '</strong></td></tr>';
                    });
                    data.accessories.forEach(function (acc) {
                        html += '<tr><td class="font-weight-bold supp-val">' + acc.name + '</td><td><span class="badge" style="background:rgba(14,165,233,0.15);color:#0284c7;border-radius:8px;"><?= $isTr ? "Aksesuar" : "Accessory" ?></span></td><td class="text-center"><strong style="color:#0284c7;">' + acc.quantity + '</strong></td></tr>';
                    });
                    data.components.forEach(function (comp) {
                        html += '<tr><td class="font-weight-bold supp-val">' + comp.name + '</td><td><span class="badge" style="background:rgba(34,197,94,0.15);color:#16a34a;border-radius:8px;"><?= $isTr ? "Bileşen" : "Component" ?></span></td><td class="text-center"><strong style="color:#16a34a;">' + comp.quantity + '</strong></td></tr>';
                    });
                } else {
                    html += '<tr><td colspan="3" class="text-center py-5 supp-sub-val"><i class="fas fa-vial fa-2x mb-2 d-block" style="opacity:0.3;"></i><?= $isTr ? "Ürün bulunamadı" : "No items found" ?></td></tr>';
                }
                html += '</tbody></table></div></div></div></div></div>';

                $('#suppModalBody').html(html);
            } catch (e) {
                console.error(e);
                $('#suppModalBody').html('<div class="alert alert-danger mx-3 mt-3">Veriler yüklenirken bir hata oluştu.</div>');
            }
        };
    });

    let signaturePad, signaturePadAdmin;
    let currentSigStatus = 'pending_user';
    let currentActionType = 'checkout';
    
    window.approveSignature = function(assetId, itemType = 'asset', status = 'pending_user', actionType = 'checkout', signatureId = 0) {
        $('#sig_asset_id').val(assetId);
        $('#sig_signature_id').val(signatureId);
        $('#sig_item_type').val(itemType);
        currentSigStatus = status;
        currentActionType = actionType;
        
        $('#sig_notes').val('');
        $('#sig_confirm').prop('checked', false);
        $('#btn_confirm_sig').prop('disabled', true);
        
        const isTr = <?= isset($isTr) && $isTr ? 'true' : 'false' ?>;
        const isAdmin = <?= in_array($current_user_role, [1, 3]) ? 'true' : 'false' ?>;
        
        // Update Modal Text based on actionType
        const trCheckoutUserText = 'Yukarıdaki zimmet sözleşmesi metnini okudum, eksiksiz ve çalışır vaziyette teslim aldığımı beyan ve taahhüt ederim.';
        const enCheckoutUserText = 'I declare that I have read the assignment agreement text above and received the asset in fully working condition.';
        
        const trCheckoutAdminText = 'Yukarıdaki zimmet sözleşmesi metnine istinaden, donanımı ilgili personele eksiksiz ve çalışır vaziyette teslim ettiğimi beyan ve taahhüt ederim.';
        const enCheckoutAdminText = 'Based on the assignment agreement above, I declare and commit that I have delivered the asset to the relevant personnel in fully working condition.';
        
        const trReturnUserText = 'Yukarıdaki iade sözleşmesi metnini okudum, donanımı eksiksiz ve teslim ettiğim andaki durumuyla şirkete/kuruma teslim ettiğimi beyan ve taahhüt ederim.';
        const enReturnUserText = 'I declare I have read the return agreement and returned the asset in full to the company/institution.';
        
        const trReturnAdminText = 'Yukarıdaki iade sözleşmesi metnini okudum, donanımı personelden eksiksiz olarak teslim aldığımı beyan ve taahhüt ederim.';
        const enReturnAdminText = 'I declare I have read the return agreement and received the asset in full from the personnel.';
        
        const trReturnAgreement = 'Aşağıda donanımsal detayları belirtilen envanteri, eksiksiz ve teslim ettiğim andaki durumuyla şirkete/kuruma geri teslim ettiğimi beyan ve taahhüt ederim.';
        const enReturnAgreement = 'I hereby declare and commit that I have returned the asset specified below with its hardware details, completely and in the condition I received it.';
        
        if (!window.originalAgreementHTML) window.originalAgreementHTML = $('#sig_agreement_container').html();
        
        if (actionType === 'checkin') {
            $('#signatureModal .modal-title').text(isTr ? 'İade Onay & İmza' : 'Return Signature Approval');
            $('#sig_agreement_label').html('<i class="fas fa-file-contract mr-1 text-primary"></i> ' + (isTr ? 'İade Sözleşmesi Metni' : 'Return Agreement Text'));
            $('#sig_agreement_container').html(window.originalAgreementHTML);
            
            if (status === 'pending_user') {
                $('#sig_confirm').next('label').text(isTr ? trReturnUserText : enReturnUserText);
            } else {
                $('#sig_confirm').next('label').text(isTr ? trReturnAdminText : enReturnAdminText);
            }
        } else {
            $('#signatureModal .modal-title').text(isTr ? 'Zimmet Onay & İmza' : 'Asset Signature Approval');
            $('#sig_agreement_label').html('<i class="fas fa-file-contract mr-1 text-primary"></i> ' + (isTr ? 'Zimmet Sözleşmesi Metni (Tutanak Legal Metni)' : 'Assignment Agreement Text'));
            $('#sig_agreement_container').html(window.originalAgreementHTML);
            
            if (status === 'pending_user') {
                $('#sig_confirm').next('label').text(isTr ? trCheckoutUserText : enCheckoutUserText);
            } else {
                $('#sig_confirm').next('label').text(isTr ? trCheckoutAdminText : enCheckoutAdminText);
            }
        }
        
        if (status === 'pending_user') {
            $('#personnelCanvasContainer').show();
            $('#adminCanvasContainer').hide();
            if (isAdmin) {
                // Admin teslim ederken: hem personel hem kendi imzasını atabilir veya bypass yapabilir
                $('#bypassContainer').show();
                $('#sig_bypass').prop('checked', false);
            } else {
                $('#bypassContainer').hide();
            }
        } else if (status === 'pending_admin') {
            // Personel imzaladı, şimdi admin imzalamalı
            $('#personnelCanvasContainer').hide(); // Personel imzası zaten DB'de
            if (isAdmin) {
                // Admin kendi imzasını atıyor
                $('#adminCanvasContainer').show();
                $('#bypassContainer').hide();
                // Admin için modal subtitle güncelle
                if (actionType === 'checkin') {
                    $('#signatureModal .modal-title').text(isTr ? 'İade Onayı - Admin İmzası' : 'Return Approval - Admin Sign');
                } else {
                    $('#signatureModal .modal-title').text(isTr ? 'Zimmet Onayı - Admin İmzası' : 'Assignment Approval - Admin Sign');
                }
                // Checkbox etiketini admin metnine güncelle
                if (actionType === 'checkin') {
                    $('#sig_confirm').next('label').text(isTr ? trReturnAdminText : enReturnAdminText);
                } else {
                    $('#sig_confirm').next('label').text(isTr ? trCheckoutAdminText : enCheckoutAdminText);
                }
            } else {
                // Personel: sadece bilgi için açılmış (buton görünmemeli zaten)
                $('#adminCanvasContainer').hide();
                $('#bypassContainer').hide();
            }
        } else {
            $('#personnelCanvasContainer').show();
            $('#adminCanvasContainer').show();
        }
        
        $('#signatureModal').modal('show');
        
        // Initialize Signature Pad after modal is shown
        setTimeout(() => {
            const canvas = document.getElementById('signature-pad');
            const canvasAdmin = document.getElementById('signature-pad-admin');
            const ratio =  Math.max(window.devicePixelRatio || 1, 1);
            
            if (canvas && $('#personnelCanvasContainer').is(':visible')) {
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = canvas.offsetHeight * ratio;
                canvas.getContext("2d").scale(ratio, ratio);
                if (signaturePad) signaturePad.clear();
                else signaturePad = new SignaturePad(canvas, { minWidth: 2.0, maxWidth: 4.5 });
            }
            if (canvasAdmin && $('#adminCanvasContainer').is(':visible')) {
                canvasAdmin.width = canvasAdmin.offsetWidth * ratio;
                canvasAdmin.height = canvasAdmin.offsetHeight * ratio;
                canvasAdmin.getContext("2d").scale(ratio, ratio);
                if (signaturePadAdmin) signaturePadAdmin.clear();
                else signaturePadAdmin = new SignaturePad(canvasAdmin, { minWidth: 2.0, maxWidth: 4.5 });
            }
        }, 300);
    };

    $(document).on('change', '#sig_bypass', function() {
        if ($(this).is(':checked')) {
            $('#personnelCanvasContainer').hide();
            $('#adminCanvasContainer').show();
            
            // Initialize Admin pad if it was hidden
            setTimeout(() => {
                const canvasAdmin = document.getElementById('signature-pad-admin');
                const ratio =  Math.max(window.devicePixelRatio || 1, 1);
                if (canvasAdmin && $('#adminCanvasContainer').is(':visible')) {
                    canvasAdmin.width = canvasAdmin.offsetWidth * ratio;
                    canvasAdmin.height = canvasAdmin.offsetHeight * ratio;
                    canvasAdmin.getContext("2d").scale(ratio, ratio);
                    if (signaturePadAdmin) signaturePadAdmin.clear();
                    else signaturePadAdmin = new SignaturePad(canvasAdmin, { minWidth: 2.0, maxWidth: 4.5 });
                }
            }, 50);
            
        } else {
            $('#personnelCanvasContainer').show();
            $('#adminCanvasContainer').hide();
        }
    });

    $(document).on('click', '#clear-signature', function() {
        if (signaturePad) signaturePad.clear();
    });
    
    $(document).on('click', '#clear-signature-admin', function() {
        if (signaturePadAdmin) signaturePadAdmin.clear();
    });

    $(document).on('change', '#sig_confirm', function() {
        $('#btn_confirm_sig').prop('disabled', !$(this).prop('checked'));
    });

    $(document).on('click', '#btn_confirm_sig', function() {
        const assetId = $('#sig_asset_id').val();
        const signatureId = $('#sig_signature_id').val();
        const itemType = $('#sig_item_type').val() || 'asset';
        const notes = $('#sig_notes').val();
        const signature = signaturePad && !signaturePad.isEmpty() && $('#personnelCanvasContainer').is(':visible') ? signaturePad.toDataURL() : '';
        const adminSignature = signaturePadAdmin && !signaturePadAdmin.isEmpty() && $('#adminCanvasContainer').is(':visible') ? signaturePadAdmin.toDataURL() : '';
        const btn = $(this);
        const bypass = $('#sig_bypass').is(':checked') ? 1 : 0;
        
        if (currentSigStatus === 'pending_user') {
            const isAdmin = <?= in_array($current_user_role, [1, 3]) ? 'true' : 'false' ?>;
            if (isAdmin) {
                if (bypass) {
                    if (!adminSignature) { Swal.fire('Uyarı', typeof isTr !== 'undefined' && isTr ? 'Yönetici imzası zorunludur.' : 'Admin signature required.', 'warning'); return; }
                } else {
                    if (!signature) { Swal.fire('Uyarı', typeof isTr !== 'undefined' && isTr ? 'Lütfen personelin imzasını atın.' : 'Please provide personnel signature.', 'warning'); return; }
                }
            } else {
                if (!signature) { Swal.fire('Uyarı', typeof isTr !== 'undefined' && isTr ? 'Lütfen imzanızı atın.' : 'Please provide your signature.', 'warning'); return; }
            }
        } else if (currentSigStatus === 'pending_admin') {
            // Admin kendi imzasını atmak zorunda
            if (!adminSignature) { 
                Swal.fire('Uyarı', typeof isTr !== 'undefined' && isTr ? 'Lütfen yönetici imzanızı atın.' : 'Please provide your admin signature.', 'warning'); 
                return; 
            }
        }

        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> ' + (typeof isTr !== 'undefined' && isTr ? "Kaydediliyor..." : "Saving..."));

        $.ajax({
            url: 'varliklar',
            type: 'POST',
            data: {
                action: 'approve_signature',
                ajax_action: 1,
                asset_id: assetId,
                signature_id: signatureId,
                item_type: itemType,
                notes: notes,
                signature: signature,
                admin_signature: adminSignature,
                bypass: bypass,
                admin_name: $('#sig_admin_name').val(),
                csrf_token: '<?= csrf_token() ?>'
            },
            success: function(res) {
                if(res.success) {
                    $('#signatureModal').modal('hide');
                    Swal.fire({
                        title: typeof isTr !== 'undefined' && isTr ? 'Başarılı' : 'Success',
                        text: typeof isTr !== 'undefined' && isTr ? "İmza başarıyla kaydedildi." : "Signature saved successfully.",
                        icon: 'success'
                    }).then(function() {
                        location.reload();
                    });
                } else {
                    Swal.fire('Hata', res.message || res.error || 'Bilinmeyen bir hata oluştu.', 'error');
                    btn.prop('disabled', false).html(typeof isTr !== 'undefined' && isTr ? "Onayla ve Kaydet" : "Confirm & Save");
                }
            },
            error: function() {
                Swal.fire('Hata', 'Sunucu bağlantı hatası.', 'error');
                btn.prop('disabled', false).html(typeof isTr !== 'undefined' && isTr ? "Onayla ve Kaydet" : "Confirm & Save");
            }
        });
    });

    // Auto-restore form data after veritabanı/SQL error redirection
    <?php if (isset($_SESSION['invalid_post_data'])): ?>
    (function() {
        const postData = <?= json_encode($_SESSION['invalid_post_data']) ?>;
        const postLabels = <?= json_encode($_SESSION['invalid_post_labels'] ?? []) ?>;
        const view = postData.view || 'assets';

        // Render the dynamic form first
        renderDynamicForm(view);

        // Set modal title depending on if it's edit or add
        const isEdit = parseInt(postData.asset_id || 0) > 0;
        if (isEdit) {
            $('#modalTitle').html('<i class="fas fa-edit mr-2"></i>' + (isTr ? 'Düzenle (Hata Sonrası)' : 'Edit (After Error)'));
            $('#asset_id').val(postData.asset_id);
        } else {
            $('#modalTitle').html('<i class="fas fa-plus mr-2"></i>' + (isTr ? 'Yeni Ekle (Hata Sonrası)' : 'Add New (After Error)'));
            $('#asset_id').val('');
        }

        // Wait a little bit for DOM rendering
        setTimeout(function() {
            // Populate standard elements
            for (const key in postData) {
                const $el = $('#' + key).length ? $('#' + key) : $('[name="' + key + '"]');
                if ($el.length && $el.attr('type') !== 'file') {
                    if ($el.is('select')) {
                        if ($el.hasClass('select2-ajax')) {
                            if (postLabels[key]) {
                                setSelect2AjaxValue($el, postData[key], postLabels[key]);
                            }
                        } else {
                            $el.val(postData[key]).trigger('change');
                        }
                    } else if ($el.attr('type') === 'checkbox') {
                        $el.prop('checked', postData[key] == '1');
                    } else {
                        $el.val(postData[key]);
                    }
                }
            }

            // Populate custom fields
            if (postData.custom_fields) {
                for (const fldId in postData.custom_fields) {
                    const val = postData.custom_fields[fldId];
                    const $f = $('[name="custom_fields[' + fldId + ']"]');
                    if ($f.length) $f.val(val);
                }
            }

            // Show the modal
            $('#assetModal').modal('show');
        }, 400);
    })();
    <?php unset($_SESSION['invalid_post_data']); unset($_SESSION['invalid_post_labels']); endif; ?>
</script>

<!-- Page Specific Tip -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (localStorage.getItem('eaprimus_tip_varliklar') !== 'true') {
        setTimeout(function() {
            if (typeof Swal !== 'undefined') {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 6000,
                    timerProgressBar: true,
                    background: '#0f1b3d',
                    color: '#fff',
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer)
                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                    }
                });
                Toast.fire({
                    icon: 'info',
                    iconColor: '#60a5fa',
                    title: typeof isTr !== 'undefined' && isTr ? 'İpucu' : 'Tip',
                    text: typeof isTr !== 'undefined' && isTr ? 'Sağ üstteki "Yeni Ekle" butonu ile sisteme yeni demirbaş/cihaz kaydedebilirsiniz.' : 'You can add new assets using the "Add New" button on the top right.'
                });
                localStorage.setItem('eaprimus_tip_varliklar', 'true');
            }
        }, 1500);
    }
});
</script>

<!-- Interactive Tour Script for Predefined Pages -->
<?php if (isset($_GET['tour'])): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intro.js/7.2.0/introjs.min.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intro.js/7.2.0/themes/introjs-modern.min.css" />
<style>
    .introjs-tooltip { border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); font-family: 'Plus Jakarta Sans', sans-serif; animation: introFadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1); border: 1px solid rgba(0,0,0,0.05); }
    .introjs-tooltip .fas { font-family: 'Font Awesome 5 Free' !important; font-weight: 900 !important; font-style: normal !important; }
    .introjs-tooltip .far { font-family: 'Font Awesome 5 Free' !important; font-weight: 400 !important; font-style: normal !important; }
    @keyframes introFadeIn { from { opacity: 0; transform: translateY(10px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .introjs-tooltiptext { padding: 20px; font-size: 14.5px; line-height: 1.6; color: #334155; }
    .introjs-button { border-radius: 10px; font-weight: 600; padding: 10px 18px; text-shadow: none; transition: all 0.2s ease; cursor: pointer; }
    .introjs-button:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    .introjs-nextbutton { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important; color: #fff !important; border: none !important; }
    .introjs-nextbutton:hover { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important; }
    .introjs-prevbutton { background: #f8fafc !important; color: #64748b !important; border: 1px solid #e2e8f0 !important; }
    .introjs-prevbutton:hover { background: #f1f5f9 !important; color: #334155 !important; }
    .introjs-helperLayer { background-color: rgba(255,255,255,.95); box-shadow: 0 0 0 10000px rgba(15,23,42,0.85); border-radius: 12px; }
    .introjs-bullets ul li a { width: 8px; height: 8px; border-radius: 50%; background: #cbd5e1; transition: all 0.3s ease; }
    .introjs-bullets ul li a.active { background: #3b82f6; width: 16px; border-radius: 8px; }
    .introjs-tooltip-header { padding-right: 15px; }
    .introjs-skipbutton { color: #94a3b8; font-weight: 500; }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/intro.js/7.2.0/intro.min.js"></script>
<?php endif; ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const urlParams = new URLSearchParams(window.location.search);
    const tourParam = urlParams.get('tour');
    const typeParam = urlParams.get('type');
    const viewParam = urlParams.get('view');

    if (viewParam === 'predefined' && typeof introJs !== 'undefined') {
        if (tourParam === 'category' && typeParam === 'categories') {
            setTimeout(function() {
                var intro = introJs();
                intro.setOptions({
                    nextLabel: typeof isTr !== 'undefined' && isTr ? 'İleri' : 'Next',
                    prevLabel: typeof isTr !== 'undefined' && isTr ? 'Geri' : 'Back',
                    doneLabel: typeof isTr !== 'undefined' && isTr ? 'Kapat' : 'Close',
                    showStepNumbers: false,
                    showBullets: false,
                    steps: [
                        {
                            element: document.querySelector('#btnNewRecord'),
                            intro: typeof isTr !== 'undefined' && isTr 
                                ? "<div class='text-center'><i class='fas fa-plus-circle fa-2x text-primary mb-2'></i><br><h6 class='font-weight-bold text-dark'>İlk Kategorinizi Ekleyin</h6><p class='mb-0'>Lütfen <b>Yeni Ekle</b> butonuna tıklayarak örnek bir kategori (Örn: Bilgisayar) oluşturun.</p></div>"
                                : "<div class='text-center'><i class='fas fa-plus-circle fa-2x text-primary mb-2'></i><br><h6 class='font-weight-bold text-dark'>Add Your First Category</h6><p class='mb-0'>Please click the <b>Add New</b> button to create a sample category (e.g. Computers).</p></div>",
                            position: 'left'
                        }
                    ]
                });
                intro.start();
                intro.onexit(function() {
                    $('#btnNewRecord').click();
                });
                intro.oncomplete(function() {
                    $('#btnNewRecord').click();
                });
            }, 800);
        } else if (tourParam === 'models' && typeParam === 'models') {
            setTimeout(function() {
                var intro = introJs();
                intro.setOptions({
                    nextLabel: typeof isTr !== 'undefined' && isTr ? 'İleri' : 'Next',
                    prevLabel: typeof isTr !== 'undefined' && isTr ? 'Geri' : 'Back',
                    doneLabel: typeof isTr !== 'undefined' && isTr ? 'Bitir' : 'Finish',
                    showStepNumbers: false,
                    showBullets: false,
                    steps: [
                        {
                            element: document.querySelector('#btnNewRecord'),
                            intro: typeof isTr !== 'undefined' && isTr 
                                ? "<div class='text-center'><i class='fas fa-box-open fa-2x text-success mb-2'></i><br><h6 class='font-weight-bold text-dark'>Harika! Şimdi Sırada Model Var</h6><p class='mb-0'>Kategoriyi ekledik. Şimdi bu kategoriye ait bir örnek model ekleyin.</p></div>"
                                : "<div class='text-center'><i class='fas fa-box-open fa-2x text-success mb-2'></i><br><h6 class='font-weight-bold text-dark'>Great! Now for the Model</h6><p class='mb-0'>Category added. Now add a sample model belonging to that category.</p></div>",
                            position: 'left'
                        }
                    ]
                });
                intro.start();
                intro.onexit(function() {
                    localStorage.setItem('eaprimus_tour_completed', 'true');
                    localStorage.removeItem('eaprimus_tour_step');
                    $('#btnNewRecord').click();
                });
                intro.oncomplete(function() {
                    localStorage.setItem('eaprimus_tour_completed', 'true');
                    localStorage.removeItem('eaprimus_tour_step');
                    $('#btnNewRecord').click();
                });
            }, 800);
        }
    }
});

$(document).ready(function() {
    let isAutoAdd = <?= isset($_GET['auto_add']) && $_GET['auto_add'] == '1' ? 'true' : 'false' ?>;
    let autoIp = <?= json_encode($_GET['ip'] ?? '') ?>;
    let autoMac = <?= json_encode($_GET['mac'] ?? '') ?>;
    let autoHostname = <?= json_encode($_GET['hostname'] ?? '') ?>;
    let autoDiscId = <?= json_encode($_GET['disc_id'] ?? '') ?>;

    // Her durumda (auto_add olsun veya olmasın) DOM'u dinle ve Ağ Bilgileri / Teknik Bilgiler kartını modal'a enjekte et
    const observer = new MutationObserver(function(mutations) {
        let viewParam = new URLSearchParams(window.location.search).get('view') || 'assets';
        if (viewParam !== 'assets') return;

        let modalBody = $('.modal.show .modal-body');
        if (modalBody.length === 0) {
            modalBody = $('form').has('input[name="action"][value="save"]').find('.modal-body');
        }
        if (modalBody.length === 0) return;

        let targetForm = modalBody.closest('form');
        if (targetForm.length === 0) return;

        // Skip injection completely for checkout (quick_assign) and return (checkin) forms/modals
        let actionVal = targetForm.find('input[name="action"]').val();
        if (actionVal === 'quick_assign' || actionVal === 'checkin' || 
            targetForm.closest('#assignmentModal').length > 0 || 
            targetForm.closest('#returnModal').length > 0) {
            return;
        }

        let currentItem = targetForm.data('current-item') || null;

        // Eğer nativeNetworkFieldsCard yoksa enjekte et
        if (!targetForm.find('#nativeNetworkFieldsCard').length && !targetForm.find('input[name="ip_address"].native-ip').length) {
            // Find the right column containing the asset image file input or preview container
            let targetCol = targetForm.find('#asset_image').closest('.col-md-4');
            if (targetCol.length === 0) {
                targetCol = targetForm.find('#asset_image_preview_container').closest('.col-md-4');
            }
            if (targetCol.length === 0) {
                targetCol = targetForm.find('.col-md-4').last();
            }
            if (targetCol.length === 0) return;

            let networkFieldsHtml = `
            <div class="card bg-white border-0 shadow-sm mb-4" id="nativeNetworkFieldsCard" style="border-radius:20px;">
                <div class="card-body p-3">
                    <h6 class="text-primary font-weight-bold mb-3"><i class="fas fa-network-wired mr-2"></i><?= __("Ağ Bilgileri") ?> ${isAutoAdd ? '(Tarayıcıdan)' : ''}</h6>
                    <div class="row mb-2">
                        <div class="col-md-6 form-group mb-2" id="nativeIpWrapper">
                            <label class="small font-weight-bold text-muted"><?= __("IP Adresi") ?></label>
                            <input type="text" name="ip_address" id="ip_address" class="form-control native-ip bg-light border-0" value="${isAutoAdd ? autoIp : ''}" style="border-radius:10px;">
                        </div>
                        <div class="col-md-6 form-group mb-2" id="nativeMacWrapper">
                            <label class="small font-weight-bold text-muted"><?= __("MAC Adresi") ?></label>
                            <input type="text" name="mac_address" id="mac_address" class="form-control native-mac bg-light border-0" value="${isAutoAdd ? autoMac : ''}" style="border-radius:10px;">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 form-group mb-0">
                            <label class="small font-weight-bold text-muted"><?= __("Yedek/Kablosuz IP (İkincil IP)") ?></label>
                            <input type="text" name="ip_secondary" id="ip_secondary" class="form-control bg-light border-0" value="" style="border-radius:10px;">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card bg-white border-0 shadow-sm mb-4" id="nativeSpecsFieldsCard" style="border-radius:20px;">
                <div class="card-body p-3">
                    <h6 class="text-primary font-weight-bold mb-3"><i class="fas fa-microchip mr-2"></i><?= __("Teknik Bilgiler") ?></h6>
                    <div class="row mb-2">
                        <div class="col-md-12 form-group mb-2">
                            <label class="small font-weight-bold text-muted"><?= __("İşletim Sistemi (OS)") ?></label>
                            <input type="text" name="os" id="os" class="form-control bg-light border-0" value="" style="border-radius:10px;">
                        </div>
                        <div class="col-md-6 form-group mb-2">
                            <label class="small font-weight-bold text-muted"><?= __("İşlemci (CPU)") ?></label>
                            <input type="text" name="cpu" id="cpu" class="form-control bg-light border-0" value="" style="border-radius:10px;">
                        </div>
                        <div class="col-md-6 form-group mb-2">
                            <label class="small font-weight-bold text-muted"><?= $isTr ? 'Anakart' : 'Motherboard' ?></label>
                            <input type="text" name="mainboard" id="mainboard" class="form-control bg-light border-0" value="" style="border-radius:10px;">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-6 form-group mb-2">
                            <label class="small font-weight-bold text-muted"><?= __("Bellek (RAM)") ?></label>
                            <input type="text" name="ram" id="ram" class="form-control bg-light border-0" value="" style="border-radius:10px;">
                        </div>
                        <div class="col-md-6 form-group mb-2">
                            <label class="small font-weight-bold text-muted"><?= __("Disk") ?></label>
                            <input type="text" name="disk" id="disk" class="form-control bg-light border-0" value="" style="border-radius:10px;">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 form-group mb-2">
                            <label class="small font-weight-bold text-muted"><?= __("Ekran Kartı (GPU)") ?></label>
                            <input type="text" name="gpu" id="gpu" class="form-control bg-light border-0" value="" style="border-radius:10px;">
                        </div>
                        <div class="col-md-12 form-group mb-0">
                            <label class="small font-weight-bold text-muted"><?= __("Monitör") ?></label>
                            <input type="text" name="monitor" id="monitor" class="form-control bg-light border-0" value="" style="border-radius:10px;">
                        </div>
                    </div>
                </div>
            </div>
            `;
            targetCol.append(networkFieldsHtml);

            // Eğer düzenleme modundaysak, değerleri hemen doldur ve hover durumunda gözükmesi için title ekle
            if (currentItem) {
                const fields = {
                    '#ip_address': currentItem.ip_address || '',
                    '#mac_address': currentItem.mac_address || '',
                    '#ip_secondary': currentItem.ip_secondary || '',
                    '#cpu': currentItem.cpu || '',
                    '#ram': currentItem.ram || '',
                    '#gpu': currentItem.gpu || '',
                    '#disk': currentItem.disk || currentItem.hdd_size || '',
                    '#monitor': currentItem.monitor || '',
                    '#os': currentItem.os || '',
                    '#mainboard': currentItem.mainboard || ''
                };
                for (let selector in fields) {
                    let val = fields[selector];
                    let el = targetForm.find(selector);
                    el.val(val).attr('title', val);
                }
            }

            // Input değiştikçe title özniteliğini otomatik güncelle
            targetForm.find('#nativeNetworkFieldsCard input, #nativeSpecsFieldsCard input').on('input change', function() {
                $(this).attr('title', $(this).val());
            });
        }

        // Özel alanları (Custom Fields) kontrol et, eğer IP veya MAC özel alanı eklenmişse görsel karmaşayı önle
        let customIpFound = false;
        let customMacFound = false;

        $('.form-group label').each(function() {
            let text = $(this).text().toLowerCase();
            let isNativeLabel = $(this).closest('#nativeNetworkFieldsCard').length > 0;
            
            if (!isNativeLabel) {
                if (text.includes('ip adres') || text.includes('ip address') || text === 'ip') {
                    customIpFound = true;
                    let input = $(this).parent().find('input');
                    // Eğer auto_add ile geldiyse ve özel alan henüz boşsa otomatik doldur
                    if (isAutoAdd && autoIp && input.length && !input.val()) input.val(autoIp);
                    // Değer değiştiğinde native alanı da güncelle ki çakışma olmasın
                    input.off('change.sync').on('change.sync', function() {
                        $('#hiddenNativeIp').val($(this).val());
                        $('#ip_address').val($(this).val());
                    });
                }
                if (text.includes('mac adres') || text.includes('mac address') || text === 'mac') {
                    customMacFound = true;
                    let input = $(this).parent().find('input');
                    if (isAutoAdd && autoMac && input.length && !input.val()) input.val(autoMac);
                    input.off('change.sync').on('change.sync', function() {
                        $('#hiddenNativeMac').val($(this).val());
                        $('#mac_address').val($(this).val());
                    });
                }
            }
        });

        // Eğer IP Adresi özel alan olarak gelmişse, native görsel kutuyu gizle, onun yerine hidden input kullan (Backend'in hatasız kaydetmesi için)
        if (customIpFound) {
            $('#nativeIpWrapper').hide();
            if (!$('#hiddenNativeIp').length) targetForm.append('<input type="hidden" name="ip_address" id="hiddenNativeIp" value="'+(isAutoAdd ? autoIp : $('#ip_address').val())+'">');
        } else {
            $('#nativeIpWrapper').show();
            $('#hiddenNativeIp').remove();
        }

        if (customMacFound) {
            $('#nativeMacWrapper').hide();
            if (!$('#hiddenNativeMac').length) targetForm.append('<input type="hidden" name="mac_address" id="hiddenNativeMac" value="'+(isAutoAdd ? autoMac : $('#mac_address').val())+'">');
        } else {
            $('#nativeMacWrapper').show();
            $('#hiddenNativeMac').remove();
        }

        // Eğer hem IP hem MAC özel alanla hallediliyorsa, tüm native kartı gizle
        if (customIpFound && customMacFound) {
            $('#nativeNetworkFieldsCard').hide();
        } else {
            $('#nativeNetworkFieldsCard').show();
        }

        // Keşfedilen cihaz ID'sini forma ekle (sadece auto_add durumunda)
        if (isAutoAdd && autoDiscId && !targetForm.find('input[name="discovered_id"]').length) {
            targetForm.append('<input type="hidden" name="discovered_id" value="'+autoDiscId+'">');
        }
    });

    observer.observe(document.body, { childList: true, subtree: true });

    // Ağ tarayıcıdan geldiysek modalı aç
    if (isAutoAdd) {
        setTimeout(() => {
            if (typeof addAssetByView === 'function') {
                let viewParam = new URLSearchParams(window.location.search).get('view') || 'assets';
                addAssetByView(viewParam);
            } else if ($('#btnNewRecord').length) {
                $('#btnNewRecord').click();
            } else {
                $('button[onclick^="addAssetByView"]').first().click();
            }
            
            setTimeout(() => {
                $('input[name="name"]').val(autoHostname);
                
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: '<?= __("Ağ cihazı bilgileri hazırlandı.") ?>',
                        showConfirmButton: false,
                        timer: 4000
                    });
                }
            }, 800);
        }, 500);
    }
});
</script>

