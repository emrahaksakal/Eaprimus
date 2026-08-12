<?php
// pages/main.php
require_once __DIR__ . "/../includes/asset_helpers.php";

$rol = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$has_varliklar_perm = false;
try {
    $stmtPermCheck = $pdo->prepare("SELECT id FROM user_perm WHERE role_id = ? AND (route_name = '*' OR FIND_IN_SET('varliklar', route_name))");
    $stmtPermCheck->execute([$rol]);
    if ($stmtPermCheck->fetch()) {
        $has_varliklar_perm = true;
    }
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_log') {
    $logId = (int) ($_POST['log_id'] ?? 0);
    if ($logId > 0) {
        $pdo->prepare("UPDATE asset_timeline SET is_deleted = 1 WHERE id = ?")->execute([$logId]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if (hasPermission('dashboard_view_all')) {
    // ---------------------------------------------------------
    // MODERN Dashboard (Admin, Manager, Staff)
    // ---------------------------------------------------------
    function ticketsPersonnelColumn(PDO $pdo): string
    {
        static $col = null;
        if ($col !== null) {
            return $col;
        }
        try {
            // Safer check for column existence without information_schema
            $stmt = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'personnel_id'");
            $hasPersonnel = $stmt->fetch();
            $col = $hasPersonnel ? 'personnel_id' : 'assigned_to';
            return $col;
        } catch (Throwable $e) {
            return 'assigned_to'; // Fallback to assigned_to which we verified exists
        }
    }
    $personnelCol = ticketsPersonnelColumn($pdo);
    $isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';
    $ui = [
        'all_dashboards' => $isTr ? 'Genel BakÄ±ÅŸ' : 'All Dashboards',
        'inventory_dashboard' => $isTr ? 'Envanter Panosu' : 'Inventory Dashboard',
        'ticket_system' => $isTr ? 'Destek Sistemi' : 'Ticket System',
        'inventory_slogan' => $isTr ? 'Envanter YÃ¶netim Paneli' : 'Inventory Management Panel',
        'ticket_slogan' => $isTr ? 'Destek ve SLA YÃ¶netim Merkezi' : 'Support and SLA Management Center',
        'view_all' => $isTr ? 'TÃ¼mÃ¼nÃ¼ GÃ¶r' : 'View All',
        'ready' => $isTr ? 'HazÄ±r' : 'Ready',
        'assigned' => $isTr ? 'AtanmÄ±ÅŸ' : 'Assigned',
        'faulty' => $isTr ? 'Arızalı' : 'Faulty',
        'scrapped' => $isTr ? 'Hurda' : 'Scrapped',
        'assets_by_status' => $isTr ? 'Duruma Göre Varlıklar' : 'Assets by Status',
        'recent_activity' => $isTr ? 'Son Etkinlikler' : 'Recent Activity',
        'timeline_created' => $isTr ? 'Oluşturuldu' : 'Created',
        'timeline_updated' => $isTr ? 'Güncellendi' : 'Updated',
        'timeline_deleted' => $isTr ? 'Silindi' : 'Deleted',
        'timeline_restored' => $isTr ? 'Geri Yüklendi' : 'Restored',
        'timeline_checkin' => $isTr ? 'Geri Alındı' : 'Checked In',
        'timeline_checkout' => $isTr ? 'Zimmetlendi' : 'Checked Out',
        'system' => $isTr ? 'Sistem' : 'System',
        'new_inventory' => $isTr ? 'Yeni Envanter Ekle' : 'Add New Inventory',
        'asset_checked_in_from' => $isTr ? 'Geri Alındı' : 'Checked In',
        'asset_checked_out_to' => $isTr ? 'Zimmetlendi' : 'Checked Out',
        'asset_checked_in' => $isTr ? 'Geri Alındı' : 'Checked In',
        'asset_checked_out' => $isTr ? 'Zimmetlendi' : 'Checked Out',
    ];

    $whereCount = "";
    if ($rol == 2) {
        $whereCount = "AND t.creator_id = " . (int)$user_id;
    } elseif ($rol == 3) {
        $whereCount = "AND (t.queue_id IN (SELECT q.id FROM queues q JOIN teams_users tu ON q.team_id=tu.team_id WHERE tu.user_id = " . (int)$user_id . ") OR t.$personnelCol = " . (int)$user_id . " OR t.creator_id = " . (int)$user_id . ")";
    }

    // TOPLAM AKTÄ°F BÄ°LET SAYISI
    $c_open_total = $pdo->query("SELECT COUNT(*) FROM tickets t WHERE t.status NOT IN ('resolved','closed') $whereCount")->fetchColumn();

    // Default Panel Logic
    $default_panel = ($c_open_total > 0) ? 'ticket' : 'inventory';
    $panel_mode = $_GET['panel'] ?? $default_panel;

    $inventoryData = [
        'assets'      => ['ready' => 0, 'assigned' => 0, 'scrapped' => 0, 'faulty' => 0],
        'licenses'    => ['ready' => 0, 'assigned' => 0, 'scrapped' => 0, 'faulty' => 0],
        'accessories' => ['ready' => 0, 'assigned' => 0, 'scrapped' => 0, 'faulty' => 0],
        'consumables' => ['ready' => 0, 'assigned' => 0, 'scrapped' => 0, 'faulty' => 0],
        'components'  => ['ready' => 0, 'assigned' => 0, 'scrapped' => 0, 'faulty' => 0]
    ];

    // ENVANTER SAYILARI
    if ($panel_mode == 'inventory') {
        $c_assets = $pdo->query("SELECT COUNT(*) FROM assets WHERE deleted_at IS NULL AND (status_id IS NULL OR status_id != 6)")->fetchColumn();
        $c_licenses = $pdo->query("SELECT COUNT(*) FROM asset_licenses WHERE deleted_at IS NULL")->fetchColumn();
        $c_accessories = $pdo->query("SELECT COUNT(*) FROM asset_accessories WHERE deleted_at IS NULL AND (status NOT LIKE '%Hurda%' AND status NOT LIKE '%Scrap%')")->fetchColumn();
        $c_consumables = $pdo->query("SELECT COUNT(*) FROM asset_consumables WHERE deleted_at IS NULL")->fetchColumn();
        $c_components = (int)$pdo->query("SELECT COALESCE(SUM(total_qty), COUNT(*), 0) FROM asset_components WHERE deleted_at IS NULL AND (status NOT LIKE '%Hurda%' AND status NOT LIKE '%Scrap%' OR status IS NULL)")->fetchColumn();
        $c_people = $pdo->query("SELECT COUNT(*) FROM users WHERE username != 'customer_gateway' AND deleted_at IS NULL")->fetchColumn();
        
        // Bekleyen İmzalar (Kullanıcının kendi bekleyen imzaları veya Adminin beklediği imzalar)
        if ($rol == 1 || $rol == 2) {
            // Admin can see all pending signatures
            $c_pending_sigs = $pdo->query("SELECT COUNT(*) FROM asset_signatures WHERE status IN ('pending_user', 'pending_admin')")->fetchColumn();
        } else {
            // Personnel sees only their own pending signatures
            $c_pending_sigs = $pdo->prepare("SELECT COUNT(*) FROM asset_signatures WHERE status = 'pending_user' AND user_id = ?");
            $c_pending_sigs->execute([$user_id]);
            $c_pending_sigs = $c_pending_sigs->fetchColumn();
        }

        // Unified Inventory Status Aggregation
        $inventoryData = [
            'assets'      => ['ready' => 0, 'assigned' => 0, 'scrapped' => 0, 'faulty' => 0],
            'licenses'    => ['ready' => 0, 'assigned' => 0, 'scrapped' => 0, 'faulty' => 0],
            'accessories' => ['ready' => 0, 'assigned' => 0, 'scrapped' => 0, 'faulty' => 0],
            'consumables' => ['ready' => 0, 'assigned' => 0, 'scrapped' => 0, 'faulty' => 0],
            'components'  => ['ready' => 0, 'assigned' => 0, 'scrapped' => 0, 'faulty' => 0]
        ];

        // 1. Assets
        $assetsTotal = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE deleted_at IS NULL")->fetchColumn();
        $inventoryData['assets']['assigned'] = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE (assigned_user_id IS NOT NULL OR asset_id IS NOT NULL) AND deleted_at IS NULL")->fetchColumn();
        $inventoryData['assets']['scrapped'] = (int)$pdo->query("SELECT COUNT(*) FROM assets a LEFT JOIN asset_status_labels sl ON a.status_id = sl.id WHERE sl.id = 6 AND a.deleted_at IS NULL")->fetchColumn();
        $inventoryData['assets']['faulty'] = (int)$pdo->query("SELECT COUNT(*) FROM assets a LEFT JOIN asset_status_labels sl ON a.status_id = sl.id WHERE sl.type IN ('undeployable', 'pending') AND sl.id != 6 AND a.deleted_at IS NULL")->fetchColumn();
        $inventoryData['assets']['ready'] = max(0, $assetsTotal - $inventoryData['assets']['assigned'] - $inventoryData['assets']['scrapped'] - $inventoryData['assets']['faulty']);

        // 2. Licenses
        $licTotal = $pdo->query("SELECT SUM(seats) as seats FROM asset_licenses WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
        $licAssigned = $pdo->query("SELECT SUM(quantity) as qty FROM asset_license_checkouts")->fetch(PDO::FETCH_ASSOC);
        $inventoryData['licenses']['assigned'] = (int)($licAssigned['qty'] ?? 0);
        $inventoryData['licenses']['ready'] = max(0, (int)($licTotal['seats'] ?? 0) - $inventoryData['licenses']['assigned']);

        // 3. Accessories
        $accStats = $pdo->query("SELECT 
            SUM(total_qty) as total,
            SUM(CASE WHEN status LIKE '%Hurda%' OR status LIKE '%Scrap%' THEN total_qty ELSE 0 END) as scrapped,
            SUM(CASE WHEN status LIKE '%Arıza%' OR status LIKE '%Faulty%' THEN total_qty ELSE 0 END) as faulty
            FROM asset_accessories WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
        $accAssigned = (int)$pdo->query("SELECT SUM(quantity) FROM asset_accessory_checkouts")->fetchColumn();
        $inventoryData['accessories']['assigned'] = $accAssigned;
        $inventoryData['accessories']['scrapped'] = (int)($accStats['scrapped'] ?? 0);
        $inventoryData['accessories']['faulty'] = (int)($accStats['faulty'] ?? 0);
        $inventoryData['accessories']['ready'] = max(0, (int)($accStats['total'] ?? 0) - $accAssigned - $inventoryData['accessories']['scrapped'] - $inventoryData['accessories']['faulty']);

        // 4. Consumables
        $conStats = $pdo->query("SELECT SUM(total_qty) as total, SUM(remaining_qty) as remaining FROM asset_consumables WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
        $inventoryData['consumables']['ready'] = (int)($conStats['remaining'] ?? 0);
        $inventoryData['consumables']['assigned'] = max(0, (int)($conStats['total'] ?? 0) - $inventoryData['consumables']['ready']);

        // 5. Components
        $compStats = $pdo->query("SELECT 
            SUM(total_qty) as total,
            SUM(CASE WHEN status LIKE '%Hurda%' OR status LIKE '%Scrap%' THEN total_qty ELSE 0 END) as scrapped,
            SUM(CASE WHEN status LIKE '%Arıza%' OR status LIKE '%Faulty%' THEN total_qty ELSE 0 END) as faulty
            FROM asset_components WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
        $compAssigned = (int)$pdo->query("SELECT SUM(quantity) FROM asset_component_checkouts")->fetchColumn();
        $inventoryData['components']['assigned'] = $compAssigned;
        $inventoryData['components']['scrapped'] = (int)($compStats['scrapped'] ?? 0);
        $inventoryData['components']['faulty'] = (int)($compStats['faulty'] ?? 0);
        $inventoryData['components']['ready'] = max(0, (int)($compStats['total'] ?? 0) - $compAssigned - $inventoryData['components']['scrapped'] - $inventoryData['components']['faulty']);

        $catDist = $pdo->query("
            SELECT c.id, c.name, c.type,
                   (SELECT COUNT(*) FROM assets a WHERE a.category_id = c.id AND a.deleted_at IS NULL) as asset_count,
                   (SELECT COUNT(*) FROM asset_consumables con WHERE con.category_id = c.id AND con.deleted_at IS NULL) as con_count,
                   (SELECT COUNT(*) FROM asset_accessories acc WHERE acc.category_id = c.id AND acc.deleted_at IS NULL) as acc_count,
                   (SELECT COUNT(*) FROM asset_licenses lic WHERE lic.category_id = c.id AND lic.deleted_at IS NULL) as lic_count,
                   (SELECT COUNT(DISTINCT comp.name) FROM asset_components comp WHERE comp.category_id = c.id AND comp.deleted_at IS NULL) as comp_count
            FROM asset_categories c
            HAVING (asset_count + con_count + acc_count + lic_count + comp_count) > 0
            ORDER BY (asset_count + con_count + acc_count + lic_count + comp_count) DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Append virtual "Kategorisiz" (Uncategorized) categories if there are any uncategorized items
        $uncatAssets = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE (category_id IS NULL OR category_id = 0) AND deleted_at IS NULL")->fetchColumn();
        if ($uncatAssets > 0) {
            $catDist[] = [
                'id' => 0,
                'name' => $isTr ? 'Kategorisiz Demirbaşlar' : 'Uncategorized Assets',
                'type' => 'asset',
                'asset_count' => $uncatAssets,
                'con_count' => 0,
                'acc_count' => 0,
                'lic_count' => 0,
                'comp_count' => 0
            ];
        }

        $uncatLicenses = (int)$pdo->query("SELECT COUNT(*) FROM asset_licenses WHERE (category_id IS NULL OR category_id = 0) AND deleted_at IS NULL")->fetchColumn();
        if ($uncatLicenses > 0) {
            $catDist[] = [
                'id' => 0,
                'name' => $isTr ? 'Kategorisiz Lisanslar' : 'Uncategorized Licenses',
                'type' => 'license',
                'asset_count' => 0,
                'con_count' => 0,
                'acc_count' => 0,
                'lic_count' => $uncatLicenses,
                'comp_count' => 0
            ];
        }

        $uncatConsumables = (int)$pdo->query("SELECT COUNT(*) FROM asset_consumables WHERE (category_id IS NULL OR category_id = 0) AND deleted_at IS NULL")->fetchColumn();
        if ($uncatConsumables > 0) {
            $catDist[] = [
                'id' => 0,
                'name' => $isTr ? 'Kategorisiz Sarf Malzemeler' : 'Uncategorized Consumables',
                'type' => 'consumable',
                'asset_count' => 0,
                'con_count' => $uncatConsumables,
                'acc_count' => 0,
                'lic_count' => 0,
                'comp_count' => 0
            ];
        }

        $uncatAccessories = (int)$pdo->query("SELECT COUNT(*) FROM asset_accessories WHERE (category_id IS NULL OR category_id = 0) AND deleted_at IS NULL")->fetchColumn();
        if ($uncatAccessories > 0) {
            $catDist[] = [
                'id' => 0,
                'name' => $isTr ? 'Kategorisiz Aksesuarlar' : 'Uncategorized Accessories',
                'type' => 'accessory',
                'asset_count' => 0,
                'con_count' => 0,
                'acc_count' => $uncatAccessories,
                'lic_count' => 0,
                'comp_count' => 0
            ];
        }

        $uncatComponents = (int)$pdo->query("SELECT COUNT(DISTINCT name) FROM asset_components WHERE (category_id IS NULL OR category_id = 0) AND deleted_at IS NULL")->fetchColumn();
        if ($uncatComponents > 0) {
            $catDist[] = [
                'id' => 0,
                'name' => $isTr ? 'Kategorisiz Bileşenler' : 'Uncategorized Components',
                'type' => 'component',
                'asset_count' => 0,
                'con_count' => 0,
                'acc_count' => 0,
                'lic_count' => 0,
                'comp_count' => $uncatComponents
            ];
        }
    }

    if ($panel_mode == 'ticket') {
        $filter_q = $_GET['q'] ?? '';
        $filter_status = $_GET['status'] ?? 'active';
        $filter_priority = $_GET['priority'] ?? '';
        $filter_queue = $_GET['queue'] ?? '';
        $sort = $_GET['sort'] ?? 'prio';

        $c_open = $c_open_total;
        $c_closed = $pdo->query("SELECT COUNT(*) FROM tickets t WHERE t.status IN ('resolved','closed') $whereCount")->fetchColumn();
        $c_all = $pdo->query("SELECT COUNT(*) FROM tickets t WHERE 1=1 $whereCount")->fetchColumn();
        // Modern Breach Query: includes critical priority, overdue SLA, or 24h fallback
        $c_breach = $pdo->query("SELECT COUNT(*) FROM tickets t WHERE t.status NOT IN ('resolved','closed') AND (t.sla_due_date < NOW() OR (t.sla_due_date IS NULL AND t.create_date < DATE_SUB(NOW(), INTERVAL 24 HOUR))) $whereCount")->fetchColumn();

        if ($rol == 1 || $rol == 3) {
            $queues = $pdo->query("SELECT id, name FROM queues ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $q_stmt = $pdo->prepare("SELECT q.id, q.name FROM queues q JOIN teams_users tu ON q.team_id = tu.team_id WHERE tu.user_id = ? ORDER BY q.name ASC");
            $q_stmt->execute([$user_id]);
            $queues = $q_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $listWhere = ["1=1"];
        if ($rol == 2) {
            $listWhere[] = "t.creator_id = " . (int)$user_id;
        } elseif ($rol == 3) {
            $listWhere[] = "(t.queue_id IN (SELECT q.id FROM queues q JOIN teams_users tu ON q.team_id=tu.team_id WHERE tu.user_id = " . (int)$user_id . ") OR t.$personnelCol = " . (int)$user_id . " OR t.creator_id = " . (int)$user_id . ")";
        }
        if ($filter_status == 'active' || empty($filter_status)) {
            $listWhere[] = "t.status NOT IN ('resolved','closed')";
        } elseif ($filter_status == 'closed') {
            $listWhere[] = "t.status IN ('resolved','closed')";
        } elseif ($filter_status == 'breach') {
            $listWhere[] = "t.status NOT IN ('resolved','closed') AND (t.sla_due_date < NOW() OR (t.sla_due_date IS NULL AND t.create_date < DATE_SUB(NOW(), INTERVAL 24 HOUR)))";
        } elseif ($filter_status != 'all') {
            $listWhere[] = "t.status = " . $pdo->quote($filter_status);
        }
        if (!empty($filter_q)) {
            $listWhere[] = "(t.ticket_no LIKE " . $pdo->quote("%$filter_q%") . " OR t.title LIKE " . $pdo->quote("%$filter_q%") . ")";
        }
        if (!empty($filter_priority)) {
            $listWhere[] = "t.priority = " . $pdo->quote($filter_priority);
        }
        if (!empty($filter_queue)) {
            $listWhere[] = "t.queue_id = " . (int) $filter_queue;
        }

        $lWhereStr = "WHERE " . implode(' AND ', $listWhere);
        $orderSql = ($sort === 'newest') ? "ORDER BY t.create_date DESC" : (($sort === 'oldest') ? "ORDER BY t.create_date ASC" : "ORDER BY FIELD(t.priority,'critical','high','normal','low'), t.create_date DESC");

        $tickets = $pdo->query("SELECT t.id, t.ticket_no, t.title, t.priority, t.create_date, t.status, t.sla_due_date, 
            u.fullname as creator, a.fullname as agent, q.name as queue_name, c.name as customer_name, t.locked_by, t.locked_at, lu.fullname as locked_by_name,
            (SELECT u2.fullname FROM ticket_replies r2 JOIN users u2 ON r2.user_id = u2.id WHERE r2.ticket_id = t.id AND r2.is_private = 0 ORDER BY r2.created_at DESC LIMIT 1) as last_reply_user,
            (SELECT r2.created_at FROM ticket_replies r2 WHERE r2.ticket_id = t.id AND r2.is_private = 0 ORDER BY r2.created_at DESC LIMIT 1) as last_reply_at,
            (CASE WHEN t.status NOT IN ('resolved','closed') AND (t.sla_due_date < NOW() OR (t.sla_due_date IS NULL AND t.create_date < DATE_SUB(NOW(), INTERVAL 24 HOUR))) THEN 1 ELSE 0 END) as is_breached
        FROM tickets t 
        LEFT JOIN users u ON t.creator_id = u.id 
        LEFT JOIN users a ON t.$personnelCol = a.id 
        LEFT JOIN users lu ON t.locked_by = lu.id 
        LEFT JOIN queues q ON t.queue_id = q.id 
        LEFT JOIN customers c ON c.id = t.customer_id 
        $lWhereStr $orderSql LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

        $statusConfig = json_decode($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ticket_statuses_config'")->fetchColumn() ?: '', true);
        if (empty($statusConfig)) {
            $statusLabels = ['open' => __('open'), 'assigned' => __('ticket_status_assigned'), 'waiting_customer' => __('ticket_status_waiting_customer'), 'closed' => __('closed')];
            $statusColors = ['open' => '#3b82f6', 'assigned' => '#6366f1', 'waiting_customer' => '#8b5cf6', 'closed' => '#64748b'];
        } else {
            $statusLabels = [];
            $statusColors = [];
            foreach ($statusConfig as $k => $v) {
                $translated = __("ticket_status_" . $k);
                if ($translated !== "ticket_status_" . $k) {
                    $statusLabels[$k] = $translated;
                } else {
                    $statusLabels[$k] = $v['label'];
                }
                $statusColors[$k] = $v['color'];
            }
        }
    }

    $inv_slogan = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'inv_slogan'")->fetchColumn() ?: 'Envanter Yönetim Paneli';
    $ticket_slogan = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ticket_slogan'")->fetchColumn() ?: 'Destek ve SLA Yönetim Merkezi';
    
    $inv_title = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'inv_title'")->fetchColumn() ?: ($isTr ? 'Envanter Panosu' : 'Inventory Dashboard');
    $ticket_title = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ticket_title'")->fetchColumn() ?: ($isTr ? 'Destek Sistemi' : 'Ticket System');
    
    $current_slogan = ($panel_mode == 'inventory') ? $inv_slogan : $ticket_slogan;
    $current_title = ($panel_mode == 'inventory') ? $inv_title : $ticket_title;
    ?>
    <style>
        body:not(.dark-mode) .content-wrapper {
            background: #f4f6f9 !important;
        }

        body.dark-mode .content-wrapper {
            background: #0f1216 !important;
            color: #e5e7eb;
        }

        /* Header Tab Switcher Refinement */
        .header-tab-group {
            display: inline-flex;
            align-items: center;
            gap: 12px; /* Distinct spacing between tabs */
        }

        body.dark-mode .header-tab-group {
            background: transparent;
            border-color: transparent;
        }

        .header-tab-btn {
            padding: 10px 22px;
            font-size: 13px;
            font-weight: 700;
            color: #64748b;
            text-decoration: none !important;
            border-radius: 12px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.04);
        }

        .header-tab-btn:hover {
            color: #1e293b;
            background: #f8fafc;
            transform: translateY(-1px);
        }

        .header-tab-btn.active {
            background: #1e3c72;
            border-color: #1e3c72;
            color: #fff !important;
            box-shadow: 0 4px 12px rgba(30, 60, 114, 0.25);
        }

        body.dark-mode .header-tab-btn {
            background: #1a1f26;
            border-color: #313a48;
            color: #94a3b8;
        }

        body.dark-mode .header-tab-btn:hover {
            background: #212832;
            color: #fff;
        }

        .header-tab-divider {
            display: none;
        }

        .ds-page {
            padding: 24px;
        }

        .stat-card {
            border-radius: 18px;
            padding: 24px;
            color: white;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            border: none;
            height: 100%;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            z-index: 1;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: -100%; width: 50%; height: 100%;
            background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.15) 50%, rgba(255,255,255,0) 100%);
            transform: skewX(-25deg);
            transition: all 0.6s ease;
            z-index: 2;
        }

        .stat-card:hover::before {
            left: 200%;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 30px rgba(0, 0, 0, 0.15);
        }

        .stat-card .icon-bg {
            position: absolute;
            right: -10px;
            top: -10px;
            font-size: 80px;
            opacity: 0.15;
            transform: rotate(-15deg);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            z-index: 0;
        }

        .stat-card:hover .icon-bg {
            transform: rotate(0deg) scale(1.15);
            opacity: 0.25;
        }

        .stat-card .count {
            font-size: 38px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 6px;
            position: relative;
            z-index: 3;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .stat-card .label {
            font-size: 14.5px;
            font-weight: 600;
            text-transform: uppercase;
            opacity: 0.95;
            letter-spacing: 0.5px;
            position: relative;
            z-index: 3;
        }

        .bg-assets { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); }
        .bg-licenses { background: linear-gradient(135deg, #2b32b2 0%, #1488cc 100%); }
        .bg-accessories { background: linear-gradient(135deg, #373b44 0%, #4286f4 100%); }
        .bg-consumables { background: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%); }
        .bg-components { background: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%); }
        .bg-people { background: linear-gradient(135deg, #485563 0%, #29323c 100%); }

        .ds-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
            overflow: hidden;
            border: 1px solid #edf2f7;
        }

        body.dark-mode .ds-card {
            background: #1a1f26;
            border: 1px solid #2d343c;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .ds-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .ds-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            padding: 14px 20px;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
        }

        .ds-table td {
            padding: 14px 20px;
            font-size: 13px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        body.dark-mode .ds-table th {
            background: #212832;
            color: #94a3b8;
            border-color: #313a48;
        }

        body.dark-mode .ds-table td {
            color: #e5e7eb;
            border-color: #2d343c;
        }

        .ds-topbar-title {
            font-size: 22px;
            font-weight: 800;
            color: #1e293b;
            display: flex;
            align-items: center;
        }

        body.dark-mode .ds-topbar-title { color: #f8fafc !important; }

        .ds-topbar-subtitle {
            font-size: 13px;
            color: #64748b;
            margin-top: -2px;
        }

        body.dark-mode .ds-topbar-subtitle { color: #94a3b8 !important; }

        .event-badge {
            font-size: 10px;
            padding: 6px 14px;
            border-radius: 50px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border: 1px solid transparent;
            display: inline-block;
            line-height: 1;
        }

        .event-updated { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
        .event-deleted { background: #fff1f2; color: #991b1b; border-color: #fecaca; }
        .event-created { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
        .event-checkout { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
        .event-checkin { background: #faf5ff; color: #5b21b6; border-color: #e9d5ff; }

        body.dark-mode .event-updated { background: rgba(148, 163, 184, 0.1); color: #94a3b8; border-color: rgba(148, 163, 184, 0.2); }
        body.dark-mode .event-deleted { background: rgba(153, 27, 27, 0.1); color: #f87171; border-color: rgba(153, 27, 27, 0.2); }
        body.dark-mode .event-created { background: rgba(22, 101, 52, 0.1); color: #34d399; border-color: rgba(22, 101, 52, 0.2); }
        body.dark-mode .event-checkout { background: rgba(30, 64, 175, 0.1); color: #60a5fa; border-color: rgba(30, 64, 175, 0.2); }
        body.dark-mode .event-checkin { background: rgba(91, 33, 182, 0.1); color: #a78bfa; border-color: rgba(91, 33, 182, 0.2); }

        .bg-active { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .bg-solved { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .bg-breach { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .bg-all { background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%); }

        body.dark-mode .btn-outline-primary {
            color: #60a5fa !important;
            border-color: #3b82f6 !important;
            background: rgba(59, 130, 246, 0.05) !important;
        }
        
        body.dark-mode .btn-outline-primary:hover {
            background: #3b82f6 !important;
            color: #fff !important;
        }

        body.dark-mode .ds-card-header, 
        body.dark-mode .ds-card .border-bottom {
            background: #212832 !important;
            color: #f8fafc !important;
            border-bottom-color: #313a48 !important;
        }

        body.dark-mode .text-dark { color: #f1f5f9 !important; }
        body.dark-mode .bg-light { background-color: #1a1f26 !important; color: #cbd5e1 !important; }
        body.dark-mode .table-hover tbody tr:hover { background-color: rgba(255,255,255,0.05) !important; color: #fff !important; }

        .pulse-badge { font-size: 10px; border-radius: 50px; padding: 2px 7px; animation: pulse-red-badge 2s infinite; }
        @keyframes pulse-red-badge {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            70% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        /* Premium Dashboard Modernizations */
        .badge-soft-primary {
            background-color: rgba(59, 130, 246, 0.1) !important;
            color: #3b82f6 !important;
            border: 1px solid rgba(59, 130, 246, 0.15) !important;
            border-radius: 50px;
            font-weight: 600;
            padding: 5px 12px;
        }
        .badge-soft-success {
            background-color: rgba(16, 185, 129, 0.1) !important;
            color: #10b981 !important;
            border: 1px solid rgba(16, 185, 129, 0.15) !important;
            border-radius: 50px;
            font-weight: 600;
            padding: 5px 12px;
        }
        .badge-soft-info {
            background-color: rgba(6, 182, 212, 0.1) !important;
            color: #06b6d4 !important;
            border: 1px solid rgba(6, 182, 212, 0.15) !important;
            border-radius: 50px;
            font-weight: 600;
            padding: 5px 12px;
        }
        .badge-soft-secondary {
            background-color: rgba(100, 116, 139, 0.1) !important;
            color: #64748b !important;
            border: 1px solid rgba(100, 116, 139, 0.15) !important;
            border-radius: 50px;
            font-weight: 600;
            padding: 5px 12px;
        }
        body.dark-mode .badge-soft-primary {
            background-color: rgba(96, 165, 250, 0.15) !important;
            color: #60a5fa !important;
            border-color: rgba(96, 165, 250, 0.25) !important;
        }
        body.dark-mode .badge-soft-success {
            background-color: rgba(52, 211, 153, 0.15) !important;
            color: #34d399 !important;
            border-color: rgba(52, 211, 153, 0.25) !important;
        }
        body.dark-mode .badge-soft-info {
            background-color: rgba(45, 212, 191, 0.15) !important;
            color: #2dd4bf !important;
            border-color: rgba(45, 212, 191, 0.25) !important;
        }
        body.dark-mode .badge-soft-secondary {
            background-color: rgba(148, 163, 184, 0.15) !important;
            color: #94a3b8 !important;
            border-color: rgba(148, 163, 184, 0.25) !important;
        }

        .badge-asset-tag {
            font-family: var(--font-monospace, monospace);
            font-size: 11px;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #dbeafe;
            padding: 4px 8px;
            border-radius: 6px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        body.dark-mode .badge-asset-tag {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border-color: rgba(59, 130, 246, 0.25);
        }

        .status-dot-pulse {
            animation: status-pulse-anim 1.5s infinite ease-in-out;
        }
        @keyframes status-pulse-anim {
            0% { opacity: 0.4; transform: scale(0.9); }
            50% { opacity: 1; transform: scale(1.15); }
            100% { opacity: 0.4; transform: scale(0.9); }
        }

        .btn-premium-action {
            border-radius: 50px;
            font-weight: 600;
            font-size: 12px;
            padding: 6px 16px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #1e293b;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            text-decoration: none !important;
        }
        .btn-premium-action:hover {
            background: #1e3c72;
            color: #fff !important;
            border-color: #1e3c72;
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 4px 12px rgba(30, 60, 114, 0.25);
        }
        body.dark-mode .btn-premium-action {
            background: #212832;
            color: #cbd5e1;
            border-color: #313a48;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        body.dark-mode .btn-premium-action:hover {
            background: #60a5fa;
            color: #0f172a !important;
            border-color: #60a5fa;
            box-shadow: 0 4px 12px rgba(96, 165, 250, 0.3);
        }

        .ds-card-header h5 {
            font-size: 16px;
            letter-spacing: -0.3px;
            font-weight: 700 !important;
        }

        .ds-table tbody tr {
            transition: all 0.2s ease;
        }
        .ds-table tbody tr:hover {
            background-color: #f8fafc;
        }
        body.dark-mode .ds-table tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.03) !important;
        }

        /* Premium Colored Neon Shadows for Stat Cards */
        .stat-card.bg-assets:hover { box-shadow: 0 16px 36px rgba(30, 60, 114, 0.4) !important; }
        .stat-card.bg-licenses:hover { box-shadow: 0 16px 36px rgba(43, 50, 178, 0.4) !important; }
        .stat-card.bg-accessories:hover { box-shadow: 0 16px 36px rgba(66, 134, 244, 0.4) !important; }
        .stat-card.bg-consumables:hover { box-shadow: 0 16px 36px rgba(44, 83, 100, 0.4) !important; }
        .stat-card.bg-components:hover { box-shadow: 0 16px 36px rgba(76, 161, 175, 0.4) !important; }
        .stat-card.bg-people:hover { box-shadow: 0 16px 36px rgba(41, 50, 60, 0.4) !important; }
        .stat-card.bg-active:hover { box-shadow: 0 16px 36px rgba(59, 130, 246, 0.4) !important; }
        .stat-card.bg-solved:hover { box-shadow: 0 16px 36px rgba(16, 185, 129, 0.4) !important; }
        .stat-card.bg-breach:hover { box-shadow: 0 16px 36px rgba(239, 68, 68, 0.4) !important; }
        .stat-card.bg-all:hover { box-shadow: 0 16px 36px rgba(107, 114, 128, 0.4) !important; }
    </style>

    <div class="content-header p-0">
        <div class="container-fluid">
            <div class="row align-items-center py-4 px-3">
                <div class="col-sm-6">
                    <h1 class="m-0 ds-topbar-title">
                        <i
                            class="fas <?= $panel_mode == 'inventory' ? 'fa-tachometer-alt' : 'fa-headset' ?> mr-3 text-primary"></i>
                        <?= htmlspecialchars($current_title) ?>
                    </h1>
                    <div class="ds-topbar-subtitle ml-5">
                        <?= $current_slogan ?>
                    </div>
                </div>
                <div class="col-sm-6 text-right">
                    <div class="header-tab-group shadow-sm">
                        <a href="anasayfa?panel=inventory"
                            class="header-tab-btn <?= $panel_mode == 'inventory' ? 'active' : '' ?>">
                            <i class="fas fa-boxes mr-2"></i>
                            <?= htmlspecialchars($inv_title) ?>
                        </a>
                        <div class="header-tab-divider"></div>
                        <a href="anasayfa?panel=ticket"
                            class="header-tab-btn <?= $panel_mode == 'ticket' ? 'active' : '' ?>">
                            <i class="fas fa-headset mr-2"></i>
                            <?= htmlspecialchars($ticket_title) ?>
                            <span class="badge badge-danger ml-2 pulse-badge <?= (($c_new_tickets_nav ?? 0) > 0) ? '' : 'd-none' ?>" id="support-system-tab-badge">
                                <?= $c_new_tickets_nav ?? 0 ?>
                            </span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="content ds-page">
        <div class="container-fluid">
            <?php if ($panel_mode == 'inventory') { ?>
                <?php if ($has_varliklar_perm) { ?>
                <div class="row mb-4">
                    <?php
                    $stats = [
                        ['assets', $c_assets, 'fa-desktop', 'bg-assets', 'varliklar?view=assets'],
                        ['licenses', $c_licenses, 'fa-key', 'bg-licenses', 'varliklar?view=licenses'],
                        ['accessories', $c_accessories, 'fa-headphones', 'bg-accessories', 'varliklar?view=accessories'],
                        ['consumables', $c_consumables, 'fa-box-open', 'bg-consumables', 'varliklar?view=consumables'],
                        ['components', $c_components, 'fa-memory', 'bg-components', 'varliklar?view=components'],
                        ['people', $c_people, 'fa-users', 'bg-people', 'kullanici-listele']
                    ];
                    
                    if ($c_pending_sigs > 0) {
                        $stats[] = ['pending_signatures', $c_pending_sigs, 'fa-signature', 'bg-breach', 'varliklar?view=signatures'];
                        $ui['pending_signatures'] = $isTr ? 'Bekleyen İmzalar' : 'Pending Signatures';
                    }
                    
                    foreach ($stats as $s): ?>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stat-card <?= $s[3] ?>" onclick="window.location='<?= $s[4] ?>'">
                                <i class="fas <?= $s[2] ?> icon-bg"></i>
                                <div class="count">
                                    <?= $s[1] ?>
                                </div>
                                <div class="label">
                                    <?= __($s[0]) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="ds-card mb-4">
                            <div class="p-4 border-bottom d-flex justify-content-between align-items-center bg-white">
                                <h5 class="m-0 font-weight-bold text-dark"><i class="fas fa-history mr-2 text-warning"></i>
                                    <?= $ui['recent_activity'] ?>
                                </h5>
                                <a href="raporlar?view=activity" class="btn btn-sm btn-outline-primary px-3" style="border-radius:20px; font-weight:600;">
                                    <?= $ui['view_all'] ?> <i class="fas fa-chevron-right ml-1 small"></i>
                                </a>
                            </div>
                            <div class="table-responsive" style="max-height: 500px;">
                                <table class="ds-table table-sm">
                                    <thead>
                                        <tr>
                                            <th style="min-width: 95px; white-space: nowrap;">
                                                <?= __('date') ?>
                                            </th>
                                            <th>
                                                <?= $isTr ? 'İşlem Yapan' : __('user') ?>
                                            </th>
                                            <th>
                                                <?= $isTr ? 'Hareket' : __('action') ?>
                                            </th>
                                            <th>
                                                <?= $isTr ? 'Ürün' : __('asset') ?>
                                            </th>
                                            <th>
                                                <?= $isTr ? 'Hedef' : __('target') ?>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $recent_logs = $pdo->query("SELECT at.*, u.fullname FROM asset_timeline at LEFT JOIN users u ON at.user_id = u.id WHERE at.is_deleted = 0 ORDER BY at.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

                                        $logoPath = s('logo_path');
                                        $logoUrl = (!empty($logoPath) && file_exists(__DIR__ . '/../../public/' . $logoPath)) 
                                            ? 'public/' . $logoPath 
                                            : 'public/logo.png';

                                        $eventTranslations = [
                                            'updated' => ['tr' => 'GÜNCELLENDİ', 'en' => 'UPDATED', 'class' => 'event-updated', 'icon' => 'fa-edit text-primary'],
                                            'deleted' => ['tr' => 'SİLİNDİ', 'en' => 'DELETED', 'class' => 'event-deleted', 'icon' => 'fa-trash text-danger'],
                                            'created' => ['tr' => 'OLUŞTURULDU', 'en' => 'CREATED', 'class' => 'event-created', 'icon' => 'fa-plus-circle text-success'],
                                            'approved' => ['tr' => 'ONAYLANDI', 'en' => 'APPROVED', 'class' => 'event-created', 'icon' => 'fa-check-circle text-success'],
                                            'checkout' => ['tr' => 'ATANDI', 'en' => 'CHECKOUT', 'class' => 'event-checkout', 'icon' => 'fa-user-check text-warning'],
                                            'checkin' => ['tr' => 'İADE ALINDI', 'en' => 'CHECKIN', 'class' => 'event-checkin', 'icon' => 'fa-undo text-secondary'],
                                            'restored' => ['tr' => 'GERİ YÜKLENDİ', 'en' => 'RESTORED', 'class' => 'event-created', 'icon' => 'fa-trash-restore text-info'],
                                            'return' => ['tr' => 'İADE ALINDI', 'en' => 'RETURN', 'class' => 'event-checkin', 'icon' => 'fa-undo text-secondary'],
                                            'repair' => ['tr' => 'ONARIMA GÖNDERİLDİ', 'en' => 'REPAIR', 'class' => 'event-deleted', 'icon' => 'fa-tools text-warning'],
                                            'scrap' => ['tr' => 'HURDAYA AYRILDI', 'en' => 'SCRAPPED', 'class' => 'event-deleted', 'icon' => 'fa-dumpster text-danger'],
                                            'timeline_created' => ['tr' => 'OLUŞTURULDU', 'en' => 'CREATED', 'class' => 'event-created', 'icon' => 'fa-plus-circle text-success'],
                                            'timeline_updated' => ['tr' => 'GÜNCELLENDİ', 'en' => 'UPDATED', 'class' => 'event-updated', 'icon' => 'fa-edit text-primary'],
                                            'timeline_deleted' => ['tr' => 'SİLİNDİ', 'en' => 'DELETED', 'class' => 'event-deleted', 'icon' => 'fa-trash text-danger'],
                                            'timeline_restored' => ['tr' => 'GERİ YÜKLENDİ', 'en' => 'RESTORED', 'class' => 'event-created', 'icon' => 'fa-trash-restore text-info'],
                                            'timeline_checkin' => ['tr' => 'İADE ALINDI', 'en' => 'CHECKIN', 'class' => 'event-checkin', 'icon' => 'fa-undo text-secondary'],
                                            'timeline_checkout' => ['tr' => 'ATANDI', 'en' => 'CHECKOUT', 'class' => 'event-checkout', 'icon' => 'fa-user-check text-warning']
                                        ];

                                        $translateLog = function ($desc, $isTr) {
                                            if (empty($desc)) return '';
                                            $desc = (string)$desc;

                                            // Normalize Turkish mojibake first
                                            if (function_exists('normalize_turkish_mojibake')) {
                                                $desc = normalize_turkish_mojibake($desc);
                                            }

                                            // Double Language split
                                            if (strpos($desc, ' / ') !== false) {
                                                $parts = explode(' / ', $desc);
                                                $desc = $isTr ? trim($parts[0]) : trim($parts[1]);
                                            }

                                            // Bidirectional status transitions and info update translations
                                            if ($isTr) {
                                                $desc = preg_replace('/^Info updated:\s*/i', 'Bilgi güncellendi: ', $desc);
                                                $desc = preg_replace('/Status:\s*/i', 'Durum: ', $desc);
                                            } else {
                                                $desc = preg_replace('/^Bilgi güncellendi:\s*/i', 'Info updated: ', $desc);
                                                $desc = preg_replace('/Durum:\s*/i', 'Status: ', $desc);
                                            }

                                            $statusMap = [
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
                                                'Yok' => ['tr' => 'Yok', 'en' => 'None'],
                                                'None' => ['tr' => 'Yok', 'en' => 'None']
                                            ];

                                            foreach ($statusMap as $key => $vals) {
                                                $target = $isTr ? $vals['tr'] : $vals['en'];
                                                $desc = preg_replace('/\b' . preg_quote($key, '/') . '\b/u', $target, $desc);
                                            }

                                            // Handle raw untranslated system keys that might have been saved in English
                                            $systemKeys = [
                                                '/asset_checked_in_from_asset/i' => $isTr ? 'Cihazdan geri alındı' : 'Checked in from device',
                                                '/asset_checked_in_from/i' => $isTr ? 'Personelden geri alındı' : 'Checked in from user',
                                                '/asset_assigned_to_asset/i' => $isTr ? 'Cihaza atandı' : 'Assigned to device',
                                                '/asset_assigned_to/i' => $isTr ? 'Personele atandı' : 'Assigned to user',
                                                '/asset_created/i' => $isTr ? 'Sisteme eklendi' : 'Added to system',
                                                '/Reason: Check In - Undamaged/i' => $isTr ? 'Sebep: Geri Alma - Hasarsız' : 'Reason: Check In - Undamaged',
                                                '/Reason: Check In/i' => $isTr ? 'Sebep: Geri Alma' : 'Reason: Check In',
                                                '/Cihaz API üzerinden eklendi\./ui' => $isTr ? 'Cihaz API üzerinden eklendi.' : 'Device added via API.',
                                                '/Cihaz API üzerinden güncellendi\./ui' => $isTr ? 'Cihaz API üzerinden güncellendi.' : 'Device updated via API.',
                                                '/Device added via API\./ui' => $isTr ? 'Cihaz API üzerinden eklendi.' : 'Device added via API.',
                                                '/Device updated via API\./ui' => $isTr ? 'Cihaz API üzerinden güncellendi.' : 'Device updated via API.'
                                            ];
                                            foreach ($systemKeys as $pattern => $replacement) {
                                                $desc = preg_replace($pattern, $replacement, $desc);
                                            }

                                            if ($isTr)
                                                return $desc;

                                            $rules = [
                                                '/İade onaylandı ve imzalandı \(Resmi Tutanak Oluşturuldu\)\.?/ui' => 'Return approved and signed (Official Minutes Created).',
                                                '/Zimmet onaylandı ve imzalandı \(Resmi Tutanak Oluşturuldu\)\.?/ui' => 'Assignment approved and signed (Official Minutes Created).',
                                                '/Koltuk sayısı artırıldı:\s*(\d+)/ui' => 'Number of seats increased: $1',
                                                '/Koltuk sayısı azaltıldı:\s*(\d+)/ui' => 'Number of seats decreased: $1',
                                                '/Sebep:\s*Geri Alma\s*-\s*Hasarsız/ui' => 'Reason: Retrieval - Undamaged',
                                                '/Sebep:\s*İşten Ayrılış\s*-\s*Hasarsız/ui' => 'Reason: Termination of Employment - Undamaged',
                                                '/Sebep:\s*Geri Alma/ui' => 'Reason: Retrieval',
                                                '/Sebep:\s*İşten Ayrılış/ui' => 'Reason: Termination of Employment',
                                                '/Hasarsız/ui' => 'Undamaged',
                                                '/Hasarlı/ui' => 'Damaged',
                                                '/sarf malzemesi (.*) cihazına atandı\. \((\d+) Miktar\)/i' => 'consumable assigned to $1. ($2 QTY)',
                                                '/Güncellenenler: Miktar: (\d+) -> (\d+)/i' => 'Updates: Quantity: $1 -> $2',
                                                '/(.*) bu cihaza atandı\. \((\d+) Adet\)/i' => '$1 assigned to this device. ($2 Pcs)',
                                                '/Sarf malzeme çöp kutusuna taşındı\./i' => 'Consumable moved to trash.',
                                                '/Devir: (.*) -> (.*)/i' => 'Handover: $1 -> $2',
                                                '/Cihaza atandı/i' => 'Assigned to device',
                                                '/Miktar: /i' => 'Quantity: ',
                                                '/Güncellenenler: /i' => 'Updates: ',
                                                '/Yüklendi/i' => 'Uploaded',
                                                '/Atandı/i' => 'Assigned',
                                                '/Bilgi güncellendi:/i' => 'Info updated:',
                                                '/cihazına parça takıldı\./i' => 'component attached to device.',
                                                '/cihazından parça söküldü\./i' => 'component removed from device.',
                                                '/(.*) üzerinden (.*) söküldü\./i' => '$2 detached from $1.',
                                                '/seri numarası güncellendi: (.*) -> (.*)/i' => 'serial number updated: $1 -> $2',
                                                '/personeli üzerinden geri alındı/i' => 'checked in from user',
                                                '/Etiket eklendi:/i' => 'Tag added:',
                                                '/Tedarikçi:/i' => 'Supplier:',
                                                '/Yok/i' => 'None',
                                                '/Alım Tarihi/i' => 'Purchase Date',
                                                '/Sipariş No/i' => 'Order No',
                                                '/Personel:/i' => 'User:',
                                                '/Şirket:/i' => 'Company:',
                                                '/Maliyet/i' => 'Cost',
                                                '/Kullanıcı çöp kutusundan geri yüklendi\./i' => 'User restored from trash.',
                                                '/Kullanıcı kaydı çöp kutusuna taşındı\./i' => 'User moved to trash.',
                                                '/Sarf malzemesi bilgileri güncellendi\./i' => 'Consumable info updated.',
                                                '/Kalan Adet:/i' => 'Remaining Quantity:',
                                                '/Sarf malzemesi sisteme eklendi\./i' => 'Consumable added to system.',
                                                '/Başlangıç Miktarı:/i' => 'Initial Quantity:',
                                                '/Bölüm güncellendi:/i' => 'Department updated:',
                                                '/Şirket güncellendi:/i' => 'Company updated:',
                                                '/Bileşen transfer edildi:/i' => 'Component transferred:',
                                                '/cihazına aksesuar atanmıştır\./i' => 'accessory assigned to device.',
                                                '/Aksesuar geri alındı\./i' => 'Accessory checked in.',
                                                '/personeline zimmetlendi\./i' => 'checked out to user.',
                                                '/Bağlı Cihaz:/i' => 'Connected Device:',
                                                '/İşlemci \(CPU\):/i' => 'Processor (CPU):',
                                                '/Bellek \(RAM\):/i' => 'Memory (RAM):',
                                                 '/Durum:\s*Hazır/ui' => 'Status: Ready',
                                                 '/Durum:\s*Atanmış/ui' => 'Status: Assigned',
                                                 '/Durum:\s*Arızalı/ui' => 'Status: Faulty',
                                                 '/Durum:\s*Hurda/ui' => 'Status: Scrap',
                                                 '/Durum:\s*İmza Bekliyor/ui' => 'Status: Pending Signature',
                                                 '/Durum:\s*Beklemede/ui' => 'Status: Pending',
                                                 '/Durum:\s*Yok/ui' => 'Status: None',
                                                 '/Status:\s*Hazır/ui' => 'Status: Ready',
                                                 '/Status:\s*Atanmış/ui' => 'Status: Assigned',
                                                 '/Status:\s*Arızalı/ui' => 'Status: Faulty',
                                                 '/Status:\s*Hurda/ui' => 'Status: Scrap',
                                                 '/Status:\s*İmza Bekliyor/ui' => 'Status: Pending Signature',
                                                 '/Status:\s*Beklemede/ui' => 'Status: Pending',
                                                 '/Status:\s*Yok/ui' => 'Status: None',
                                                 '/->\s*Hazır/ui' => '-> Ready',
                                                 '/->\s*Atanmış/ui' => '-> Assigned',
                                                 '/->\s*Arızalı/ui' => '-> Faulty',
                                                 '/->\s*Hurda/ui' => '-> Scrap',
                                                 '/->\s*İmza Bekliyor/ui' => '-> Pending Signature',
                                                 '/->\s*Beklemede/ui' => '-> Pending',
                                                 '/->\s*Yok/ui' => '-> None',
                                                 '/Durum:/i' => 'Status:',
                                                '/Kesin Hurdaya Ayrıldı/i' => 'Permanently Scrapped',
                                                '/Hurdaya ayrıldı/i' => 'Scrapped',
                                                '/personeline zimmetlendi/i' => 'checked out to user',
                                                '/Hızlı stok girişi ile eklendi/i' => 'Added via quick stock entry',
                                                '/sisteme eklendi/i' => 'added to system',
                                                '/Başlangıç Miktarı/i' => 'Initial Quantity',
                                                '/çöp kutusuna taşındı/i' => 'moved to trash',
                                                '/Stok eklendi/i' => 'Stock added',
                                                '/Bileşen grubu güncellendi/i' => 'Component group updated',
                                                '/boşta parça stoktan düşüldü/i' => 'idle parts removed from stock',
                                                '/Arızalı/i' => 'Faulty',
                                                '/Aksesuar geri alındı/i' => 'Accessory checked in',
                                                '/Sarf malzemesi bilgileri güncellendi/i' => 'Consumable information updated',
                                                '/Aksesuar bilgileri güncellendi/i' => 'Accessory information updated',
                                                '/Bileşen/i' => 'Component',
                                                '/Varlık/i' => 'Asset',
                                                '/Aksesuar/i' => 'Accessory',
                                                '/cihazına/i' => 'to device',
                                                '/atandı/i' => 'assigned',
                                                '/atama yapıldı/i' => 'assigned',
                                                '/geri alındı/i' => 'checked in',
                                                '/iade edildi/i' => 'returned',
                                                '/bilgileri güncellendi/i' => 'information updated',
                                                '/Satın Alma/i' => 'Purchase',
                                                '/Devir/i' => 'Handover',
                                                '/sarf malzemesi/i' => 'consumable',
                                                '/öğesinin/i' => 'item',
                                                '/üzerinden iade alındı/i' => 'returned from',
                                                '/iade alındı/i' => 'checked in',
                                                '/Quantityi/i' => 'Quantity',
                                                '/personeline/i' => 'personnel',
                                                '/Notlar:/i' => 'Notes:',
                                                '/Sistem/i' => 'System',
                                                '/Ürün/i' => 'Product',
                                                '/Adet/i' => 'Quantity',
                                                '/Silindi/i' => 'Deleted',
                                                '/bozuk/i' => 'broken',
                                                '/Kullanıcı/i' => 'User'
                                            ];
                                            foreach ($rules as $pattern => $replacement) {
                                                if (strpos($pattern, '/') === 0) {
                                                    $desc = preg_replace($pattern, $replacement, $desc);
                                                } else {
                                                    $desc = str_ireplace($pattern, $replacement, $desc);
                                                }
                                            }
                                            return $desc;
                                        };

                                        foreach ($recent_logs as $log):
                                             $itemName = '';
                                             $log_aid = (int) ($log['asset_id'] ?? 0);
                                             $isDeletedUser = false;
                                             
                                             if ($log_aid > 0) {
                                                 // IMPROVED DETECTION:
                                                 // 1. Check if type is explicitly set to accessory
                                                 // 2. Check if description says "Aksesuar" (often true for accessory checkin)
                                                 $isAccHint = (strcasecmp($log['item_type'] ?? '', 'accessory') === 0 || strpos($log['event_description'] ?? '', 'Aksesuar') !== false);
                                                 
                                                 $tableName = match ($log['item_type']) {
                                                     'license' => 'asset_licenses',
                                                     'accessory' => 'asset_accessories',
                                                     'consumable' => 'asset_consumables',
                                                     'component' => 'asset_components',
                                                     'user' => 'users',
                                                     'category' => 'asset_categories',
                                                     'model' => 'asset_models',
                                                     'manufacturer' => 'asset_manufacturers',
                                                     'supplier' => 'asset_suppliers',
                                                     'company' => 'asset_companies',
                                                     'department' => 'bolumler',
                                                     'status_label' => 'asset_status_labels',
                                                     'custom_field' => 'inventory_custom_fields',
                                                     default => ($isAccHint ? 'asset_accessories' : 'assets')
                                                 };
                                                 
                                                 $nameField = match ($tableName) {
                                                     'asset_licenses' => 'software_name',
                                                     'users' => 'fullname',
                                                     'bolumler' => 'bolum_adi',
                                                     'inventory_custom_fields' => 'field_label',
                                                     default => 'name'
                                                 };
                                                 try {
                                                     $hasDelCol = ($tableName === 'users' || tableHasColumn($pdo, $tableName, 'deleted_at'));
                                                     $itemData = $pdo->query("SELECT $nameField, " . ($hasDelCol ? 'deleted_at' : 'NULL as deleted_at') . ", id FROM $tableName WHERE id = $log_aid")->fetch();
                                                     
                                                     if (($tableName === 'assets' || empty($itemData)) && $isAccHint) {
                                                         $accMatch = $pdo->query("SELECT name, deleted_at, id FROM asset_accessories WHERE id = $log_aid")->fetch();
                                                         if ($accMatch) {
                                                             $itemData = $accMatch;
                                                             $itemName = $accMatch['name'] ?? '';
                                                             $log['item_type'] = 'accessory';
                                                             $tableName = 'asset_accessories';
                                                         }
                                                     }
                                                     
                                                     if ($itemData) {
                                                         $itemName = $itemData[$nameField] ?? $itemData['name'] ?? $itemName;
                                                         $isItemDeleted = !empty($itemData['deleted_at']);
                                                         if ($tableName === 'users') {
                                                             if ($log['item_type'] !== 'user') $log['item_type'] = 'user';
                                                             if (!empty($itemData['deleted_at'])) $isDeletedUser = true;
                                                         }
                                                         if ($tableName === 'asset_accessories') {
                                                             $log['item_type'] = 'accessory';
                                                         }
                                                     } else {
                                                         $itemTypeLabel = match ($log['item_type']) {
                                                             'asset' => ($isTr ? 'Demirbaş' : 'Asset'),
                                                             'license' => ($isTr ? 'Lisans' : 'License'),
                                                             'accessory' => ($isTr ? 'Aksesuar' : 'Accessory'),
                                                             'consumable' => ($isTr ? 'Sarf' : 'Consumable'),
                                                             'component' => ($isTr ? 'Bileşen' : 'Component'),
                                                             'user' => ($isTr ? 'Kullanıcı' : 'User'),
                                                             'category' => ($isTr ? 'Kategori' : 'Category'),
                                                             'model' => ($isTr ? 'Model' : 'Model'),
                                                             'manufacturer' => ($isTr ? 'Üretici' : 'Manufacturer'),
                                                             'supplier' => ($isTr ? 'Tedarikçi' : 'Supplier'),
                                                             'company' => ($isTr ? 'Şirket' : 'Company'),
                                                             'department' => ($isTr ? 'Departman' : 'Department'),
                                                             'status_label' => ($isTr ? 'Durum Etiketi' : 'Status Label'),
                                                             'custom_field' => ($isTr ? 'Özel Alan' : 'Custom Field'),
                                                             default => $log['item_type']
                                                         };
                                                         $itemName = ($isTr ? "Silinmiş " : "Deleted ") . $itemTypeLabel;
                                                         if ($log['item_type'] === 'user') $isDeletedUser = true;
                                                         $isItemDeleted = true;
                                                     }
                                                 } catch (Exception $e) {}
                                             }

                                            if (empty($itemName) || $itemName === '-' || strpos($itemName, 'Deleted') !== false) {
                                                $tryId = (int)($log['asset_id'] ?: ($log['context_id'] ?: 0));
                                                if ($tryId > 0) {
                                                    try {
                                                        $accCheck = $pdo->query("SELECT name, deleted_at FROM asset_accessories WHERE id = $tryId")->fetch();
                                                        if ($accCheck) {
                                                            $itemName = $accCheck['name'];
                                                            $log['asset_id'] = $tryId;
                                                            $log['item_type'] = 'accessory';
                                                            $isItemDeleted = !empty($accCheck['deleted_at']);
                                                        } else {
                                                            $astCheck = $pdo->query("SELECT name, deleted_at FROM assets WHERE id = $tryId")->fetch();
                                                            if ($astCheck) {
                                                                $itemName = $astCheck['name'];
                                                                $log['asset_id'] = $tryId;
                                                                $log['item_type'] = 'asset';
                                                                $isItemDeleted = !empty($astCheck['deleted_at']);
                                                            }
                                                        }
                                                    } catch (Exception $e) {}
                                                }
                                            }

                                            $isDeleted = ($log['event_type'] == 'deleted' || ($isItemDeleted ?? false));
                                            if ($isDeleted) {
                                                $itemLink = match ($log['item_type'] ?? '') {
                                                    'asset' => 'varlik-detay/' . ($log['asset_id'] ?? 0) . '?view=assets',
                                                    'license' => 'varlik-detay/' . ($log['asset_id'] ?? 0) . '?view=licenses',
                                                    'accessory' => 'varlik-detay/' . ($log['asset_id'] ?? 0) . '?view=accessories',
                                                    'consumable' => 'varlik-detay/' . ($log['asset_id'] ?? 0) . '?view=consumables',
                                                    'component' => 'varlik-detay/' . ($log['asset_id'] ?? 0) . '?view=components',
                                                    'user' => 'kullanici-listele/deleted?highlight_id=' . ($log['asset_id'] ?? 0),
                                                    'category' => 'varliklar?view=predefined&type=categories',
                                                    'model' => 'varliklar?view=predefined&type=models',
                                                    'manufacturer' => 'varliklar?view=predefined&type=manufacturers',
                                                    'supplier' => 'varliklar?view=predefined&type=suppliers',
                                                    'company' => 'varliklar?view=predefined&type=companies',
                                                    'department' => 'varliklar?view=predefined&type=departments',
                                                    'status_label' => 'varliklar?view=predefined&type=status_labels',
                                                    default => '#'
                                                };
                                            } else {
                                                $itemLink = match ($log['item_type'] ?? '') {
                                                    'asset' => 'varlik-detay/' . ($log['asset_id'] ?? 0),
                                                    'license' => 'varliklar?view=licenses&highlight_id=' . ($log['asset_id'] ?? 0),
                                                    'accessory' => (!empty($log['asset_id']) ? 'varlik-detay/' . $log['asset_id'] . '?view=accessories' : 'varliklar?view=accessories'),
                                                    'consumable' => 'varliklar?view=consumables&highlight_id=' . ($log['asset_id'] ?? 0),
                                                    'component' => 'varliklar?view=components&highlight_id=' . ($log['asset_id'] ?? 0),
                                                    'user' => $isDeletedUser ? 'kullanici-listele/deleted?highlight_id=' . ($log['asset_id'] ?? 0) : 'kullanici-listele?highlight_id=' . ($log['asset_id'] ?? 0),
                                                    'category' => 'varliklar?view=predefined&type=categories&highlight_id=' . ($log['asset_id'] ?? 0),
                                                    'model' => 'varliklar?view=predefined&type=models&highlight_id=' . ($log['asset_id'] ?? 0),
                                                    'manufacturer' => 'varliklar?view=predefined&type=manufacturers&highlight_id=' . ($log['asset_id'] ?? 0),
                                                    'supplier' => 'varliklar?view=predefined&type=suppliers&highlight_id=' . ($log['asset_id'] ?? 0),
                                                    'company' => 'varliklar?view=predefined&type=companies&highlight_id=' . ($log['asset_id'] ?? 0),
                                                    'department' => 'varliklar?view=predefined&type=departments&highlight_id=' . ($log['asset_id'] ?? 0),
                                                    'status_label' => 'varliklar?view=predefined&type=status_labels&highlight_id=' . ($log['asset_id'] ?? 0),
                                                    default => '#'
                                                };
                                            }


                                            $pImg = '';
                                            if ($log['user_id']) {
                                                $pImgUser = $pdo->query("SELECT profil_fotosu FROM users WHERE id = " . intval($log['user_id']))->fetch(PDO::FETCH_ASSOC);
                                                $pImg = ($pImgUser && $pImgUser['profil_fotosu']) ? $pImgUser['profil_fotosu'] : '';
                                            }

                                            $performerLink = $log['user_id'] ? 'kullanici-detay/' . $log['user_id'] : '#';
                                            $contextLink = '#';
                                            $tName = '';
                                            if ($log['context_id']) {
                                                $ctxType = $log['context_type'] ?? null;
                                                if ($ctxType === 'user') {
                                                    $tUser = $pdo->query("SELECT fullname FROM users WHERE id = " . intval($log['context_id']))->fetch();
                                                    if ($tUser) {
                                                        $tName = $tUser['fullname'];
                                                        $contextLink = 'kullanici-detay/' . intval($log['context_id']);
                                                    }
                                                } elseif ($ctxType === 'asset') {
                                                    $tAsset = $pdo->query("SELECT name FROM assets WHERE id = " . intval($log['context_id']))->fetch();
                                                    if ($tAsset) {
                                                        $tName = $tAsset['name'];
                                                        $contextLink = 'varlik-detay/' . intval($log['context_id']);
                                                    }
                                                } else {
                                                    // Fallback for old logs
                                                    $tUser = $pdo->query("SELECT fullname FROM users WHERE id = " . intval($log['context_id']))->fetch();
                                                    if ($tUser) {
                                                        $tName = $tUser['fullname'];
                                                        $contextLink = 'kullanici-detay/' . intval($log['context_id']);
                                                    } else {
                                                        $tAsset = $pdo->query("SELECT name FROM assets WHERE id = " . intval($log['context_id']))->fetch();
                                                        if ($tAsset) {
                                                            $tName = $tAsset['name'];
                                                            $contextLink = 'varlik-detay/' . intval($log['context_id']);
                                                        }
                                                    }
                                                }
                                            }

                                            $ev = $eventTranslations[$log['event_type']] ?? ['tr' => strtoupper($log['event_type']), 'en' => strtoupper($log['event_type']), 'class' => 'badge-light', 'icon' => 'fa-info-circle text-muted'];
                                            $evLabel = $isTr ? $ev['tr'] : $ev['en'];
                                            $placeholder = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%23f3f4f6'/%3E%3Ctext x='50%25' y='50%25' dominant-baseline='middle' text-anchor='middle' font-family='sans-serif' font-size='10' fill='%239ca3af'%3E" . ($isTr ? 'Resim Yok' : 'No Image') . "%3C/text%3E%3C/svg%3E";
                                            ?>
                                            <tr>
                                                <td class="small" style="white-space: nowrap; min-width: 80px;">
                                                    <?= date('d.m H:i', strtotime($log['created_at'])) ?>
                                                </td>
                                                <td class="small">
                                                    <div class="d-flex align-items-center">
                                                        <?php 
                                                         if (empty($log['user_id'])) {
                                                             $imgSrc = $logoUrl;
                                                         } else {
                                                             if (empty($pImg)) {
                                                                 $imgSrc = $placeholder;
                                                             } elseif (strpos($pImg, 'http') === 0) {
                                                                 $imgSrc = $pImg;
                                                             } elseif (strpos($pImg, 'dist/img/avatars/') !== false) {
                                                                 $imgSrc = preg_replace('/^.*(dist\/img\/avatars\/[a-zA-Z0-9_\-\.]+)/', '$1', $pImg);
                                                             } else {
                                                                 $imgSrc = 'public/uploads/profil/' . $pImg;
                                                             }
                                                         }
                                                         ?>
                                                         <img src="<?= $imgSrc ?>" class="rounded-circle mr-2 border shadow-sm"
                                                             style="width:24px; height:24px; object-fit:cover;"
                                                             onerror="this.src='<?= empty($log['user_id']) ? 'public/logo.png' : $placeholder ?>'">
                                                        <a href="<?= $performerLink ?>"
                                                            class="text-dark font-weight-bold hover-underline"
                                                            style="text-decoration: none;">
                                                            <?= htmlspecialchars($log['fullname'] ?: $ui['system']) ?>
                                                        </a>
                                                    </div>
                                                </td>
                                                <td><span class="event-badge <?= $ev['class'] ?>">
                                                        <?= $evLabel ?>
                                                    </span></td>
                                                <td class="small">
                                                    <a href="<?= $itemLink ?>" class="text-info font-weight-bold hover-underline"
                                                        style="text-decoration: none;">
                                                        <?= htmlspecialchars($itemName ?: '-') ?>
                                                    </a>
                                                </td>
                                                <td class="small">
                                                    <div class="d-flex align-items-center">
                                                        <?php if ($tName): ?>
                                                            <a href="<?= $contextLink ?>"
                                                                class="text-primary font-weight-bold hover-underline d-flex align-items-center"
                                                                style="text-decoration: none;">
                                                                <i class="fas fa-user-tag mr-1" style="font-size:10px;"></i>
                                                                <?= htmlspecialchars($tName) ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted opacity-50">-</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php if (!empty($log['event_description'])): ?>
                                                <tr style="border-top:none;">
                                                    <td colspan="3" style="border-top:none;"></td>
                                                    <td colspan="2" class="py-0 px-4" style="border-top:none;">
                                                        <div class="text-muted xsmall mb-2"
                                                            style="font-size:10px; font-style:italic; line-height:1.2;">
                                                            <i class="fas fa-comment-dots mr-1"></i>
                                                            <?= htmlspecialchars($translateLog($log['event_description'], $isTr)) ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="ds-card mb-4" style="height: calc(100% - 1.5rem);">
                            <div class="p-4 border-bottom bg-white">
                                <h5 class="m-0 font-weight-bold text-dark"><i class="fas fa-chart-pie mr-2 text-primary"></i>
                                    <?= $ui['assets_by_status'] ?>
                                </h5>
                            </div>
                            <div class="card-body d-flex align-items-center justify-content-center" style="min-height: 350px;">
                                <canvas id="statusChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-12">
                        <div class="ds-card mb-4 overflow-hidden" style="border-radius:15px; border:none; box-shadow:0 10px 30px rgba(0,0,0,0.05); background:#fff;">
                            <div class="p-4 border-bottom bg-white d-flex align-items-center justify-content-between">
                                <h5 class="m-0 font-weight-bold text-dark">
                                    <i class="fas fa-th-large mr-2 text-primary"></i>
                                    <?= $isTr ? 'Kategori Bazlı Detaylı Dağılım' : 'Detailed Category Distribution' ?>
                                </h5>
                                <div class="text-xs text-muted font-weight-600">
                                    <?= $isTr ? 'Toplam ' . count($catDist) . ' Kategori' : 'Total ' . count($catDist) . ' Categories' ?>
                                </div>
                            </div>
                            
                            <style>
                                /* CSS REDESIGN FOR INVENTORY CATEGORY DISTRIBUTION */
                                .category-filter-tabs .btn-filter-tab {
                                    border: 1px solid #e2e8f0;
                                    background: #fff;
                                    color: #475569;
                                    font-weight: 600;
                                    font-size: 12.5px;
                                    border-radius: 20px;
                                    padding: 6px 14px;
                                    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                                    cursor: pointer;
                                    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
                                }
                                .category-filter-tabs .btn-filter-tab:hover {
                                    background: #f8fafc;
                                    border-color: #cbd5e1;
                                    color: #0f172a;
                                    transform: translateY(-1px);
                                }
                                .category-filter-tabs .btn-filter-tab.active {
                                    background: #1e293b;
                                    border-color: #1e293b;
                                    color: #fff;
                                    box-shadow: 0 4px 6px -1px rgba(30, 41, 59, 0.2);
                                }
                                .category-filter-tabs .btn-filter-tab.active i {
                                    color: #fff !important;
                                }

                                /* View toggle styling */
                                .btn-view-toggle {
                                    border: none;
                                    background: transparent;
                                    color: #64748b;
                                    transition: all 0.2s;
                                    font-size: 12px;
                                }
                                .btn-view-toggle.active {
                                    background: #fff;
                                    color: #0f172a;
                                    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
                                }

                                /* Search Focus Effect */
                                #categorySearchInput:focus {
                                    border-color: #6366f1;
                                    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
                                    background: #fff;
                                }
                                #categorySearchInput::placeholder {
                                    color: #94a3b8;
                                }
                                .clear-search-icon:hover {
                                    color: #64748b !important;
                                }

                                /* Container states */
                                .category-grid-container, .category-grouped-container, .category-list-container {
                                    transition: opacity 0.2s ease;
                                }
                                .category-grouped-container.d-none, .category-grid-container.d-none, .category-list-container.d-none {
                                    display: none !important;
                                }

                                /* Unified Cards Layout */
                                .category-cards-grid {
                                    display: grid;
                                    grid-template-columns: repeat(4, 1fr);
                                    gap: 16px;
                                }
                                @media (max-width: 1400px) { .category-cards-grid { grid-template-columns: repeat(3, 1fr); } }
                                @media (max-width: 992px) { .category-cards-grid { grid-template-columns: repeat(2, 1fr); } }
                                @media (max-width: 576px) { .category-cards-grid { grid-template-columns: 1fr; } }

                                .category-modern-card {
                                    background: #fff;
                                    border-radius: 12px;
                                    border: 1px solid #e2e8f0;
                                    padding: 12px 14px;
                                    display: flex;
                                    align-items: center;
                                    justify-content: space-between;
                                    text-decoration: none !important;
                                    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
                                    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
                                    cursor: pointer;
                                }
                                .category-modern-card:hover {
                                    transform: translateY(-3px);
                                    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);
                                    background: #fff;
                                }

                                /* Type specific borders and glows */
                                .category-modern-card.type-asset { border-left: 4px solid #3b82f6; }
                                .category-modern-card.type-asset:hover { border-color: #2563eb; box-shadow: 0 8px 20px rgba(59, 130, 246, 0.08); background: rgba(59, 130, 246, 0.01); }
                                
                                .category-modern-card.type-license { border-left: 4px solid #f59e0b; }
                                .category-modern-card.type-license:hover { border-color: #d97706; box-shadow: 0 8px 20px rgba(245, 158, 11, 0.08); background: rgba(245, 158, 11, 0.01); }

                                .category-modern-card.type-consumable { border-left: 4px solid #ef4444; }
                                .category-modern-card.type-consumable:hover { border-color: #dc2626; box-shadow: 0 8px 20px rgba(239, 68, 68, 0.08); background: rgba(239, 68, 68, 0.01); }

                                .category-modern-card.type-accessory { border-left: 4px solid #14b8a6; }
                                .category-modern-card.type-accessory:hover { border-color: #0d9488; box-shadow: 0 8px 20px rgba(20, 184, 166, 0.08); background: rgba(20, 184, 166, 0.01); }

                                .category-modern-card.type-component { border-left: 4px solid #22c55e; }
                                .category-modern-card.type-component:hover { border-color: #16a34a; box-shadow: 0 8px 20px rgba(34, 197, 94, 0.08); background: rgba(34, 197, 94, 0.01); }

                                /* Icon Wrapper styling */
                                .category-modern-card .card-icon-box {
                                    width: 38px;
                                    height: 38px;
                                    border-radius: 10px;
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    font-size: 14px;
                                    margin-right: 12px;
                                    flex-shrink: 0;
                                    transition: transform 0.25s ease;
                                }
                                .category-modern-card:hover .card-icon-box {
                                    transform: scale(1.08);
                                }
                                .icon-box-asset { background: rgba(59, 130, 246, 0.08); color: #2563eb; }
                                .icon-box-license { background: rgba(245, 158, 11, 0.08); color: #d97706; }
                                .icon-box-consumable { background: rgba(239, 68, 68, 0.08); color: #dc2626; }
                                .icon-box-accessory { background: rgba(20, 184, 166, 0.08); color: #0d9488; }
                                .icon-box-component { background: rgba(34, 197, 94, 0.08); color: #16a34a; }

                                /* Text & Info */
                                .category-modern-card .card-details {
                                    flex-grow: 1;
                                    min-width: 0;
                                }
                                .category-modern-card .card-name {
                                    font-weight: 600;
                                    color: #1e293b;
                                    font-size: 13.5px;
                                    line-height: 1.3;
                                    margin-bottom: 2px;
                                    display: block;
                                }
                                .category-modern-card .card-type-label {
                                    font-size: 9px;
                                    font-weight: 700;
                                    letter-spacing: 0.5px;
                                    text-transform: uppercase;
                                    color: #94a3b8;
                                }

                                /* Badges and Counts */
                                .category-modern-card .card-count-badge {
                                    font-size: 11.5px;
                                    font-weight: 700;
                                    padding: 5px 10px;
                                    border-radius: 20px;
                                    transition: transform 0.2s;
                                }
                                .category-modern-card:hover .card-count-badge {
                                    transform: scale(1.05);
                                }

                                /* Custom Scrollbars for Grouped Cards list */
                                .category-type-card .category-list::-webkit-scrollbar {
                                    width: 5px;
                                }
                                .category-type-card .category-list::-webkit-scrollbar-track {
                                    background: rgba(241, 245, 249, 0.5);
                                }
                                .category-type-card .category-list::-webkit-scrollbar-thumb {
                                    background: rgba(203, 213, 225, 0.7);
                                    border-radius: 4px;
                                }
                                .category-type-card .category-list::-webkit-scrollbar-thumb:hover {
                                    background: rgba(148, 163, 184, 0.9);
                                }

                                /* Grouped Mode Item Enhancements */
                                .category-type-card .category-item {
                                    position: relative;
                                    overflow: hidden;
                                }
                                .category-type-card .category-item .distribution-bar {
                                    position: absolute;
                                    bottom: 0;
                                    left: 0;
                                    height: 3px;
                                    background: currentColor;
                                    opacity: 0.15;
                                    transition: height 0.25s ease;
                                }
                                .category-type-card .category-item:hover .distribution-bar {
                                    height: 5px;
                                    opacity: 0.3;
                                }

                                /* Empty state with animation */
                                .category-empty-state {
                                    padding: 60px 20px;
                                    text-align: center;
                                    background: #fff;
                                    border-radius: 12px;
                                    border: 1px dashed #e2e8f0;
                                    color: #64748b;
                                    animation: fadeIn 0.3s ease;
                                }
                                .category-empty-state i {
                                    font-size: 36px;
                                    color: #cbd5e1;
                                    margin-bottom: 12px;
                                }

                                /* Category Scroll Box */
                                .category-grid-scroll-box {
                                    max-height: 420px;
                                    overflow-y: auto;
                                    padding-right: 6px;
                                }
                                .category-grid-scroll-box::-webkit-scrollbar {
                                    width: 5px;
                                }
                                .category-grid-scroll-box::-webkit-scrollbar-track {
                                    background: rgba(241, 245, 249, 0.5);
                                }
                                .category-grid-scroll-box::-webkit-scrollbar-thumb {
                                    background: rgba(203, 213, 225, 0.7);
                                    border-radius: 4px;
                                }
                                .category-grid-scroll-box::-webkit-scrollbar-thumb:hover {
                                    background: rgba(148, 163, 184, 0.9);
                                }

                                .inventory-category-grid {
                                    display: grid;
                                    grid-template-columns: repeat(5, 1fr);
                                    gap: 20px;
                                }
                                @media (max-width: 1300px) {
                                    .inventory-category-grid {
                                        grid-template-columns: repeat(3, 1fr);
                                    }
                                }
                                @media (max-width: 992px) {
                                    .inventory-category-grid {
                                        grid-template-columns: repeat(2, 1fr);
                                    }
                                }
                                @media (max-width: 576px) {
                                    .inventory-category-grid {
                                        grid-template-columns: 1fr;
                                    }
                                }

                                .category-type-card {
                                    background: #fff;
                                    border-radius: 14px;
                                    border: 1px solid #e2e8f0;
                                    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
                                    overflow: hidden;
                                    transition: transform 0.25s ease, box-shadow 0.25s ease;
                                    display: flex;
                                    flex-direction: column;
                                    min-height: 250px;
                                }
                                .category-type-card:hover {
                                    transform: translateY(-4px);
                                    box-shadow: 0 12px 20px -5px rgba(0, 0, 0, 0.08);
                                }
                                .category-type-card .card-type-header {
                                    padding: 16px 20px;
                                    font-weight: 700;
                                    font-size: 12px;
                                    letter-spacing: 0.8px;
                                    text-transform: uppercase;
                                    display: flex;
                                    align-items: center;
                                    justify-content: space-between;
                                    border-bottom: 1px solid #f1f5f9;
                                }
                                .type-header-asset { background: rgba(59, 130, 246, 0.06); color: #1d4ed8; }
                                .type-header-license { background: rgba(245, 158, 11, 0.06); color: #b45309; }
                                .type-header-consumable { background: rgba(239, 68, 68, 0.06); color: #b91c1c; }
                                .type-header-accessory { background: rgba(20, 184, 166, 0.06); color: #0f766e; }
                                .type-header-component { background: rgba(34, 197, 94, 0.06); color: #15803d; }

                                .category-type-card .category-list {
                                    list-style: none;
                                    margin: 0;
                                    padding: 15px;
                                    flex-grow: 1;
                                    display: flex;
                                    flex-direction: column;
                                    gap: 10px;
                                    max-height: 400px;
                                    overflow-y: auto;
                                    scrollbar-width: thin;
                                }
                                .category-type-card .category-item {
                                    display: flex;
                                    align-items: center;
                                    justify-content: space-between;
                                    padding: 10px 12px;
                                    border-radius: 10px;
                                    background: #f8fafc;
                                    border: 1px solid #f1f5f9;
                                    font-size: 13px;
                                    font-weight: 600;
                                    color: #334155;
                                    transition: background 0.2s ease, border-color 0.2s ease;
                                    cursor: pointer;
                                    text-decoration: none !important;
                                }
                                .category-type-card .category-item:hover {
                                    background: #f1f5f9;
                                    border-color: #cbd5e1;
                                }
                                .category-type-card .category-name {
                                    white-space: nowrap;
                                    overflow: hidden;
                                    text-overflow: ellipsis;
                                    margin-right: 8px;
                                }
                                .category-type-card .empty-state {
                                    padding: 40px 20px;
                                    text-align: center;
                                    color: #94a3b8;
                                    font-size: 12px;
                                    display: flex;
                                    flex-direction: column;
                                    align-items: center;
                                    justify-content: center;
                                    gap: 10px;
                                    flex-grow: 1;
                                }
                                .category-type-card .empty-state i {
                                    font-size: 24px;
                                    opacity: 0.4;
                                }
                                
                                @keyframes fadeIn {
                                    from { opacity: 0; transform: translateY(10px); }
                                    to { opacity: 1; transform: translateY(0); }
                                }

                                /* DARK MODE OVERRIDES FOR CATEGORY DISTRIBUTION PANEL */
                                body.dark-mode .category-panel-body {
                                    background: #161a22 !important;
                                }
                                body.dark-mode .category-controls-container {
                                    background: #212832 !important;
                                    border-top-color: #2d343c !important;
                                    border-bottom-color: #2d343c !important;
                                }
                                body.dark-mode .category-controls-container .btn-group {
                                    background: #1a1f26 !important;
                                }
                                body.dark-mode .btn-view-toggle {
                                    color: #94a3b8;
                                }
                                body.dark-mode .btn-view-toggle.active {
                                    background: #2d3748;
                                    color: #f8fafc;
                                    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                                }
                                body.dark-mode .category-filter-tabs .btn-filter-tab {
                                    background: #1a1f26;
                                    border-color: #2d343c;
                                    color: #94a3b8;
                                }
                                body.dark-mode .category-filter-tabs .btn-filter-tab:hover {
                                    background: #2d343c;
                                    border-color: #4b5563;
                                    color: #f1f5f9;
                                }
                                body.dark-mode .category-filter-tabs .btn-filter-tab.active {
                                    background: #3b82f6;
                                    border-color: #3b82f6;
                                    color: #fff;
                                }
                                body.dark-mode #categorySearchInput {
                                    background: #1a1f26;
                                    border-color: #2d343c;
                                    color: #f1f5f9;
                                }
                                body.dark-mode #categorySearchInput:focus {
                                    border-color: #3b82f6;
                                    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
                                    background: #1a1f26;
                                }
                                body.dark-mode .category-modern-card {
                                    background: #212832;
                                    border-color: #2d343c;
                                }
                                body.dark-mode .category-modern-card:hover {
                                    background: #2d343c;
                                    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3);
                                }
                                body.dark-mode .category-modern-card.type-asset:hover { background: rgba(59, 130, 246, 0.05); }
                                body.dark-mode .category-modern-card.type-license:hover { background: rgba(245, 158, 11, 0.05); }
                                body.dark-mode .category-modern-card.type-consumable:hover { background: rgba(239, 68, 68, 0.05); }
                                body.dark-mode .category-modern-card.type-accessory:hover { background: rgba(20, 184, 166, 0.05); }
                                body.dark-mode .category-modern-card.type-component:hover { background: rgba(34, 197, 94, 0.05); }
                                body.dark-mode .category-modern-card .card-name {
                                    color: #f1f5f9;
                                }
                                body.dark-mode .category-type-card {
                                    background: #212832;
                                    border-color: #2d343c;
                                }
                                body.dark-mode .category-type-card .category-item {
                                    background: #1a1f26;
                                    border-color: #2d343c;
                                    color: #cbd5e1;
                                }
                                body.dark-mode .category-type-card .category-item:hover {
                                    background: #2d343c;
                                    border-color: #4b5563;
                                }
                                body.dark-mode .category-empty-state {
                                    background: #212832;
                                    border-color: #2d343c;
                                    color: #cbd5e1;
                                }
                                body.dark-mode .category-type-card .empty-state {
                                    color: #64748b;
                                }
                                body.dark-mode .type-header-asset { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
                                body.dark-mode .type-header-license { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
                                body.dark-mode .type-header-consumable { background: rgba(239, 68, 68, 0.15); color: #f87171; }
                                body.dark-mode .type-header-accessory { background: rgba(20, 184, 166, 0.15); color: #38bdf8; }
                                body.dark-mode .type-header-component { background: rgba(34, 197, 94, 0.15); color: #4ade80; }
                                body.dark-mode .group-count-badge {
                                    background-color: #1a1f26 !important;
                                    color: #cbd5e1 !important;
                                }
                                /* ===== NEW: Grouped view items with progress fill ===== */
                                .cat-item-bar {
                                    position: absolute; left: 0; top: 0; bottom: 0;
                                    border-radius: inherit; opacity: 0.07;
                                    pointer-events: none; transition: width 0.4s ease;
                                }
                                .cat-rank-num {
                                    font-size: 10px; color: #94a3b8; font-weight: 700;
                                    margin-right: 6px; min-width: 16px; text-align: right;
                                    position: relative; z-index: 1; flex-shrink: 0;
                                }
                                .cat-item-badge { position: relative; z-index: 1; flex-shrink: 0; }

                                /* Show More button */
                                .cat-show-more-btn {
                                    width: 100%; padding: 8px 12px; font-size: 11.5px; font-weight: 600;
                                    color: #64748b; background: #f8fafc;
                                    border: none; border-top: 1px solid #f1f5f9;
                                    cursor: pointer; transition: background 0.15s, color 0.15s;
                                    display: flex; align-items: center; justify-content: center; gap: 6px;
                                }
                                .cat-show-more-btn:hover { background: #f1f5f9; color: #334155; }
                                .cat-show-more-btn i { transition: transform 0.2s; }
                                .cat-show-more-btn.expanded i { transform: rotate(180deg); }

                                /* ===== NEW: Compact List View ===== */
                                .cat-list-section {
                                    background: #fff; border-radius: 12px;
                                    border: 1px solid #e2e8f0; margin-bottom: 8px;
                                    overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.03);
                                }
                                .cat-list-section-header {
                                    padding: 11px 16px;
                                    font-size: 11px; font-weight: 700;
                                    letter-spacing: 0.6px; text-transform: uppercase;
                                    display: flex; align-items: center; justify-content: space-between;
                                    cursor: pointer; user-select: none;
                                    transition: filter 0.15s;
                                }
                                .cat-list-section-header:hover { filter: brightness(0.97); }
                                .cat-list-section-header i.toggle-icon { transition: transform 0.2s; }
                                .cat-list-section-header.collapsed i.toggle-icon { transform: rotate(-90deg); }
                                .cat-list-table {
                                    width: 100%; border-collapse: collapse;
                                }
                                .cat-list-table tr {
                                    border-bottom: 1px solid #f1f5f9;
                                    transition: background 0.1s;
                                }
                                .cat-list-table tr:last-child { border-bottom: none; }
                                .cat-list-table tr:hover td { background: #f8fafc; }
                                .cat-list-table td {
                                    padding: 7px 14px; vertical-align: middle; font-size: 12.5px;
                                }
                                .cat-list-table td.cat-rank-col {
                                    color: #94a3b8; font-weight: 700; font-size: 11px;
                                    width: 28px; text-align: center;
                                }
                                .cat-list-table td.cat-name-cell {
                                    font-weight: 600; color: #1e293b;
                                }
                                .cat-list-table td.cat-name-cell a {
                                    color: inherit; text-decoration: none;
                                }
                                .cat-list-table td.cat-name-cell a:hover { color: #6366f1; }
                                .cat-list-table td.cat-bar-cell { width: 38%; }
                                .cat-list-table td.cat-count-cell {
                                    text-align: right; font-weight: 700;
                                    color: #374151; width: 55px; font-size: 13px;
                                }
                                .cat-mini-bar-wrap {
                                    height: 5px; background: #f1f5f9;
                                    border-radius: 4px; overflow: hidden;
                                }
                                .cat-mini-bar-fill {
                                    height: 100%; border-radius: 4px;
                                    transition: width 0.5s ease;
                                }
                                .cat-list-show-more {
                                    text-align: center; padding: 8px 14px;
                                    font-size: 12px; font-weight: 600; color: #6366f1;
                                    cursor: pointer; border-top: 1px solid #f1f5f9;
                                    transition: background 0.15s;
                                }
                                .cat-list-show-more:hover { background: #f8fafc; }

                                /* Dark mode extras */
                                body.dark-mode .cat-show-more-btn { background: #1a1f26; border-color: #2d343c; color: #64748b; }
                                body.dark-mode .cat-show-more-btn:hover { background: #2d343c; color: #cbd5e1; }
                                body.dark-mode .cat-list-section { background: #212832; border-color: #2d343c; }
                                body.dark-mode .cat-list-table tr { border-color: #2d343c; }
                                body.dark-mode .cat-list-table tr:hover td { background: #2d343c; }
                                body.dark-mode .cat-list-table td.cat-name-cell { color: #f1f5f9; }
                                body.dark-mode .cat-list-table td.cat-count-cell { color: #cbd5e1; }
                                body.dark-mode .cat-mini-bar-wrap { background: #2d343c; }
                                body.dark-mode .cat-list-show-more { border-color: #2d343c; color: #818cf8; }
                                body.dark-mode .cat-list-show-more:hover { background: #2d343c; }
                            </style>

                            <?php
                            $types_map = [
                                'asset' => [
                                    'title' => $isTr ? 'Demirbaşlar' : 'Assets',
                                    'icon' => 'fa-laptop-medical',
                                    'class' => 'type-header-asset',
                                    'badge' => 'badge-primary',
                                    'view' => 'assets'
                                ],
                                'license' => [
                                    'title' => $isTr ? 'Lisanslar' : 'Licenses',
                                    'icon' => 'fa-key',
                                    'class' => 'type-header-license',
                                    'badge' => 'badge-warning text-dark',
                                    'view' => 'licenses'
                                ],
                                'consumable' => [
                                    'title' => $isTr ? 'Sarf Malzemeler' : 'Consumables',
                                    'icon' => 'fa-box-open',
                                    'class' => 'type-header-consumable',
                                    'badge' => 'badge-danger',
                                    'view' => 'consumables'
                                ],
                                'accessory' => [
                                    'title' => $isTr ? 'Aksesuarlar' : 'Accessories',
                                    'icon' => 'fa-keyboard',
                                    'class' => 'type-header-accessory',
                                    'badge' => 'badge-info',
                                    'view' => 'accessories'
                                ],
                                'component' => [
                                    'title' => $isTr ? 'Bileşenler' : 'Components',
                                    'icon' => 'fa-microchip',
                                    'class' => 'type-header-component',
                                    'badge' => 'badge-success',
                                    'view' => 'components'
                                ]
                            ];

                            $groupedCats = [
                                'asset' => [],
                                'license' => [],
                                'consumable' => [],
                                'accessory' => [],
                                'component' => []
                            ];

                            foreach ($catDist as $cd) {
                                if (isset($groupedCats[$cd['type']])) {
                                    $groupedCats[$cd['type']][] = $cd;
                                }
                            }
                            ?>

                            <!-- Interactive Dashboard Control Panel -->
                            <div class="category-controls-container p-4 border-bottom bg-white d-flex flex-wrap align-items-center justify-content-between" style="gap: 15px; border-top: 1px solid #f1f5f9;">
                                <!-- Left Section: Filters -->
                                <div class="category-filter-tabs d-flex flex-wrap align-items-center" style="gap: 8px;">
                                    <button class="btn btn-sm btn-filter-tab active" data-type="all">
                                        <i class="fas fa-border-all mr-1.5"></i> <?= $isTr ? 'Tümü' : 'All' ?>
                                    </button>
                                    <button class="btn btn-sm btn-filter-tab" data-type="asset">
                                        <i class="fas fa-laptop-medical mr-1.5 text-primary"></i> <?= $isTr ? 'Demirbaş' : 'Assets' ?>
                                    </button>
                                    <button class="btn btn-sm btn-filter-tab" data-type="license">
                                        <i class="fas fa-key mr-1.5 text-warning"></i> <?= $isTr ? 'Lisans' : 'Licenses' ?>
                                    </button>
                                    <button class="btn btn-sm btn-filter-tab" data-type="consumable">
                                        <i class="fas fa-box-open mr-1.5 text-danger"></i> <?= $isTr ? 'Sarf' : 'Consumables' ?>
                                    </button>
                                    <button class="btn btn-sm btn-filter-tab" data-type="accessory">
                                        <i class="fas fa-keyboard mr-1.5 text-info"></i> <?= $isTr ? 'Aksesuar' : 'Accessories' ?>
                                    </button>
                                    <button class="btn btn-sm btn-filter-tab" data-type="component">
                                        <i class="fas fa-microchip mr-1.5 text-success"></i> <?= $isTr ? 'Bileşen' : 'Components' ?>
                                    </button>
                                </div>
                                
                                <!-- Right Section: Search & View Switcher -->
                                <div class="d-flex align-items-center flex-wrap" style="gap: 12px; flex-grow: 1; max-width: 450px; justify-content: flex-end;">
                                    <!-- Dynamic Search Bar -->
                                    <div class="search-input-wrapper position-relative flex-grow-1" style="max-width: 260px; min-width: 160px;">
                                        <i class="fas fa-search position-absolute" style="left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px;"></i>
                                        <input type="text" id="categorySearchInput" class="form-control form-control-sm pl-5 pr-4" placeholder="<?= $isTr ? 'Kategorilerde ara...' : 'Search categories...' ?>" style="border-radius: 20px; font-size: 13px; height: 34px; border: 1px solid #cbd5e1; background: #fff; transition: all 0.2s;">
                                        <i class="fas fa-times-circle position-absolute" id="clearCategorySearch" style="right: 12px; top: 50%; transform: translateY(-50%); color: #cbd5e1; cursor: pointer; display: none; font-size: 13px;"></i>
                                    </div>
                                    
                                    <!-- 3-mode Layout Switcher -->
                                    <div class="btn-group btn-group-sm rounded-pill p-1" style="background: #e2e8f0; height: 34px; border: none;">
                                        <button class="btn btn-sm btn-view-toggle rounded-pill px-3 active" id="btnViewCards" title="<?= $isTr ? 'Kart Görünümü' : 'Grid View' ?>">
                                            <i class="fas fa-th-large"></i>
                                        </button>
                                        <button class="btn btn-sm btn-view-toggle rounded-pill px-3" id="btnViewGrouped" title="<?= $isTr ? 'Grup Görünümü' : 'Grouped View' ?>">
                                            <i class="fas fa-columns"></i>
                                        </button>
                                        <button class="btn btn-sm btn-view-toggle rounded-pill px-3" id="btnViewList" title="<?= $isTr ? 'Liste Görünümü' : 'List View' ?>">
                                            <i class="fas fa-list"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="card-body p-4 category-panel-body" style="background:#f8fafc;">
                                
                                <!-- VIEW 1: DYNAMIC GRID CARDS -->
                                <div class="category-grid-container" id="categoryCardsWrapper">
                                    <div class="category-grid-scroll-box">
                                        <div class="category-cards-grid">
                                            <?php
                                            foreach ($catDist as $c):
                                                $meta = $types_map[$c['type']] ?? null;
                                                if (!$meta) continue;
                                                
                                                $count = match($c['type']) {
                                                    'asset' => $c['asset_count'],
                                                    'license' => $c['lic_count'],
                                                    'consumable' => $c['con_count'],
                                                    'accessory' => $c['acc_count'],
                                                    'component' => $c['comp_count'],
                                                    default => 0
                                                };
                                                
                                                $typeLabel = match($c['type']) {
                                                    'asset' => $isTr ? 'DEMİRBAŞ' : 'ASSET',
                                                    'license' => $isTr ? 'LİSANS' : 'LICENSE',
                                                    'consumable' => $isTr ? 'SARF' : 'CONSUMABLE',
                                                    'accessory' => $isTr ? 'AKSESUAR' : 'ACCESSORY',
                                                    'component' => $isTr ? 'BİLEŞEN' : 'COMPONENT',
                                                    default => ''
                                                };
                                                ?>
                                                <a href="varliklar?view=<?= $meta['view'] ?>&category_id=<?= $c['id'] ?>" 
                                                   class="category-modern-card type-<?= $c['type'] ?>" 
                                                   data-type="<?= $c['type'] ?>" 
                                                   data-name="<?= htmlspecialchars(mb_strtolower($c['name'], 'UTF-8')) ?>">
                                                    
                                                    <div class="d-flex align-items-center min-w-0 flex-grow-1">
                                                        <div class="card-icon-box icon-box-<?= $c['type'] ?>">
                                                            <i class="fas <?= $meta['icon'] ?>"></i>
                                                        </div>
                                                        <div class="card-details">
                                                            <span class="card-name text-truncate" title="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></span>
                                                            <span class="card-type-label"><?= $typeLabel ?></span>
                                                        </div>
                                                    </div>
                                                    
                                                    <span class="badge badge-pill <?= $meta['badge'] ?> card-count-badge ml-2"><?= $count ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- VIEW 2: GROUPED COLUMNS VIEW -->
                                <div class="category-grouped-container d-none" id="categoryGroupedWrapper">
                                    <div class="inventory-category-grid">
                                        <?php
                                        foreach ($types_map as $typeKey => $meta):
                                            $cats = $groupedCats[$typeKey];
                                            $cat_count = count($cats);
                                            
                                            // Sort by count DESC
                                            usort($cats, function($a, $b) use ($typeKey) {
                                                $countKey = match($typeKey) {
                                                    'asset' => 'asset_count', 'license' => 'lic_count',
                                                    'consumable' => 'con_count', 'accessory' => 'acc_count',
                                                    'component' => 'comp_count', default => 'asset_count'
                                                };
                                                return ($b[$countKey] ?? 0) <=> ($a[$countKey] ?? 0);
                                            });

                                            // Find max count for progress bars
                                            $maxInGroup = 1;
                                            foreach ($cats as $c) {
                                                $c_count = match($c['type']) {
                                                    'asset' => $c['asset_count'], 'license' => $c['lic_count'],
                                                    'consumable' => $c['con_count'], 'accessory' => $c['acc_count'],
                                                    'component' => $c['comp_count'], default => 0
                                                };
                                                if ($c_count > $maxInGroup) $maxInGroup = $c_count;
                                            }

                                            $barColors = [
                                                'asset' => '#3b82f6', 'license' => '#f59e0b',
                                                'consumable' => '#ef4444', 'accessory' => '#14b8a6', 'component' => '#22c55e'
                                            ];
                                            $barColor = $barColors[$typeKey] ?? '#6366f1';
                                            $showLimit = 8; // Show first 8 per group
                                            ?>
                                            <div class="category-type-card" data-column-type="<?= $typeKey ?>">
                                                <div class="card-type-header <?= $meta['class'] ?>">
                                                    <span><i class="fas <?= $meta['icon'] ?> mr-2"></i><?= $meta['title'] ?></span>
                                                    <span class="badge badge-light px-2 py-1 group-count-badge" style="font-size:10px; border-radius:10px;"><?= $cat_count ?></span>
                                                </div>
                                                
                                                <?php if (empty($cats)): ?>
                                                    <div class="empty-state">
                                                        <i class="fas <?= $meta['icon'] ?>"></i>
                                                        <span><?= $isTr ? 'Kategori Yok' : 'No Categories' ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="category-list">
                                                        <?php foreach ($cats as $idx => $c):
                                                            $count = match($c['type']) {
                                                                'asset' => $c['asset_count'], 'license' => $c['lic_count'],
                                                                'consumable' => $c['con_count'], 'accessory' => $c['acc_count'],
                                                                'component' => $c['comp_count'], default => 0
                                                            };
                                                            $proportion = min(100, max(4, round(($count / $maxInGroup) * 100)));
                                                            $isHidden = ($idx >= $showLimit) ? 'style="display:none;"' : '';
                                                            ?>
                                                            <a href="varliklar?view=<?= $meta['view'] ?>&category_id=<?= $c['id'] ?>" 
                                                               class="category-item category-item-grouped" 
                                                               data-type="<?= $c['type'] ?>" 
                                                               data-name="<?= htmlspecialchars(mb_strtolower($c['name'], 'UTF-8')) ?>"
                                                               data-idx="<?= $idx ?>"
                                                               <?= $isHidden ?>>
                                                                <!-- Background fill bar -->
                                                                <div class="cat-item-bar" style="width:<?= $proportion ?>%; background:<?= $barColor ?>;"></div>
                                                                <span class="cat-rank-num"><?= $idx + 1 ?></span>
                                                                <span class="category-name text-truncate" title="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></span>
                                                                <span class="badge badge-pill <?= $meta['badge'] ?> px-2 py-1 cat-item-badge" style="font-size:10.5px;"><?= $count ?></span>
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <?php if ($cat_count > $showLimit): ?>
                                                    <button class="cat-show-more-btn" data-type="<?= $typeKey ?>" data-showing="<?= $showLimit ?>" data-total="<?= $cat_count ?>">
                                                        <i class="fas fa-chevron-down"></i>
                                                        <span><?= $isTr ? ($cat_count - $showLimit) . ' kategori daha' : ($cat_count - $showLimit) . ' more' ?></span>
                                                    </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- VIEW 3: COMPACT LIST VIEW (NEW) -->
                                <div class="category-list-container d-none" id="categoryListWrapper">
                                    <?php
                                    foreach ($types_map as $typeKey => $meta):
                                        $cats = $groupedCats[$typeKey];
                                        if (empty($cats)) continue;
                                        usort($cats, function($a, $b) use ($typeKey) {
                                            $ck = match($typeKey) { 'asset'=>'asset_count','license'=>'lic_count','consumable'=>'con_count','accessory'=>'acc_count','component'=>'comp_count',default=>'asset_count' };
                                            return ($b[$ck]??0)<=>($a[$ck]??0);
                                        });
                                        $totalCount = array_sum(array_map(fn($c) => match($typeKey){'asset'=>$c['asset_count'],'license'=>$c['lic_count'],'consumable'=>$c['con_count'],'accessory'=>$c['acc_count'],'component'=>$c['comp_count'],default=>0}, $cats));
                                        $maxCount = max(1, ...array_map(fn($c) => match($typeKey){'asset'=>$c['asset_count'],'license'=>$c['lic_count'],'consumable'=>$c['con_count'],'accessory'=>$c['acc_count'],'component'=>$c['comp_count'],default=>0}, $cats));
                                        $barColors = ['asset'=>'#3b82f6','license'=>'#f59e0b','consumable'=>'#ef4444','accessory'=>'#14b8a6','component'=>'#22c55e'];
                                        $bc = $barColors[$typeKey] ?? '#6366f1';
                                        $listShowLimit = 10;
                                        ?>
                                        <div class="cat-list-section" data-list-type="<?= $typeKey ?>">
                                            <div class="cat-list-section-header <?= $meta['class'] ?>" onclick="toggleCatSection(this)">
                                                <span><i class="fas <?= $meta['icon'] ?> mr-2"></i><?= $meta['title'] ?> <span class="badge badge-light ml-2 px-2" style="font-size:10px;border-radius:10px;"><?= count($cats) ?> <?= $isTr?'kategori':'categories'?></span></span>
                                                <div class="d-flex align-items-center" style="gap:10px;">
                                                    <span style="font-size:11px; font-weight:600; opacity:0.7;"><?= $isTr ? 'Toplam' : 'Total' ?>: <?= number_format($totalCount) ?></span>
                                                    <i class="fas fa-chevron-down toggle-icon" style="font-size:11px;"></i>
                                                </div>
                                            </div>
                                            <div class="cat-list-body">
                                                <table class="cat-list-table">
                                                    <tbody>
                                                        <?php foreach ($cats as $idx => $c):
                                                            $count = match($c['type']){'asset'=>$c['asset_count'],'license'=>$c['lic_count'],'consumable'=>$c['con_count'],'accessory'=>$c['acc_count'],'component'=>$c['comp_count'],default=>0};
                                                            $barPct = min(100, max(2, round(($count / $maxCount) * 100)));
                                                            $isHiddenRow = ($idx >= $listShowLimit) ? 'class="cat-extra-row" style="display:none;"' : '';
                                                            ?>
                                                            <tr <?= $isHiddenRow ?> data-type="<?= $typeKey ?>" data-name="<?= htmlspecialchars(mb_strtolower($c['name'],'UTF-8')) ?>">
                                                                <td class="cat-rank-col"><?= $idx+1 ?></td>
                                                                <td class="cat-name-cell">
                                                                    <a href="varliklar?view=<?= $meta['view'] ?>&category_id=<?= $c['id'] ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($c['name']) ?></a>
                                                                </td>
                                                                <td class="cat-bar-cell">
                                                                    <div class="cat-mini-bar-wrap">
                                                                        <div class="cat-mini-bar-fill" style="width:<?= $barPct ?>%; background:<?= $bc ?>;"></div>
                                                                    </div>
                                                                </td>
                                                                <td class="cat-count-cell"><?= number_format($count) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                                <?php if (count($cats) > $listShowLimit): ?>
                                                <div class="cat-list-show-more" onclick="showAllInList(this)" data-total="<?= count($cats) ?>">
                                                    <i class="fas fa-chevron-down mr-1"></i> <?= $isTr ? (count($cats)-$listShowLimit).' kategori daha göster' : 'Show '.count($cats)-$listShowLimit.' more' ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- VIEW 3: DYNAMIC EMPTY STATE -->
                                <div class="category-empty-state d-none" id="categoryEmptyState">
                                    <i class="fas fa-search-minus animate-pulse"></i>
                                    <h5 class="font-weight-bold mt-2"><?= $isTr ? 'Eşleşen Kategori Bulunamadı' : 'No Matching Categories Found' ?></h5>
                                    <p class="text-muted small mb-3"><?= $isTr ? 'Arama kriterlerinizi değiştirmeyi veya filtreleri sıfırlamayı deneyin.' : 'Try changing your search criteria or resetting filters.' ?></p>
                                    <button class="btn btn-sm btn-dark px-4 py-2 rounded-pill font-weight-bold" id="btnResetCategoryFilters" style="font-size:12px;">
                                        <i class="fas fa-redo mr-2"></i> <?= $isTr ? 'Filtreleri Sıfırla' : 'Reset Filters' ?>
                                    </button>
                                </div>

                            </div>

                            <script>
                            $(document).ready(function() {
                                let activeType = 'all';
                                let searchQuery = '';
                                
                                // View Switcher Logic (3 modes)
                                function setView(mode) {
                                    $('.btn-view-toggle').removeClass('active');
                                    $('#categoryCardsWrapper, #categoryGroupedWrapper, #categoryListWrapper').addClass('d-none');
                                    if (mode === 'cards') {
                                        $('#btnViewCards').addClass('active');
                                        $('#categoryCardsWrapper').removeClass('d-none');
                                    } else if (mode === 'grouped') {
                                        $('#btnViewGrouped').addClass('active');
                                        $('#categoryGroupedWrapper').removeClass('d-none');
                                    } else {
                                        $('#btnViewList').addClass('active');
                                        $('#categoryListWrapper').removeClass('d-none');
                                    }
                                    localStorage.setItem('categoryViewMode', mode);
                                    applyFilters();
                                }

                                $('#btnViewCards').on('click', function() { setView('cards'); });
                                $('#btnViewGrouped').on('click', function() { setView('grouped'); });
                                $('#btnViewList').on('click', function() { setView('list'); });
                                
                                // Restore View Mode (default: grouped)
                                const preferredView = localStorage.getItem('categoryViewMode') || 'grouped';
                                setView(preferredView);

                                // Filter Tabs
                                $('.btn-filter-tab').on('click', function() {
                                    $('.btn-filter-tab').removeClass('active');
                                    $(this).addClass('active');
                                    activeType = $(this).data('type');
                                    applyFilters();
                                });

                                // Search
                                $('#categorySearchInput').on('keyup input', function() {
                                    searchQuery = $(this).val().trim().toLowerCase();
                                    $('#clearCategorySearch').toggle(searchQuery.length > 0);
                                    applyFilters();
                                });
                                $('#clearCategorySearch').on('click', function() {
                                    $('#categorySearchInput').val('');
                                    $(this).hide();
                                    searchQuery = '';
                                    applyFilters();
                                });
                                $('#btnResetCategoryFilters').on('click', function() {
                                    $('.btn-filter-tab[data-type="all"]').trigger('click');
                                    $('#clearCategorySearch').trigger('click');
                                });

                                // Show More in grouped columns
                                $(document).on('click', '.cat-show-more-btn', function() {
                                    const $btn = $(this);
                                    const typeKey = $btn.data('type');
                                    const isExpanded = $btn.hasClass('expanded');
                                    const $list = $btn.prev('.category-list');
                                    if (isExpanded) {
                                        const showLimit = <?= $showLimit ?? 8 ?>;
                                        $list.find('.category-item-grouped[data-idx]').each(function() {
                                            const idx = parseInt($(this).data('idx'));
                                            if (idx >= showLimit) $(this).hide();
                                        });
                                        const hidden = $btn.data('total') - showLimit;
                                        $btn.find('span').text(hidden + ' <?= $isTr ? "kategori daha" : "more" ?>');
                                        $btn.removeClass('expanded');
                                    } else {
                                        $list.find('.category-item-grouped').show();
                                        $btn.find('span').text('<?= $isTr ? "Daha az göster" : "Show less" ?>');
                                        $btn.addClass('expanded');
                                    }
                                });

                                function applyFilters() {
                                    let matchedCount = 0;
                                    const isCards   = $('#btnViewCards').hasClass('active');
                                    const isGrouped = $('#btnViewGrouped').hasClass('active');
                                    const isList    = $('#btnViewList').hasClass('active');
                                    
                                    // 1. Filter Grid Cards
                                    $('#categoryCardsWrapper .category-modern-card').each(function() {
                                        const cardType = $(this).data('type');
                                        const cardName = $(this).data('name').toString();
                                        const match = (activeType === 'all' || cardType === activeType) && (searchQuery === '' || cardName.includes(searchQuery));
                                        $(this).toggle(match);
                                        if (match) matchedCount++;
                                    });

                                    // 2. Filter Grouped Columns
                                    let activeColumnsCount = 0;
                                    $('#categoryGroupedWrapper .category-type-card').each(function() {
                                        const colType = $(this).data('column-type');
                                        let itemsInCol = 0;
                                        $(this).find('.category-item-grouped').each(function() {
                                            const itemName = $(this).data('name').toString();
                                            const sm = (searchQuery === '' || itemName.includes(searchQuery));
                                            // Don't toggle hidden ones (show-more controlled)
                                            if (sm && !$(this).is('[data-idx]') || (sm && parseInt($(this).data('idx')) < <?= $showLimit ?? 8 ?>)) {
                                                // visible
                                            }
                                            if (sm) itemsInCol++;
                                        });
                                        const colMatch = (activeType === 'all' || colType === activeType);
                                        if (colMatch && itemsInCol > 0) {
                                            $(this).show(); activeColumnsCount++;
                                            $(this).find('.empty-state').addClass('d-none');
                                            $(this).find('.category-list').removeClass('d-none');
                                            $(this).find('.group-count-badge').text(itemsInCol).show();
                                        } else if (colMatch && itemsInCol === 0 && searchQuery === '') {
                                            $(this).show(); activeColumnsCount++;
                                            $(this).find('.empty-state').removeClass('d-none');
                                            $(this).find('.category-list').addClass('d-none');
                                            $(this).find('.group-count-badge').hide();
                                        } else {
                                            $(this).hide();
                                        }
                                    });
                                    // Adjust grid columns dynamically
                                    const cols = Math.max(1, Math.min(5, activeColumnsCount));
                                    $('#categoryGroupedWrapper .inventory-category-grid').css('grid-template-columns', 'repeat(' + cols + ', 1fr)');

                                    // 3. Filter List View
                                    let listMatched = 0;
                                    $('#categoryListWrapper .cat-list-section').each(function() {
                                        const listType = $(this).data('list-type');
                                        const typeMatch = (activeType === 'all' || listType === activeType);
                                        let rowsVisible = 0;
                                        $(this).find('tbody tr').each(function() {
                                            const rowType = $(this).data('type');
                                            const rowName = ($(this).data('name') || '').toString();
                                            const rm = (activeType === 'all' || rowType === activeType) && (searchQuery === '' || rowName.includes(searchQuery));
                                            if (rm) { $(this).show(); rowsVisible++; }
                                            else $(this).hide();
                                        });
                                        if (typeMatch && rowsVisible > 0) { $(this).show(); listMatched += rowsVisible; }
                                        else { $(this).hide(); }
                                    });

                                    // 4. Empty state
                                    const activeMode = isCards ? matchedCount : (isGrouped ? activeColumnsCount : listMatched);
                                    if (activeMode > 0) {
                                        $('#categoryEmptyState').addClass('d-none');
                                        if (isCards)        { $('#categoryCardsWrapper').removeClass('d-none'); }
                                        else if (isGrouped) { $('#categoryGroupedWrapper').removeClass('d-none'); }
                                        else                { $('#categoryListWrapper').removeClass('d-none'); }
                                    } else {
                                        $('#categoryEmptyState').removeClass('d-none');
                                        $('#categoryCardsWrapper, #categoryGroupedWrapper, #categoryListWrapper').addClass('d-none');
                                    }
                                }
                            });

                            // Toggle list section collapse
                            function toggleCatSection(header) {
                                const $hdr = $(header);
                                const $body = $hdr.next('.cat-list-body');
                                const $icon = $hdr.find('i.toggle-icon');
                                if ($hdr.hasClass('collapsed')) {
                                    $body.slideDown(180);
                                    $hdr.removeClass('collapsed');
                                } else {
                                    $body.slideUp(180);
                                    $hdr.addClass('collapsed');
                                }
                            }

                            // Show all rows in a list section
                            function showAllInList(el) {
                                $(el).prev('table').find('.cat-extra-row').show();
                                $(el).remove();
                            }
                            </script>
                        </div>
                    </div>
                </div>
            <?php } else { ?>
                <?php
                if (!function_exists('renderPremiumAvatar')) {
                    function renderPremiumAvatar($name, $raw_img, $display_img, $defaultInitials = 'A') {
                        if (!empty($raw_img) && strpos($display_img, 'ui-avatars.com') === false) {
                            return '<img src="' . htmlspecialchars($display_img) . '" class="rounded-lg shadow-sm" style="width: 40px; height: 40px; object-fit: contain; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; transition: transform 0.2s;">';
                        }
                        
                        $name_hash = substr(md5($name), 0, 6);
                        $bg_gradients = [
                            'linear-gradient(135deg, #6366f1 0%, #a855f7 100%)',
                            'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)',
                            'linear-gradient(135deg, #10b981 0%, #059669 100%)',
                            'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)',
                            'linear-gradient(135deg, #ec4899 0%, #be185d 100%)',
                            'linear-gradient(135deg, #06b6d4 0%, #0891b2 100%)',
                        ];
                        $grad_idx = hexdec(substr($name_hash, 0, 2)) % count($bg_gradients);
                        $selected_gradient = $bg_gradients[$grad_idx];
                        
                        $clean_name = preg_replace('/[-_]\d+$/', '', $name);
                        $words = explode(' ', $clean_name);
                        $initials = '';
                        if (count($words) >= 2) {
                            $initials = mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1);
                        } else {
                            $initials = mb_substr($clean_name, 0, 2);
                        }
                        $initials = mb_strtoupper($initials);
                        if (mb_strlen($initials) > 2) {
                            $initials = mb_substr($initials, 0, 2);
                        }
                        if (empty($initials)) {
                            $initials = $defaultInitials;
                        }
                        
                        return '<div class="d-flex align-items-center justify-content-center rounded-lg shadow-sm font-weight-bold text-white" style="width: 40px; height: 40px; background: ' . $selected_gradient . '; border-radius: 10px; font-size: 13px; letter-spacing: 0.5px; box-shadow: 0 4px 8px rgba(0,0,0,0.12); transition: transform 0.2s;">' . htmlspecialchars($initials) . '</div>';
                    }
                }

                // Kişisel zimmet sorguları
                $my_assets_list = $pdo->query("SELECT a.*, sl.name as status_label_name, sl.color as status_label_color, c.name as category_name, c.image as category_image, m.name as model_name FROM assets a LEFT JOIN asset_status_labels sl ON a.status_id = sl.id LEFT JOIN asset_categories c ON a.category_id = c.id LEFT JOIN asset_models m ON a.model_id = m.id WHERE a.assigned_user_id = " . (int)$user_id . " AND a.deleted_at IS NULL ORDER BY a.id DESC")->fetchAll(PDO::FETCH_ASSOC);

                $my_accessories_list = $pdo->query("SELECT aac.*, aa.name, aa.model_no, aa.image FROM asset_accessory_checkouts aac JOIN asset_accessories aa ON aac.accessory_id = aa.id WHERE aac.user_id = " . (int)$user_id . " AND aa.deleted_at IS NULL ORDER BY aac.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

                $my_licenses_list = $pdo->query("SELECT alc.*, al.software_name, al.license_key, al.image FROM asset_license_checkouts alc JOIN asset_licenses al ON alc.license_id = al.id WHERE alc.user_id = " . (int)$user_id . " AND al.deleted_at IS NULL ORDER BY alc.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

                $my_consumables_list = $pdo->query("SELECT acc.*, ac.name, ac.image FROM asset_consumable_checkouts acc JOIN asset_consumables ac ON acc.consumable_id = ac.id WHERE acc.user_id = " . (int)$user_id . " AND ac.deleted_at IS NULL ORDER BY acc.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

                $c_my_assets = count($my_assets_list);
                
                // Fetch user's pending signatures count
                $c_my_pending_sigs = (int)$pdo->query("SELECT COUNT(*) FROM asset_signatures WHERE status = 'pending_user' AND user_id = " . (int)$user_id)->fetchColumn();
                ?>

                <div class="row mb-4">
                    <div class="col-lg-6 col-md-6 mb-3">
                        <div class="stat-card bg-assets" onclick="<?= ($c_my_assets > 0) ? "window.location='varlik-detay/" . $my_assets_list[0]['id'] . "?view=assets';" : "document.getElementById('my-assets-card').scrollIntoView({behavior: 'smooth'});" ?>">
                            <i class="fas fa-laptop-medical icon-bg"></i>
                            <div class="count"><?= $c_my_assets ?></div>
                            <div class="label"><?= $isTr ? 'ZİMMETLİ CİHAZLARIM' : 'MY ASSIGNED ASSETS' ?></div>
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-6 mb-3">
                        <div class="stat-card <?= ($c_my_pending_sigs > 0) ? 'bg-breach' : 'bg-people' ?>" onclick="window.location='varliklar?view=signatures'">
                            <i class="fas fa-signature icon-bg"></i>
                            <div class="count"><?= $c_my_pending_sigs ?></div>
                            <div class="label"><?= $isTr ? 'BEKLEYEN İMZALARIM' : 'MY PENDING SIGNATURES' ?></div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-12">
                        <div class="ds-card mb-4" id="my-assets-card">
                            <div class="p-4 border-bottom d-flex justify-content-between align-items-center ds-card-header">
                                <h5 class="m-0 font-weight-bold text-dark">
                                    <i class="fas fa-laptop mr-2 text-primary animate-pulse"></i>
                                    <?= $isTr ? 'Zimmetli Cihazlarım' : 'My Assigned Devices' ?>
                                </h5>
                            </div>
                            <div class="table-responsive">
                                <table class="ds-table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th><?= $isTr ? 'Cihaz Görseli' : 'Image' ?></th>
                                            <th><?= $isTr ? 'Varlık Etiketi' : 'Asset Tag' ?></th>
                                            <th><?= $isTr ? 'Cihaz Adı' : 'Device Name' ?></th>
                                            <th><?= $isTr ? 'Kategori' : 'Category' ?></th>
                                            <th><?= $isTr ? 'Seri No' : 'Serial No' ?></th>
                                            <th><?= $isTr ? 'Durum' : 'Status' ?></th>
                                            <th class="text-right"><?= $isTr ? 'İşlemler' : 'Actions' ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($my_assets_list)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-5 text-muted">
                                                    <i class="fas fa-laptop fa-3x mb-3 opacity-30"></i>
                                                    <p class="mb-0 font-weight-bold"><?= $isTr ? 'Üzerinize zimmetli herhangi bir cihaz bulunmamaktadır.' : 'No devices are currently assigned to you.' ?></p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($my_assets_list as $asset): 
                                                $raw_img = !empty($asset['image']) ? $asset['image'] : (!empty($asset['category_image']) ? 'categories/' . $asset['category_image'] : '');
                                                if (!empty($raw_img)) {
                                                    if (strpos($raw_img, 'public/') === 0) $raw_img = substr($raw_img, 7);
                                                    if (strpos($raw_img, 'uploads/') !== 0 && strpos($raw_img, 'categories/') !== 0) {
                                                        $raw_img = 'uploads/assets/' . $raw_img;
                                                    } elseif (strpos($raw_img, 'categories/') === 0) {
                                                        $raw_img = 'uploads/' . $raw_img;
                                                    }
                                                    $display_img = "public/" . $raw_img;
                                                } else {
                                                    $display_img = "https://ui-avatars.com/api/?name=" . urlencode($asset['name'] ?? 'Asset') . "&background=f1f5f9&color=64748b";
                                                }
                                                $statusColor = $asset['status_label_color'] ?? '#64748b';
                                            ?>
                                                <tr>
                                                    <td>
                                                        <?= renderPremiumAvatar($asset['name'], $raw_img, $display_img, 'A') ?>
                                                    </td>
                                                    <td><span class="badge badge-asset-tag"><?= htmlspecialchars($asset['asset_tag']) ?></span></td>
                                                    <td>
                                                        <div class="font-weight-bold text-dark" style="font-size: 14px; letter-spacing: -0.2px;"><?= htmlspecialchars($asset['name']) ?></div>
                                                        <?php if(!empty($asset['model_name'])): ?>
                                                            <div class="text-xs text-muted" style="margin-top: 2px; font-weight: 500;"><?= htmlspecialchars($asset['model_name']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if(!empty($asset['category_name'])): ?>
                                                            <span class="badge badge-soft-secondary"><?= htmlspecialchars($asset['category_name']) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted opacity-40">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if(!empty($asset['serial_no'])): ?>
                                                            <span class="font-monospace text-xs px-2 py-1 bg-light rounded text-dark border" style="font-size: 11px;"><?= htmlspecialchars($asset['serial_no']) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted opacity-40">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $statusName = $asset['status_label_name'] ?: ($isTr ? 'Aktif' : 'Active');
                                                        if (!$isTr) {
                                                            $statusNameLower = mb_strtolower($statusName, 'UTF-8');
                                                            $statusTranslations = [
                                                                'hazir' => 'Ready',
                                                                'hazır' => 'Ready',
                                                                'zimmetli' => 'Assigned',
                                                                'atanmiş' => 'Assigned',
                                                                'atanmış' => 'Assigned',
                                                                'arizali' => 'Faulty',
                                                                'arızalı' => 'Faulty',
                                                                'hurda' => 'Scrapped',
                                                                'aktif' => 'Active',
                                                                'beklemede' => 'Pending',
                                                                'depoda' => 'In Stock',
                                                                'kayboldu' => 'Lost',
                                                                'çalındı' => 'Stolen',
                                                                'calindi' => 'Stolen'
                                                            ];
                                                            if (isset($statusTranslations[$statusNameLower])) {
                                                                $statusName = $statusTranslations[$statusNameLower];
                                                            }
                                                        }
                                                        ?>
                                                        <span class="d-inline-flex align-items-center rounded-full text-xs font-semibold" style="background: <?= $statusColor ?>15; color: <?= $statusColor ?>; border: 1px solid <?= $statusColor ?>30; border-radius: 50px; font-size: 11px; gap: 6px; padding: 6px 12px; font-weight: 600;">
                                                            <span class="status-dot-pulse" style="width: 6px; height: 6px; border-radius: 50%; background-color: <?= $statusColor ?>; display: inline-block; box-shadow: 0 0 8px <?= $statusColor ?>;"></span>
                                                            <?= htmlspecialchars($statusName) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-right">
                                                        <a href="varlik-detay/<?= $asset['id'] ?>?view=assets" class="btn btn-sm btn-premium-action">
                                                            <i class="fas fa-eye mr-1"></i> <?= $isTr ? 'Detayı Gör' : 'View Detail' ?>
                                                        </a>
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

                <?php if (!empty($my_accessories_list) || !empty($my_licenses_list) || !empty($my_consumables_list)): ?>
                    <div class="row">
                        <!-- Accessories & Licenses assigned to user -->
                        <div class="col-lg-12">
                            <div class="ds-card mb-4">
                                <div class="p-4 border-bottom d-flex justify-content-between align-items-center ds-card-header">
                                    <h5 class="m-0 font-weight-bold text-dark">
                                        <i class="fas fa-boxes mr-2 text-success animate-pulse"></i>
                                        <?= $isTr ? 'Diğer Zimmetli Envanterlerim' : 'Other Assigned Inventory' ?>
                                    </h5>
                                </div>
                                <div class="table-responsive">
                                    <table class="ds-table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th><?= $isTr ? 'Tür' : 'Type' ?></th>
                                                <th><?= $isTr ? 'Görsel' : 'Image' ?></th>
                                                <th><?= $isTr ? 'İsim' : 'Name' ?></th>
                                                <th><?= $isTr ? 'Kod / Anahtar' : 'Code / Key' ?></th>
                                                <th><?= $isTr ? 'Zimmet Tarihi' : 'Assigned Date' ?></th>
                                                <th class="text-right"><?= $isTr ? 'İşlem' : 'Action' ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Accessories -->
                                            <?php foreach ($my_accessories_list as $acc): 
                                                $img = $acc['image'];
                                                if ($img) {
                                                    if (strpos($img, 'public/') === 0) $img = substr($img, 7);
                                                    if (strpos($img, 'uploads/') !== 0) $img = 'uploads/accessories/' . $img;
                                                    $display_img = "public/" . $img;
                                                } else {
                                                    $display_img = "https://ui-avatars.com/api/?name=" . urlencode($acc['name']) . "&background=e0f2fe&color=0369a1";
                                                }
                                            ?>
                                                <tr>
                                                    <td><span class="badge badge-soft-primary"><i class="fas fa-keyboard mr-1"></i> <?= $isTr ? 'Aksesuar' : 'Accessory' ?></span></td>
                                                    <td><?= renderPremiumAvatar($acc['name'], $acc['image'], $display_img, 'A') ?></td>
                                                    <td><div class="font-weight-bold text-dark" style="font-size: 14px; letter-spacing: -0.2px;"><?= htmlspecialchars($acc['name']) ?></div></td>
                                                    <td><span class="font-monospace text-xs px-2 py-1 bg-light rounded text-dark border" style="font-size: 11px;"><?= htmlspecialchars($acc['model_no'] ?: '-') ?></span></td>
                                                    <td><span class="text-xs text-muted" style="font-weight: 500;"><?= date('d.m.Y H:i', strtotime($acc['created_at'])) ?></span></td>
                                                    <td class="text-right">
                                                        <a href="varlik-detay/<?= $acc['accessory_id'] ?>?view=accessories" class="btn btn-sm btn-premium-action">
                                                            <i class="fas fa-eye mr-1"></i> <?= $isTr ? 'İncele' : 'View' ?>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>

                                            <!-- Licenses -->
                                            <?php foreach ($my_licenses_list as $lic): 
                                                $img = $lic['image'];
                                                if ($img) {
                                                    if (strpos($img, 'public/') === 0) $img = substr($img, 7);
                                                    if (strpos($img, 'uploads/') !== 0) $img = 'uploads/licenses/' . $img;
                                                    $display_img = "public/" . $img;
                                                } else {
                                                    $display_img = "https://ui-avatars.com/api/?name=" . urlencode($lic['software_name']) . "&background=e0f2fe&color=0369a1";
                                                }
                                            ?>
                                                <tr>
                                                    <td><span class="badge badge-soft-success"><i class="fas fa-key mr-1"></i> <?= $isTr ? 'Lisans' : 'License' ?></span></td>
                                                    <td><?= renderPremiumAvatar($lic['software_name'], $lic['image'], $display_img, 'L') ?></td>
                                                    <td><div class="font-weight-bold text-dark" style="font-size: 14px; letter-spacing: -0.2px;"><?= htmlspecialchars($lic['software_name']) ?></div></td>
                                                    <td><span class="badge badge-asset-tag" style="letter-spacing:-0.5px;"><?= htmlspecialchars($lic['license_key'] ?: '-') ?></span></td>
                                                    <td><span class="text-xs text-muted" style="font-weight: 500;"><?= date('d.m.Y H:i', strtotime($lic['created_at'])) ?></span></td>
                                                    <td class="text-right">
                                                        <a href="varlik-detay/<?= $lic['license_id'] ?>?view=licenses" class="btn btn-sm btn-premium-action">
                                                            <i class="fas fa-eye mr-1"></i> <?= $isTr ? 'İncele' : 'View' ?>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>

                                            <!-- Consumables -->
                                            <?php foreach ($my_consumables_list as $con): 
                                                $img = $con['image'];
                                                if ($img) {
                                                    if (strpos($img, 'public/') === 0) $img = substr($img, 7);
                                                    if (strpos($img, 'uploads/') !== 0) $img = 'uploads/consumables/' . $img;
                                                    $display_img = "public/" . $img;
                                                } else {
                                                    $display_img = "https://ui-avatars.com/api/?name=" . urlencode($con['name']) . "&background=e0f2fe&color=0369a1";
                                                }
                                            ?>
                                                <tr>
                                                    <td><span class="badge badge-soft-info"><i class="fas fa-fill-drip mr-1"></i> <?= $isTr ? 'Sarf Malzeme' : 'Consumable' ?></span></td>
                                                    <td><?= renderPremiumAvatar($con['name'], $con['image'], $display_img, 'C') ?></td>
                                                    <td><div class="font-weight-bold text-dark" style="font-size: 14px; letter-spacing: -0.2px;"><?= htmlspecialchars($con['name']) ?></div></td>
                                                    <td><span class="text-muted opacity-40">-</span></td>
                                                    <td><span class="text-xs text-muted" style="font-weight: 500;"><?= date('d.m.Y H:i', strtotime($con['created_at'])) ?></span></td>
                                                    <td class="text-right">
                                                        <a href="varlik-detay/<?= $con['consumable_id'] ?>?view=consumables" class="btn btn-sm btn-premium-action">
                                                            <i class="fas fa-eye mr-1"></i> <?= $isTr ? 'İncele' : 'View' ?>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php } ?>
            <?php } ?>

            <?php if ($panel_mode == 'ticket') { ?>
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stat-card bg-active" onclick="window.location='anasayfa?panel=ticket&status=active'"><i
                                class="fas fa-envelope-open icon-bg"></i>
                            <div class="count">
                                <?= $c_open ?>
                            </div>
                            <div class="label">
                                <?= __("active_tickets") ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stat-card bg-solved" onclick="window.location='anasayfa?panel=ticket&status=closed'"><i
                                class="fas fa-check-circle icon-bg"></i>
                            <div class="count">
                                <?= $c_closed ?>
                            </div>
                            <div class="label">
                                <?= __("solved_closed_tickets") ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stat-card bg-breach" onclick="window.location='anasayfa?panel=ticket&status=breach'"><i
                                class="fas fa-fire icon-bg"></i>
                            <div class="count">
                                <?= $c_breach ?>
                            </div>
                            <div class="label">
                                <?= __("breached_urgent") ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stat-card bg-all" onclick="window.location='anasayfa?panel=ticket&status=all'"><i
                                class="fas fa-ticket-alt icon-bg"></i>
                            <div class="count">
                                <?= $c_all ?>
                            </div>
                            <div class="label">
                                <?= __("all_tickets") ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ds-card">
                    <div class="p-4 border-bottom d-flex justify-content-between align-items-center ds-card-header">
                        <form method="GET" class="d-flex align-items-center" style="gap:10px;">
                            <input type="hidden" name="panel" value="ticket">
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="<?= __("search") ?>..."
                            value="
                    <?= htmlspecialchars($filter_q) ?>" style="width:200px;">
                            <select name="status" class="form-control form-control-sm" style="width:150px;">
                                <option value="active" <?= $filter_status == 'active' ? 'selected' : '' ?>>
                                    <?= __("active") ?>
                                </option>
                                <option value="breach" <?= $filter_status == 'breach' ? 'selected' : '' ?>>
                                    <?= __("breached_urgent") ?>
                                </option>
                                <option value="closed" <?= $filter_status == 'closed' ? 'selected' : '' ?>>
                                    <?= __("solved_closed_tickets") ?>
                                </option>
                                <option value="all" <?= $filter_status == 'all' ? 'selected' : '' ?>>
                                    <?= __("all") ?>
                                </option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary">
                                <?= __("filter") ?>
                            </button>
                        </form>
                        <div class="d-flex align-items-center" style="gap: 8px;">
                            <a href="anasayfa?route=kanban" class="btn btn-sm btn-outline-primary font-weight-bold"><i class="fas fa-columns mr-1"></i> <?= $isTr ? 'Kanban Pano' : 'Kanban Board' ?></a>
                            <a href="bilet-ekle" class="btn btn-sm btn-primary font-weight-bold"><i class="fas fa-plus mr-1"></i>
                                <?= __("new_ticket") ?>
                            </a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="ds-table">
                            <thead>
                                <tr>
                                    <th>
                                        <?= __("ticket_no_short") ?>
                                    </th>
                                    <th>
                                        <?= __("subject") ?>
                                    </th>
                                    <th>
                                        <?= __("customer") ?>
                                    </th>
                                    <th>
                                        <?= __("assigned_to") ?>
                                    </th>
                                    <th>
                                        <?= __("status") ?>
                                    </th>
                                    <th>
                                        <?= __("date") ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $t): ?>
                                    <tr style="cursor:pointer;" onclick="window.location='bilet-detay/<?= $t['id'] ?>'">
                                        <td>
                                            <?php
                                                $tno = !empty($t['ticket_no']) ? $t['ticket_no'] : ('EA-' . $t['id']);
                                                echo htmlspecialchars($tno);
                                            ?>
                                        </td>
                                        <td class="font-weight-bold">
                                            <?= htmlspecialchars($t['title']) ?>
                                        </td>
                                        <td>
                                            <?php
                                                $custDisplay = !empty($t['customer_name']) ? $t['customer_name'] : (!empty($t['creator']) ? $t['creator'] : '-');
                                                echo htmlspecialchars($custDisplay);
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($t['locked_by']): ?>
                                                <div class="d-flex align-items-center">
                                                    <span class="mr-2 font-weight-bold text-danger" style="font-size:12px;">
                                                        <i class="fas fa-lock mr-1"></i>
                                                        <?= htmlspecialchars($t['locked_by_name'] ?? __("locked")) ?>
                                                    </span>
                                                </div>
                                            <?php elseif ($t['agent'] || $t['last_reply_user']): ?>
                                                <div class="d-flex flex-column">
                                                    <span class="font-weight-bold" style="font-size:12px; color:#1e293b;">
                                                        <?= htmlspecialchars($t['agent'] ?: $t['last_reply_user']) ?>
                                                    </span>
                                                    <?php if ($t['last_reply_at']): ?>
                                                        <span class="text-muted" style="font-size:10px;">
                                                            <?= date('d.m.Y H:i', strtotime($t['last_reply_at'])) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small"><em>
                                                        <?= __("unassigned") ?>
                                                    </em></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($t['is_breached']): ?>
                                                <span class="badge" style="background:#ef4444; color:white;">
                                                    <?= ($isTr ? 'Gecikmiş' : 'Breached') ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge"
                                                    style="background:<?= $statusColors[$t['status']] ?? '#ddd' ?>; color:white;">
                                                    <?= $statusLabels[$t['status']] ?? $t['status'] ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <?= date('d.m.Y', strtotime($t['create_date'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php } ?>
            <?php if (!in_array($panel_mode, ['inventory', 'ticket'], true)): ?>
                <div class="card shadow-sm border-0 mt-4 text-center py-5" style="border-radius:16px;">
                    <div class="card-body py-5">
                        <div class="mb-3">
                            <i class="fas fa-search fa-4x text-warning"></i>
                        </div>
                        <h3 class="font-weight-bold text-dark mb-2"><?= $isTr ? 'Aradığınız Sayfa veya Panel Bulunamadı' : 'Page or Panel Not Found' ?></h3>
                        <p class="text-muted mb-4"><?= $isTr ? 'Girdiğiniz panel adresi ("' . htmlspecialchars($panel_mode) . '") sistemde mevcut değil veya kaldırılmış.' : 'The specified panel address ("' . htmlspecialchars($panel_mode) . '") does not exist or was removed.' ?></p>
                        <a href="anasayfa?panel=<?= $default_panel ?>" class="btn btn-primary font-weight-bold px-4 py-2" style="border-radius:10px;">
                            <i class="fas fa-home mr-2"></i><?= $isTr ? 'Ana Panele Dön' : 'Return to Main Panel' ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>


    <?php if ($panel_mode == 'inventory') { ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const ctx = document.getElementById('statusChart');
                if (ctx) {
                    const invData = <?= json_encode($inventoryData) ?>;
                    const labels = [
                        '<?= __("assets") ?>',
                        '<?= __("licenses") ?>',
                        '<?= __("accessories") ?>',
                        '<?= __("consumables") ?>',
                        '<?= __("components") ?>'
                    ];

                    var chartType = (typeof Chart.version !== 'undefined' && Chart.version.startsWith('3')) ? 'bar' : 'horizontalBar';
                    new Chart(ctx, {
                        type: chartType,
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: '<?= $ui["assigned"] ?>',
                                    data: [invData.assets.assigned, invData.licenses.assigned, invData.accessories.assigned, invData.consumables.assigned, invData.components.assigned],
                                    backgroundColor: '#1e3c72',
                                    borderRadius: 5
                                },
                                {
                                    label: '<?= $ui["ready"] ?>',
                                    data: [invData.assets.ready, invData.licenses.ready, invData.accessories.ready, invData.consumables.ready, invData.components.ready],
                                    backgroundColor: '#10b981',
                                    borderRadius: 5
                                },
                                {
                                    label: '<?= $ui["faulty"] ?>',
                                    data: [invData.assets.faulty, invData.licenses.faulty, invData.accessories.faulty, invData.consumables.faulty, invData.components.faulty],
                                    backgroundColor: '#f59e0b',
                                    borderRadius: 5
                                },
                                {
                                    label: '<?= $ui["scrapped"] ?>',
                                    data: [invData.assets.scrapped, invData.licenses.scrapped, invData.accessories.scrapped, invData.consumables.scrapped, invData.components.scrapped],
                                    backgroundColor: '#64748b',
                                    borderRadius: 5
                                }
                            ]
                        },
                        options: {
                            indexAxis: 'y',
                            maintainAspectRatio: false,
                            responsive: true,
                            legend: { position: 'top', labels: { boxWidth: 12, usePointStyle: true } },
                            tooltips: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { position: 'top', labels: { boxWidth: 12, usePointStyle: true } },
                                tooltip: { mode: 'index', intersect: false, axis: 'y' }
                            },
                            scales: {
                                xAxes: [{
                                    stacked: true,
                                    gridLines: { display: false },
                                    ticks: {
                                        beginAtZero: true,
                                        min: 0,
                                        precision: 0,
                                        stepSize: 1,
                                        callback: function(value) { if (value >= 0 && value % 1 === 0) return value; }
                                    }
                                }],
                                yAxes: [{
                                    stacked: true,
                                    gridLines: { display: false },
                                    ticks: {
                                        beginAtZero: true,
                                        min: 0,
                                        precision: 0
                                    }
                                }],
                                x: { 
                                    stacked: true, 
                                    grid: { display: false },
                                    ticks: {
                                        precision: 0,
                                        stepSize: 1,
                                        callback: function(value) { if (value >= 0 && value % 1 === 0) return value; }
                                    },
                                    min: 0
                                },
                                y: { stacked: true, grid: { display: false } }
                            }
                        }
                    });
                }
            });
        </script>
    <?php } ?>
<?php
} else {
    // PERSONNEL VIEW
    $isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';
    
    // Durum Çevirileri
    $status_tr = [
        'open' => 'Açık',
        'assigned' => 'Atanmış',
        'in_progress' => 'İşlemde',
        'waiting_customer' => 'Müşteri Yanıtı Bekleniyor',
        'pending' => 'Müşteri Yanıtı Bekleniyor',
        'resolved' => 'Çözüldü',
        'closed' => 'Kapalı',
        'waiting' => 'Beklemede'
    ];
    $status_en = [
        'open' => 'Open',
        'assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'waiting_customer' => 'Awaiting Customer',
        'pending' => 'Awaiting Customer',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'waiting' => 'Pending'
    ];
    $status_color = [
        'open' => 'primary',
        'assigned' => 'info',
        'in_progress' => 'warning',
        'waiting_customer' => 'warning',
        'pending' => 'warning',
        'resolved' => 'success',
        'closed' => 'secondary',
        'waiting' => 'info'
    ];
    ?>
    <style>
        .personnel-dash-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-radius: 20px;
            padding: 30px;
            color: #fff;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
        }
        .personnel-dash-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(59,130,246,0.2) 0%, transparent 60%);
            border-radius: 50%;
        }
        .personnel-dash-card {
            background: #fff;
            border-radius: 16px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            margin-bottom: 24px;
            overflow: hidden;
        }
        body.dark-mode .personnel-dash-card {
            background: #18223f;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.05);
        }
        .personnel-dash-card-header {
            background: transparent;
            border-bottom: 1px solid #f1f5f9;
            padding: 20px 24px;
        }
        body.dark-mode .personnel-dash-card-header {
            border-bottom-color: rgba(255,255,255,0.05);
        }
        .personnel-table th {
            border-top: none;
            color: #64748b;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        body.dark-mode .personnel-table th {
            color: #94a3b8;
            border-bottom-color: rgba(255,255,255,0.1);
        }
        .personnel-table td {
            vertical-align: middle;
            color: #334155;
            font-weight: 500;
            border-top: 1px solid #f1f5f9;
        }
        body.dark-mode .personnel-table td {
            color: #e2e8f0;
            border-top-color: rgba(255,255,255,0.05);
        }
        .personnel-table tbody tr {
            transition: all 0.2s;
        }
        .personnel-table tbody tr:hover {
            background-color: #f8fafc;
            transform: translateY(-1px);
        }
        body.dark-mode .personnel-table tbody tr:hover {
            background-color: rgba(255,255,255,0.02);
        }
    </style>
    
    <div class="p-4">
        <div class="personnel-dash-header">
            <h1 class="font-weight-bold mb-2" style="font-family: 'Plus Jakarta Sans', sans-serif;">
                <?= $isTr ? 'Merhaba' : 'Hello' ?>, <?= htmlspecialchars($_SESSION['fullname']) ?>
            </h1>
            <p class="mb-0 opacity-75" style="font-size: 16px;">
                <i class="fas fa-info-circle mr-2"></i><?= $isTr ? 'Sisteme hoş geldiniz. Açık taleplerinizi ve üzerinize kayıtlı donanımları buradan takip edebilirsiniz.' : 'Welcome to the system. You can track your open requests and assigned assets here.' ?>
            </p>
        </div>

        <div class="row">
            <!-- Biletlerim (My Tickets) -->
            <div class="col-xl-7 col-lg-12">
                <div class="personnel-dash-card">
                    <div class="personnel-dash-card-header d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 font-weight-bold text-dark"><i class="fas fa-ticket-alt mr-2 text-primary"></i><?= $isTr ? 'Aktif Biletlerim' : 'My Active Tickets' ?></h4>
                        <div>
                            <a href="biletler" class="btn btn-sm btn-outline-primary mr-2" style="border-radius: 8px;"><i class="fas fa-list mr-1"></i> <?= $isTr ? 'Tüm Biletlerim' : 'All My Tickets' ?></a>
                            <a href="ticket-olustur" class="btn btn-sm btn-primary" style="border-radius: 8px;"><i class="fas fa-plus mr-1"></i> <?= $isTr ? 'Yeni Bilet' : 'New Ticket' ?></a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                            <table class="table personnel-table mb-0">
                                <thead class="bg-light" style="position: sticky; top: 0; z-index: 2;">
                                    <tr>
                                        <th class="px-4">ID</th>
                                        <th><?= $isTr ? 'Konu' : 'Subject' ?></th>
                                        <th><?= $isTr ? 'Tarih' : 'Date' ?></th>
                                        <th class="text-right px-4"><?= $isTr ? 'Durum' : 'Status' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $personnelCol = function_exists('ticketsPersonnelColumn') ? ticketsPersonnelColumn($pdo) : 'assigned_to';
                                    if (($role ?? $current_user_role ?? 2) == 1) {
                                        $myT = $pdo->prepare("SELECT id, ticket_no, title, status, create_date FROM tickets ORDER BY create_date DESC LIMIT 15");
                                        $myT->execute();
                                    } else {
                                        $myT = $pdo->prepare("SELECT id, ticket_no, title, status, create_date 
                                            FROM tickets 
                                            WHERE (creator_id = ? 
                                                OR customer_id = ? 
                                                OR customer_id IN (SELECT id FROM customers WHERE email COLLATE utf8mb4_general_ci = (SELECT mail COLLATE utf8mb4_general_ci FROM users WHERE id = ?))
                                                OR {$personnelCol} = ? 
                                                OR queue_id IN (SELECT id FROM queues WHERE team_id IN (SELECT team_id FROM teams_users WHERE user_id = ?))) 
                                            ORDER BY create_date DESC LIMIT 15");
                                        $myT->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
                                    }
                                    $tickets = $myT->fetchAll();
                                    
                                    if (count($tickets) > 0):
                                        foreach ($tickets as $mt): 
                                            $sKey = strtolower($mt['status']);
                                            $dLabel = $isTr ? ($status_tr[$sKey] ?? $mt['status']) : ($status_en[$sKey] ?? $mt['status']);
                                            $dColor = $status_color[$sKey] ?? 'secondary';
                                    ?>
                                        <tr onclick="window.location='bilet-detay/<?= $mt['id'] ?>'" style="cursor:pointer;">
                                            <td class="px-4 text-primary font-weight-bold">#<?= $mt['ticket_no'] ?></td>
                                            <td><?= htmlspecialchars($mt['title']) ?></td>
                                            <td><span class="text-muted" style="font-size: 13px;"><?= date('d.m.Y H:i', strtotime($mt['create_date'])) ?></span></td>
                                            <td class="text-right px-4"><span class="badge badge-<?= $dColor ?> px-3 py-1" style="border-radius: 20px; font-weight: 600;"><?= mb_strtoupper($dLabel) ?></span></td>
                                        </tr>
                                    <?php 
                                        endforeach; 
                                    else:
                                    ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-5 text-muted">
                                                <i class="fas fa-box-open fa-3x mb-3 opacity-50 d-block"></i>
                                                <?= $isTr ? 'Size ait aktif bilet bulunmuyor.' : 'You do not have any active tickets.' ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-light p-3 text-right" style="border-radius: 0 0 12px 12px;">
                        <a href="biletler" class="btn btn-sm btn-link font-weight-bold text-primary"><i class="fas fa-arrow-right mr-1"></i> <?= $isTr ? 'Önceki Tüm Biletleri Görüntüle' : 'View All Previous Tickets' ?> &rarr;</a>
                    </div>
                </div>
            </div>

            <!-- Zimmetlerim (My Assigned Items) -->
            <div class="col-xl-5 col-lg-12">
                <div class="personnel-dash-card shadow-sm">
                    <div class="personnel-dash-card-header p-3 bg-white border-bottom">
                        <ul class="nav nav-pills nav-justified" id="personnelDashTabs" role="tablist" style="gap: 5px;">
                            <li class="nav-item">
                                <a class="nav-link active py-2 px-1 font-weight-bold small" id="p-assets-tab" data-toggle="pill" href="#p-assets" role="tab" style="border-radius: 10px;">
                                    <i class="fas fa-desktop mr-1"></i><?= $isTr ? 'Cihazlar' : 'Assets' ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link py-2 px-1 font-weight-bold small" id="p-licenses-tab" data-toggle="pill" href="#p-licenses" role="tab" style="border-radius: 10px;">
                                    <i class="fas fa-key mr-1"></i><?= $isTr ? 'Lisanslar' : 'Licenses' ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link py-2 px-1 font-weight-bold small" id="p-accessories-tab" data-toggle="pill" href="#p-accessories" role="tab" style="border-radius: 10px;">
                                    <i class="fas fa-plug mr-1"></i><?= $isTr ? 'Aksesuarlar' : 'Accessories' ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link py-2 px-1 font-weight-bold small" id="p-consumables-tab" data-toggle="pill" href="#p-consumables" role="tab" style="border-radius: 10px;">
                                    <i class="fas fa-box-open mr-1"></i><?= $isTr ? 'Sarf' : 'Consumables' ?>
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body p-0">
                        <div class="tab-content" id="personnelDashTabContent">
                            
                            <!-- TAB 1: ASSETS -->
                            <div class="tab-pane fade show active" id="p-assets" role="tabpanel">
                                <div class="table-responsive">
                                    <table class="table personnel-table mb-0 align-middle">
                                        <tbody>
                                            <?php
                                            $myA = $pdo->prepare("
                                                SELECT a.id, a.name, a.asset_tag, a.image, am.image as model_image, sl.name as status_name, sl.color as status_color 
                                                FROM assets a 
                                                LEFT JOIN asset_models am ON a.model_id = am.id
                                                LEFT JOIN asset_status_labels sl ON a.status_id = sl.id 
                                                WHERE a.assigned_user_id = ? AND a.deleted_at IS NULL 
                                                ORDER BY a.created_at DESC
                                            ");
                                            $myA->execute([$user_id]);
                                            $assets = $myA->fetchAll();

                                            if (count($assets) > 0):
                                                foreach ($assets as $ast):
                                                    $rawImg = !empty($ast['image']) ? $ast['image'] : ($ast['model_image'] ?? '');
                                                    $imgUrl = null;
                                                    if (!empty($rawImg)) {
                                                        $cImg = ltrim($rawImg, '/');
                                                        if (str_starts_with($cImg, 'public/')) $cImg = substr($cImg, 7);
                                                        if (!str_starts_with($cImg, 'uploads/')) {
                                                            if (str_starts_with($cImg, 'models-')) $cImg = 'uploads/models/' . $cImg;
                                                            else $cImg = 'uploads/assets/' . $cImg;
                                                        }
                                                        if (file_exists(__DIR__ . '/../../public/' . $cImg)) {
                                                            $imgUrl = $base_url . 'public/' . $cImg;
                                                        }
                                                    }
                                                    $sColor = $ast['status_color'] ?? '#3b82f6';
                                            ?>
                                                <tr onclick="window.location='varlik_detay?id=<?= $ast['id'] ?>&view=assets'" style="cursor:pointer;">
                                                    <td class="px-3" style="width: 50px;">
                                                        <?php if ($imgUrl): ?>
                                                            <img src="<?= htmlspecialchars($imgUrl) ?>" class="rounded-lg border p-1" style="width: 44px; height: 44px; object-fit: contain; background: #fff;">
                                                        <?php else: ?>
                                                            <div class="rounded-lg d-flex align-items-center justify-content-center border" style="width: 44px; height: 44px; background: rgba(59,130,246,0.08); color: #3b82f6;">
                                                                <i class="fas fa-desktop fa-lg"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="font-weight-bold text-dark mb-1" style="font-size: 14px;"><?= htmlspecialchars($ast['name']) ?></div>
                                                        <div class="text-muted small"><i class="fas fa-barcode mr-1 opacity-75"></i><?= htmlspecialchars($ast['asset_tag'] ?: '-') ?></div>
                                                    </td>
                                                    <td class="text-right px-3">
                                                        <span class="badge px-3 py-1 text-white shadow-sm" style="background-color: <?= htmlspecialchars($sColor) ?>; border-radius: 20px; font-weight: 600;">
                                                            <?= htmlspecialchars($ast['status_name'] ?? ($isTr ? 'Atanmış' : 'Assigned')) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php 
                                                endforeach; 
                                            else:
                                            ?>
                                                <tr>
                                                    <td class="text-center py-5 text-muted">
                                                        <i class="fas fa-desktop fa-3x mb-3 opacity-25 d-block"></i>
                                                        <?= $isTr ? 'Üzerinize zimmetli herhangi bir cihaz bulunmuyor.' : 'No assigned assets.' ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- TAB 2: LICENSES -->
                            <div class="tab-pane fade" id="p-licenses" role="tabpanel">
                                <div class="table-responsive">
                                    <table class="table personnel-table mb-0 align-middle">
                                        <tbody>
                                            <?php
                                            $myLic = $pdo->prepare("
                                                SELECT l.id, l.software_name as name, l.license_key as tag, l.image
                                                FROM asset_license_checkouts lc
                                                JOIN asset_licenses l ON lc.license_id = l.id
                                                WHERE lc.user_id = ? AND l.deleted_at IS NULL
                                                ORDER BY lc.created_at DESC
                                            ");
                                            $myLic->execute([$user_id]);
                                            $licenses = $myLic->fetchAll();

                                            if (count($licenses) > 0):
                                                foreach ($licenses as $lic):
                                                    $imgUrl = null;
                                                    if (!empty($lic['image'])) {
                                                        $cImg = ltrim($lic['image'], '/');
                                                        if (str_starts_with($cImg, 'public/')) $cImg = substr($cImg, 7);
                                                        if (!str_starts_with($cImg, 'uploads/')) $cImg = 'uploads/licenses/' . $cImg;
                                                        if (file_exists(__DIR__ . '/../../public/' . $cImg)) {
                                                            $imgUrl = $base_url . 'public/' . $cImg;
                                                        }
                                                    }
                                            ?>
                                                <tr onclick="window.location='varlik_detay?id=<?= $lic['id'] ?>&view=licenses'" style="cursor:pointer;">
                                                    <td class="px-3" style="width: 50px;">
                                                        <?php if ($imgUrl): ?>
                                                            <img src="<?= htmlspecialchars($imgUrl) ?>" class="rounded-lg border p-1" style="width: 44px; height: 44px; object-fit: contain; background: #fff;">
                                                        <?php else: ?>
                                                            <div class="rounded-lg d-flex align-items-center justify-content-center border" style="width: 44px; height: 44px; background: rgba(16,185,129,0.08); color: #10b981;">
                                                                <i class="fas fa-key fa-lg"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="font-weight-bold text-dark mb-1" style="font-size: 14px;"><?= htmlspecialchars($lic['name']) ?></div>
                                                        <div class="text-muted small"><i class="fas fa-shield-alt mr-1 opacity-75"></i><?= htmlspecialchars(mb_strimwidth($lic['tag'] ?: '-', 0, 20, '...')) ?></div>
                                                    </td>
                                                    <td class="text-right px-3">
                                                        <span class="badge badge-success px-3 py-1 shadow-sm" style="border-radius: 20px; font-weight: 600;">
                                                            <?= $isTr ? 'Aktif Lisans' : 'Active License' ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php 
                                                endforeach; 
                                            else:
                                            ?>
                                                <tr>
                                                    <td class="text-center py-5 text-muted">
                                                        <i class="fas fa-key fa-3x mb-3 opacity-25 d-block"></i>
                                                        <?= $isTr ? 'Üzerinize zimmetli lisans bulunmuyor.' : 'No assigned licenses.' ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- TAB 3: ACCESSORIES -->
                            <div class="tab-pane fade" id="p-accessories" role="tabpanel">
                                <div class="table-responsive">
                                    <table class="table personnel-table mb-0 align-middle">
                                        <tbody>
                                            <?php
                                            $myAcc = $pdo->prepare("
                                                SELECT a.id, a.name, a.serial_no as tag, a.image, ac.quantity
                                                FROM asset_accessory_checkouts ac
                                                JOIN asset_accessories a ON ac.accessory_id = a.id
                                                WHERE ac.user_id = ? AND a.deleted_at IS NULL
                                                ORDER BY ac.created_at DESC
                                            ");
                                            $myAcc->execute([$user_id]);
                                            $accessories = $myAcc->fetchAll();

                                            if (count($accessories) > 0):
                                                foreach ($accessories as $acc):
                                                    $imgUrl = null;
                                                    if (!empty($acc['image'])) {
                                                        $cImg = ltrim($acc['image'], '/');
                                                        if (str_starts_with($cImg, 'public/')) $cImg = substr($cImg, 7);
                                                        if (!str_starts_with($cImg, 'uploads/')) $cImg = 'uploads/accessories/' . $cImg;
                                                        if (file_exists(__DIR__ . '/../../public/' . $cImg)) {
                                                            $imgUrl = $base_url . 'public/' . $cImg;
                                                        }
                                                    }
                                            ?>
                                                <tr onclick="window.location='varlik_detay?id=<?= $acc['id'] ?>&view=accessories'" style="cursor:pointer;">
                                                    <td class="px-3" style="width: 50px;">
                                                        <?php if ($imgUrl): ?>
                                                            <img src="<?= htmlspecialchars($imgUrl) ?>" class="rounded-lg border p-1" style="width: 44px; height: 44px; object-fit: contain; background: #fff;">
                                                        <?php else: ?>
                                                            <div class="rounded-lg d-flex align-items-center justify-content-center border" style="width: 44px; height: 44px; background: rgba(245,158,11,0.08); color: #f59e0b;">
                                                                <i class="fas fa-plug fa-lg"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="font-weight-bold text-dark mb-1" style="font-size: 14px;"><?= htmlspecialchars($acc['name']) ?></div>
                                                        <div class="text-muted small"><i class="fas fa-tag mr-1 opacity-75"></i><?= htmlspecialchars($acc['tag'] ?: '-') ?></div>
                                                    </td>
                                                    <td class="text-right px-3">
                                                        <span class="badge badge-warning text-dark px-3 py-1 shadow-sm" style="border-radius: 20px; font-weight: 600;">
                                                            <?= $acc['quantity'] ?> <?= $isTr ? 'Adet' : 'Qty' ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php 
                                                endforeach; 
                                            else:
                                            ?>
                                                <tr>
                                                    <td class="text-center py-5 text-muted">
                                                        <i class="fas fa-plug fa-3x mb-3 opacity-25 d-block"></i>
                                                        <?= $isTr ? 'Üzerinize zimmetli aksesuar bulunmuyor.' : 'No assigned accessories.' ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- TAB 4: CONSUMABLES -->
                            <div class="tab-pane fade" id="p-consumables" role="tabpanel">
                                <?php
                                $currYear = date('Y');
                                $myCon = $pdo->prepare("
                                    SELECT c.id, c.name, c.item_no as tag, c.image, cc.quantity, cc.created_at as checkout_date
                                    FROM asset_consumable_checkouts cc
                                    JOIN asset_consumables c ON cc.consumable_id = c.id
                                    WHERE cc.user_id = ? AND c.deleted_at IS NULL AND YEAR(cc.created_at) = ?
                                    ORDER BY cc.created_at DESC
                                    LIMIT 10
                                ");
                                $myCon->execute([$user_id, $currYear]);
                                $consumables = $myCon->fetchAll();

                                $isYearFiltered = true;
                                if (empty($consumables)) {
                                    $isYearFiltered = false;
                                    $myCon2 = $pdo->prepare("
                                        SELECT c.id, c.name, c.item_no as tag, c.image, cc.quantity, cc.created_at as checkout_date
                                        FROM asset_consumable_checkouts cc
                                        JOIN asset_consumables c ON cc.consumable_id = c.id
                                        WHERE cc.user_id = ? AND c.deleted_at IS NULL
                                        ORDER BY cc.created_at DESC
                                        LIMIT 10
                                    ");
                                    $myCon2->execute([$user_id]);
                                    $consumables = $myCon2->fetchAll();
                                }
                                ?>

                                <?php if (!empty($consumables)): ?>
                                    <div class="p-2 px-3 bg-light border-bottom text-muted small d-flex justify-content-between align-items-center">
                                        <span>
                                            <i class="fas fa-calendar-alt text-primary mr-1"></i>
                                            <?= $isTr 
                                                ? ($isYearFiltered ? "Son 10 Teslimat ($currYear Yılı)" : "Son 10 Teslimat Kaydı") 
                                                : ($isYearFiltered ? "Recent 10 Deliveries ($currYear)" : "Recent 10 Deliveries") ?>
                                        </span>
                                        <span class="badge badge-pill badge-secondary font-weight-normal"><?= count($consumables) ?> <?= $isTr ? 'Kayıt' : 'Records' ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="table-responsive">
                                    <table class="table personnel-table mb-0 align-middle">
                                        <tbody>
                                            <?php if (count($consumables) > 0):
                                                foreach ($consumables as $con):
                                                    $imgUrl = null;
                                                    if (!empty($con['image'])) {
                                                        $cImg = ltrim($con['image'], '/');
                                                        if (str_starts_with($cImg, 'public/')) $cImg = substr($cImg, 7);
                                                        if (!str_starts_with($cImg, 'uploads/')) $cImg = 'uploads/consumables/' . $cImg;
                                                        if (file_exists(__DIR__ . '/../../public/' . $cImg)) {
                                                            $imgUrl = $base_url . 'public/' . $cImg;
                                                        }
                                                    }
                                                    $cDate = !empty($con['checkout_date']) ? date('d.m.Y H:i', strtotime($con['checkout_date'])) : '-';
                                            ?>
                                                <tr onclick="window.location='varlik_detay?id=<?= $con['id'] ?>&view=consumables'" style="cursor:pointer;">
                                                    <td class="px-3" style="width: 50px;">
                                                        <?php if ($imgUrl): ?>
                                                            <img src="<?= htmlspecialchars($imgUrl) ?>" class="rounded-lg border p-1" style="width: 44px; height: 44px; object-fit: contain; background: #fff;">
                                                        <?php else: ?>
                                                            <div class="rounded-lg d-flex align-items-center justify-content-center border" style="width: 44px; height: 44px; background: rgba(139,92,246,0.08); color: #8b5cf6;">
                                                                <i class="fas fa-box-open fa-lg"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="font-weight-bold text-dark mb-1" style="font-size: 14px;"><?= htmlspecialchars($con['name']) ?></div>
                                                        <div class="text-muted small d-flex align-items-center flex-wrap" style="gap: 12px;">
                                                            <span><i class="far fa-clock mr-1 text-primary"></i><?= $cDate ?></span>
                                                            <?php if (!empty($con['tag'])): ?>
                                                                <span><i class="fas fa-hashtag mr-1 opacity-75"></i><?= htmlspecialchars($con['tag']) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="text-right px-3">
                                                        <span class="badge badge-info px-3 py-1 shadow-sm" style="border-radius: 20px; font-weight: 600;">
                                                            <?= $con['quantity'] ?> <?= $isTr ? 'Adet Teslim' : 'Delivered' ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php 
                                                endforeach; 
                                            else:
                                            ?>
                                                <tr>
                                                    <td class="text-center py-5 text-muted">
                                                        <i class="fas fa-box-open fa-3x mb-3 opacity-25 d-block"></i>
                                                        <?= $isTr ? 'Üzerinize teslim edilen sarf malzeme bulunmuyor.' : 'No delivered consumables.' ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php } ?>