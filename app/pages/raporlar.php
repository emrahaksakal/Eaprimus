<?php
// app/pages/raporlar.php

// ticket_ratings tablosunun varlığını garantile
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_ratings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        user_id INT NOT NULL,
        agent_id INT NULL,
        rating INT NOT NULL,
        comment TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ticket_rate (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
} catch (Exception $e) {}

// Dinamik temsilci sütun ismini bul
$personnelCol = 'assigned_to';
try {
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmtCol = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'personnel_id'");
    $stmtCol->execute([$dbName]);
    $hasPersonnel = (int) $stmtCol->fetchColumn() > 0;
    $personnelCol = $hasPersonnel ? 'personnel_id' : 'assigned_to';
} catch (Throwable $e) {}

if (!function_exists('format_log_entry')) {
    function format_log_entry($log, $isTr) {
        $eventName = $log['event_type'];
        if ($isTr) {
            $eventName = match(strtolower($log['event_type'])) {
                'created', 'create', 'timeline_created' => 'Oluşturuldu',
                'updated', 'update', 'timeline_updated' => 'Güncellendi',
                'deleted', 'delete', 'timeline_deleted' => 'Silindi',
                'checkout', 'timeline_checkout' => 'Zimmetlendi',
                'checkin', 'timeline_checkin' => 'Geri Alındı',
                'restored', 'timeline_restored' => 'Geri Yüklendi',
                'handover' => 'Teslim Edildi',
                default => $log['event_type']
            };
        } else {
            $eventName = match(strtolower($log['event_type'])) {
                'created', 'create', 'timeline_created' => 'Created',
                'updated', 'update', 'timeline_updated' => 'Updated',
                'deleted', 'delete', 'timeline_deleted' => 'Deleted',
                'checkout', 'timeline_checkout' => 'Checked Out',
                'checkin', 'timeline_checkin' => 'Checked In',
                'restored', 'timeline_restored' => 'Restored',
                'handover' => 'Handover',
                default => ucfirst($log['event_type'])
            };
        }

        $itemTypeTr = $log['item_type'];
        if ($isTr) {
            $itemTypeTr = match($log['item_type']) {
                'asset' => 'Demirbaş',
                'license' => 'Lisans',
                'accessory' => 'Aksesuar',
                'consumable' => 'Sarf',
                'component' => 'Bileşen',
                'people', 'user' => 'Personel',
                default => $log['item_type']
            };
        } else {
            $itemTypeTr = ucfirst($log['item_type']);
        }

        $rawDesc = $log['event_description'] ?? '';
        $rawDesc = str_replace(["—", "–", "→", "!'", "!’", " ! ' ", " !' ", "â€”", "â†’"], " -> ", $rawDesc);
        if (function_exists('normalize_turkish_mojibake')) {
            $rawDesc = normalize_turkish_mojibake($rawDesc);
        }

        $changed = '';

        if (strpos($rawDesc, ' / ') !== false) {
            $parts = explode(' / ', $rawDesc, 2);
            $selectedDesc = $isTr ? trim($parts[0]) : trim($parts[1]);
        } else {
            $selectedDesc = trim($rawDesc);
        }

        if (strpos($selectedDesc, 'Güncellenenler: ') !== false) {
            $p = explode('Güncellenenler: ', $selectedDesc, 2);
            $cleanDesc = trim($p[0]);
            $changed = trim($p[1]);
        } elseif (strpos($selectedDesc, 'Changes: ') !== false) {
            $p = explode('Changes: ', $selectedDesc, 2);
            $cleanDesc = trim($p[0]);
            $changed = trim($p[1]);
        } elseif (preg_match('/^(Bilgi güncellendi|Info updated|Kullanıcı bilgileri güncellendi|User information updated|Cihaz API donanım bilgisi güncellendi|Device API hardware info updated):\s*(.+)$/ui', $selectedDesc, $m)) {
            $hdr = mb_strtolower($m[1]);
            if (strpos($hdr, 'kullanıcı') !== false || strpos($hdr, 'user') !== false) {
                $cleanDesc = $isTr ? 'Kullanıcı bilgileri güncellendi.' : 'User information updated.';
            } elseif (strpos($hdr, 'api') !== false || strpos($hdr, 'hardware') !== false) {
                $cleanDesc = $isTr ? 'Cihaz API donanım bilgisi güncellendi.' : 'Device API hardware info updated.';
            } else {
                $cleanDesc = $isTr ? 'Bilgi güncellendi.' : 'Info updated.';
            }
            $changed = trim($m[2]);
        } else {
            $cleanDesc = $selectedDesc;
        }

        if (!$isTr) {
            $cleanDesc = str_ireplace(
                ['Bilgi güncellendi', 'Kullanıcı bilgileri güncellendi', 'Lisans', 'cihaz üzerine', 'atandı', 'geri alındı', 'silindi', 'oluşturuldu', 'kullanıcısına', 'varlığına'],
                ['Info updated', 'User information updated', 'License', 'assigned to device', 'assigned', 'checked in', 'deleted', 'created', 'to user', 'to asset'],
                $cleanDesc
            );
            $changed = str_replace(
                ['Maliyet:', 'Alım Tarihi:', 'Şirket:', 'Tedarikçi:', 'Yok', 'Durum:', 'Hazır', 'Arızalı', 'Hurda', 'Atanmış', 'Geri Alındı'],
                ['Cost:', 'Purchase Date:', 'Company:', 'Supplier:', 'None', 'Status:', 'Ready', 'Faulty', 'Scrapped', 'Assigned', 'Returned'],
                $changed
            );
        }

        return [
            'action' => $eventName,
            'type' => $itemTypeTr,
            'desc' => $cleanDesc,
            'changes' => $changed
        ];
    }
}

$view = $_GET['view'] ?? 'activity'; 
$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'get_all_activity_logs') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $joins_r = "
        LEFT JOIN assets ast ON (at.item_type = 'asset' AND at.asset_id = ast.id)
        LEFT JOIN asset_licenses lic ON (at.item_type = 'license' AND at.asset_id = lic.id)
        LEFT JOIN asset_accessories acc ON (at.item_type = 'accessory' AND at.asset_id = acc.id)
        LEFT JOIN asset_consumables con ON (at.item_type = 'consumable' AND at.asset_id = con.id)
        LEFT JOIN asset_components cmp ON (at.item_type = 'component' AND at.asset_id = cmp.id)
        LEFT JOIN users ut ON ((at.item_type = 'user' OR at.item_type = 'people') AND at.asset_id = ut.id)
    ";
    
    $where_r = " WHERE at.is_deleted = 0 ";
    $params_r = [];

    $q_search = trim($_POST['search'] ?? $_GET['search'] ?? '');
    $f_date_from = trim($_POST['date_from'] ?? $_GET['date_from'] ?? '');
    $f_date_to = trim($_POST['date_to'] ?? $_GET['date_to'] ?? '');
    $f_event_type = trim($_POST['event_type'] ?? $_GET['event_type'] ?? '');
    $f_item_type = trim($_POST['item_type'] ?? $_GET['item_type'] ?? '');

    if (!empty($f_date_from)) {
        $where_r .= " AND at.created_at >= ? ";
        $params_r[] = $f_date_from . " 00:00:00";
    }
    if (!empty($f_date_to)) {
        $where_r .= " AND at.created_at <= ? ";
        $params_r[] = $f_date_to . " 23:59:59";
    }
    if (!empty($f_event_type)) {
        if ($f_event_type === 'created') {
            $where_r .= " AND at.event_type IN ('created', 'create', 'timeline_created') ";
        } elseif ($f_event_type === 'updated') {
            $where_r .= " AND at.event_type IN ('updated', 'update', 'timeline_updated') ";
        } elseif ($f_event_type === 'deleted') {
            $where_r .= " AND at.event_type IN ('deleted', 'delete', 'timeline_deleted') ";
        } elseif ($f_event_type === 'checkout') {
            $where_r .= " AND at.event_type IN ('checkout', 'timeline_checkout') ";
        } elseif ($f_event_type === 'checkin') {
            $where_r .= " AND at.event_type IN ('checkin', 'timeline_checkin') ";
        } else {
            $where_r .= " AND at.event_type = ? ";
            $params_r[] = $f_event_type;
        }
    }
    if (!empty($f_item_type)) {
        if ($f_item_type === 'user' || $f_item_type === 'people') {
            $where_r .= " AND at.item_type IN ('user', 'people') ";
        } else {
            $where_r .= " AND at.item_type = ? ";
            $params_r[] = $f_item_type;
        }
    }
    
    if(!empty($q_search)) {
        $terms = ["%$q_search%"];
        if (preg_match('/^(.*)(lar|ler)$/ui', $q_search, $matches)) {
            $terms[] = "%" . trim($matches[1]) . "%";
        }
        
        $searchConditions = [];
        $searchParams = [];
        foreach ($terms as $t) {
            $searchConditions[] = "at.event_description LIKE ?";
            $searchConditions[] = "u.fullname LIKE ?";
            $searchConditions[] = "ast.name LIKE ?";
            $searchConditions[] = "ast.asset_tag LIKE ?";
            $searchConditions[] = "lic.software_name LIKE ?";
            $searchConditions[] = "acc.name LIKE ?";
            $searchConditions[] = "con.name LIKE ?";
            $searchConditions[] = "cmp.name LIKE ?";
            $searchConditions[] = "ut.fullname LIKE ?";
            
            for ($i = 0; $i < 9; $i++) {
                $searchParams[] = $t;
            }
        }
        
        $where_r .= " AND (" . implode(" OR ", $searchConditions) . ") ";
        $params_r = array_merge($params_r, $searchParams);
    }
    
    $stmt = $pdo->prepare("SELECT at.*, u.fullname FROM asset_timeline at LEFT JOIN users u ON at.user_id = u.id $joins_r $where_r ORDER BY at.created_at DESC");
    $stmt->execute($params_r);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $resolvedLogs = [];
    foreach ($logs as $log) {
        $formatted = format_log_entry($log, $isTr);
        
        $itemName = '-';
        $targetId = intval($log['asset_id']);
        if ($log['item_type'] == 'asset') {
            $itemName = $pdo->query("SELECT name FROM assets WHERE id = $targetId")->fetchColumn();
        } elseif ($log['item_type'] == 'license') {
            $itemName = $pdo->query("SELECT software_name FROM asset_licenses WHERE id = $targetId")->fetchColumn();
        } elseif ($log['item_type'] == 'accessory') {
            $itemName = $pdo->query("SELECT name FROM asset_accessories WHERE id = $targetId")->fetchColumn();
        } elseif ($log['item_type'] == 'consumable') {
            $itemName = $pdo->query("SELECT name FROM asset_consumables WHERE id = $targetId")->fetchColumn();
        } elseif ($log['item_type'] == 'component') {
            $itemName = $pdo->query("SELECT name FROM asset_components WHERE id = $targetId")->fetchColumn();
        } elseif ($log['item_type'] == 'people' || $log['item_type'] == 'user') {
            $itemName = $pdo->query("SELECT fullname FROM users WHERE id = $targetId")->fetchColumn();
        }
        if (!$itemName) {
            $itemName = "ID: " . $log['asset_id'];
        }

        $resolvedLogs[] = [
            'date' => date('d.m.Y H:i', strtotime($log['created_at'])),
            'user' => $log['fullname'] ?: 'Sistem',
            'action' => $formatted['action'],
            'type' => $formatted['type'],
            'target' => $itemName,
            'desc' => $formatted['desc'],
            'changes' => $formatted['changes']
        ];
    }
    
    echo json_encode(['status' => 'success', 'data' => $resolvedLogs]);
    exit();
}
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>

<div class="content-wrapper" style="margin-left:0 !important;">
    <div class="content-header border-bottom mb-4 bg-white shadow-sm">
        <div class="container-fluid px-4 py-2">
            <h1 class="m-0 font-weight-bold text-dark" style="font-size:1.5rem;"><i class="fas fa-chart-line mr-2 text-primary"></i><?= __("reports") ?? 'Raporlar' ?></h1>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid px-4">
            <!-- Navigation Tabs -->
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <ul class="nav nav-pills custom-pills">
                    <li class="nav-item mr-2">
                        <a class="nav-link <?= $view == 'activity' ? 'active' : '' ?>" href="raporlar?view=activity">
                            <i class="fas fa-history mr-2"></i> <?= $isTr ? 'Sistem Etkinlik Logları' : 'System Activity Logs' ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $view == 'consumables' ? 'active' : '' ?>" href="raporlar?view=consumables">
                            <i class="fas fa-box-open mr-2"></i> <?= $isTr ? 'Sarf Malzeme Devir & Tüketim Analizi' : 'Consumable Usage & Rollover Analytics' ?>
                        </a>
                    </li>
                    <li class="nav-item ml-2">
                        <a class="nav-link <?= $view == 'csat' ? 'active' : '' ?>" href="raporlar?view=csat">
                            <i class="fas fa-star text-warning mr-2"></i> <?= $isTr ? 'Müşteri Memnuniyeti' : 'CSAT' ?>
                        </a>
                    </li>
                    <li class="nav-item ml-2">
                        <a class="nav-link <?= $view == 'analytics' ? 'active' : '' ?>" href="raporlar?view=analytics">
                            <i class="fas fa-chart-pie text-info mr-2"></i> <?= $isTr ? 'Grafik Raporlar & Analiz' : 'Charts & Analytics' ?>
                        </a>
                    </li>
                </ul>
                <div class="d-flex gap-2">
                    <?php if ($view == 'activity'): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm px-3 shadow-sm mr-2" onclick="bulkDeleteLogs()" style="border-radius:8px;">
                            <i class="fas fa-trash-alt mr-1"></i> <?= $isTr ? 'Seçilenleri Sil' : 'Delete Selected' ?>
                        </button>
                        <button type="button" class="btn btn-danger btn-sm px-3 shadow-sm" data-toggle="modal" data-target="#activityPdfModal" style="border-radius:8px;">
                            <i class="fas fa-file-pdf mr-1"></i> <?= $isTr ? 'PDF Rapor Al' : 'Export PDF' ?>
                        </button>
                    <?php elseif ($view == 'consumables'): ?>
                        <button type="button" class="btn btn-danger btn-sm px-3 shadow-sm" data-toggle="modal" data-target="#consumablesPdfModal" style="border-radius:8px;">
                            <i class="fas fa-file-pdf mr-1"></i> <?= $isTr ? 'PDF Rapor Al' : 'Export PDF' ?>
                        </button>
                    <?php elseif ($view == 'csat'): ?>
                        <button type="button" class="btn btn-danger btn-sm px-3 shadow-sm" data-toggle="modal" data-target="#csatPdfModal" style="border-radius:8px;">
                            <i class="fas fa-file-pdf mr-1"></i> <?= $isTr ? 'PDF Rapor Al' : 'Export PDF' ?>
                        </button>
                    <?php elseif ($view == 'analytics'): ?>
                        <button type="button" class="btn btn-danger btn-sm px-3 shadow-sm" data-toggle="modal" data-target="#analyticsPdfModal" style="border-radius:8px;">
                            <i class="fas fa-file-pdf mr-1"></i> <?= $isTr ? 'PDF Rapor Al' : 'Export PDF' ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <style>
                .custom-pills .nav-link {
                    background: #fff;
                    border: 1px solid #e2e8f0;
                    color: #64748b;
                    font-weight: 600;
                    border-radius: 10px;
                    padding: 8px 20px;
                    transition: 0.3s;
                }
                .custom-pills .nav-link.active {
                    background: #3b82f6;
                    color: #fff;
                    border-color: #3b82f6;
                    box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.2);
                }
                body.dark-mode .custom-pills .nav-link {
                    background: #1a1f26;
                    border-color: #313a48;
                    color: #94a3b8;
                }
                body.dark-mode .custom-pills .nav-link.active {
                    background: #3b82f6;
                    color: #fff;
                    border-color: #3b82f6;
                }
                .badge-success-soft { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
                .badge-info-soft { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }
                .badge-warning-soft { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
                .badge-danger-soft { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
                .badge-secondary-soft { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
                
                .ds-table th { 
                    text-transform: uppercase; 
                    font-size: 11.5px; 
                    letter-spacing: 0.06em; 
                    padding: 14px 14px !important; 
                    font-weight: 700;
                    color: #475569 !important;
                    background-color: #f8fafc !important;
                    border-bottom: 2px solid #e2e8f0 !important;
                }
                .ds-table td { 
                    vertical-align: middle !important; 
                    padding: 12px 14px !important; 
                    border-bottom: 1px solid #f1f5f9 !important;
                    font-size: 13.5px;
                }
                .ds-table tbody tr:hover {
                    background-color: #f1f5f9 !important;
                }
                body.dark-mode .ds-table th {
                    background-color: #151921 !important;
                    color: #94a3b8 !important;
                    border-bottom: 2px solid #2d343c !important;
                }
                body.dark-mode .ds-table td {
                    border-bottom: 1px solid #2d343c !important;
                }
                body.dark-mode .ds-table tbody tr:hover {
                    background-color: #1d242f !important;
                }

                .excel-row-purchased, .excel-row-purchased td { background-color: #475569 !important; color: #ffffff !important; font-weight: 700; }
                .excel-row-rollover, .excel-row-rollover td { background-color: #ea580c !important; color: #ffffff !important; font-weight: 700; }
                .excel-row-used, .excel-row-used td { background-color: #16a34a !important; color: #ffffff !important; font-weight: 700; }
                .excel-row-current, .excel-row-current td { background-color: #0284c7 !important; color: #ffffff !important; font-weight: 700; }
                
                .excel-row-purchased:hover, .excel-row-purchased:hover td { background-color: #334155 !important; color: #ffffff !important; }
                .excel-row-rollover:hover, .excel-row-rollover:hover td { background-color: #c2410c !important; color: #ffffff !important; }
                .excel-row-used:hover, .excel-row-used:hover td { background-color: #15803d !important; color: #ffffff !important; }
                .excel-row-current:hover, .excel-row-current:hover td { background-color: #0369a1 !important; color: #ffffff !important; }

                .excel-cell-val { text-align: center; font-size: 1.1rem; padding: 12px !important; }
            </style>

            <?php if ($view == 'activity'): ?>
            <?php
            if (isset($_POST['action']) && $_POST['action'] == 'bulk_delete_logs') {
                $ids = array_map('intval', explode(',', $_POST['ids'] ?? ''));
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("UPDATE asset_timeline SET is_deleted = 1 WHERE id IN ($placeholders)")->execute($ids);
                }
                $_SESSION['mesaj'] = $isTr ? "Seçili kayıtlar silindi." : "Selected records deleted.";
                header("Location: raporlar?view=activity"); exit;
            }

            $limit = intval($_GET['per_page'] ?? $_SESSION['per_page'] ?? 20);
            $page = intval($_GET['page'] ?? 1);
            $offset = ($page - 1) * $limit;
            
            $where_r = " WHERE at.is_deleted = 0 ";
            $params_r = [];

            $q_search = trim($_GET['search'] ?? '');
            $f_date_from = trim($_GET['date_from'] ?? '');
            $f_date_to = trim($_GET['date_to'] ?? '');
            $f_event_type = trim($_GET['event_type'] ?? '');
            $f_item_type = trim($_GET['item_type'] ?? '');

            if (!empty($f_date_from)) {
                $where_r .= " AND at.created_at >= ? ";
                $params_r[] = $f_date_from . " 00:00:00";
            }
            if (!empty($f_date_to)) {
                $where_r .= " AND at.created_at <= ? ";
                $params_r[] = $f_date_to . " 23:59:59";
            }
            if (!empty($f_event_type)) {
                if ($f_event_type === 'created') {
                    $where_r .= " AND at.event_type IN ('created', 'create', 'timeline_created') ";
                } elseif ($f_event_type === 'updated') {
                    $where_r .= " AND at.event_type IN ('updated', 'update', 'timeline_updated') ";
                } elseif ($f_event_type === 'deleted') {
                    $where_r .= " AND at.event_type IN ('deleted', 'delete', 'timeline_deleted') ";
                } elseif ($f_event_type === 'checkout') {
                    $where_r .= " AND at.event_type IN ('checkout', 'timeline_checkout') ";
                } elseif ($f_event_type === 'checkin') {
                    $where_r .= " AND at.event_type IN ('checkin', 'timeline_checkin') ";
                } else {
                    $where_r .= " AND at.event_type = ? ";
                    $params_r[] = $f_event_type;
                }
            }
            if (!empty($f_item_type)) {
                if ($f_item_type === 'user' || $f_item_type === 'people') {
                    $where_r .= " AND at.item_type IN ('user', 'people') ";
                } else {
                    $where_r .= " AND at.item_type = ? ";
                    $params_r[] = $f_item_type;
                }
            }
            
            $joins_r = "
                LEFT JOIN assets ast ON (at.item_type = 'asset' AND at.asset_id = ast.id)
                LEFT JOIN asset_licenses lic ON (at.item_type = 'license' AND at.asset_id = lic.id)
                LEFT JOIN asset_accessories acc ON (at.item_type = 'accessory' AND at.asset_id = acc.id)
                LEFT JOIN asset_consumables con ON (at.item_type = 'consumable' AND at.asset_id = con.id)
                LEFT JOIN asset_components cmp ON (at.item_type = 'component' AND at.asset_id = cmp.id)
                LEFT JOIN users ut ON ((at.item_type = 'user' OR at.item_type = 'people') AND at.asset_id = ut.id)
            ";

            if(!empty($q_search)) {
                $terms = ["%$q_search%"];
                if (preg_match('/^(.*)(lar|ler)$/ui', $q_search, $matches)) {
                    $terms[] = "%" . trim($matches[1]) . "%";
                }
                
                $searchConditions = [];
                $searchParams = [];
                foreach ($terms as $t) {
                    $searchConditions[] = "at.event_description LIKE ?";
                    $searchConditions[] = "u.fullname LIKE ?";
                    $searchConditions[] = "ast.name LIKE ?";
                    $searchConditions[] = "ast.asset_tag LIKE ?";
                    $searchConditions[] = "lic.software_name LIKE ?";
                    $searchConditions[] = "acc.name LIKE ?";
                    $searchConditions[] = "con.name LIKE ?";
                    $searchConditions[] = "cmp.name LIKE ?";
                    $searchConditions[] = "ut.fullname LIKE ?";
                    
                    for ($i = 0; $i < 9; $i++) {
                        $searchParams[] = $t;
                    }
                }
                
                $where_r .= " AND (" . implode(" OR ", $searchConditions) . ") ";
                $params_r = array_merge($params_r, $searchParams);
            }

            $total_records = $pdo->prepare("SELECT COUNT(*) FROM asset_timeline at LEFT JOIN users u ON at.user_id = u.id $joins_r $where_r");
            $total_records->execute($params_r);
            $total_records = $total_records->fetchColumn();
            $total_pages = ceil($total_records / $limit);

            $stmt = $pdo->prepare("SELECT at.*, u.fullname FROM asset_timeline at LEFT JOIN users u ON at.user_id = u.id $joins_r $where_r ORDER BY at.created_at DESC LIMIT $limit OFFSET $offset");
            $stmt->execute($params_r);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <!-- Dynamic Filters Bar -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius:15px; background: #ffffff;">
                <div class="card-body p-3">
                    <form method="GET" action="raporlar" id="filterForm" class="row align-items-end g-2">
                        <input type="hidden" name="view" value="activity">
                        <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                            <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Arama' : 'Search' ?></label>
                            <div class="input-group input-group-sm">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
                                </div>
                                <input type="text" name="search" class="form-control border-left-0" placeholder="<?= $isTr ? 'Personel, Cihaz, Açıklama ara...' : 'Search personnel, asset, details...' ?>" value="<?= htmlspecialchars($q_search) ?>">
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-6 mb-2 mb-md-0">
                            <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Başlangıç Tarihi' : 'Start Date' ?></label>
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($f_date_from) ?>">
                        </div>
                        <div class="col-md-2 col-sm-6 mb-2 mb-md-0">
                            <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Bitiş Tarihi' : 'End Date' ?></label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($f_date_to) ?>">
                        </div>
                        <div class="col-md-2 col-sm-6 mb-2 mb-md-0">
                            <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'İşlem Türü' : 'Action Type' ?></label>
                            <select name="event_type" class="form-control form-control-sm custom-select">
                                <option value=""><?= $isTr ? 'Tüm İşlemler' : 'All Actions' ?></option>
                                <option value="created" <?= $f_event_type === 'created' ? 'selected' : '' ?>><?= $isTr ? 'Oluşturuldu' : 'Created' ?></option>
                                <option value="updated" <?= $f_event_type === 'updated' ? 'selected' : '' ?>><?= $isTr ? 'Güncellendi' : 'Updated' ?></option>
                                <option value="checkout" <?= $f_event_type === 'checkout' ? 'selected' : '' ?>><?= $isTr ? 'Zimmetlendi' : 'Checked Out' ?></option>
                                <option value="checkin" <?= $f_event_type === 'checkin' ? 'selected' : '' ?>><?= $isTr ? 'Geri Alındı' : 'Checked In' ?></option>
                                <option value="deleted" <?= $f_event_type === 'deleted' ? 'selected' : '' ?>><?= $isTr ? 'Silindi' : 'Deleted' ?></option>
                                <option value="handover" <?= $f_event_type === 'handover' ? 'selected' : '' ?>><?= $isTr ? 'Teslim Edildi' : 'Handover' ?></option>
                            </select>
                        </div>
                        <div class="col-md-2 col-sm-6 mb-2 mb-md-0">
                            <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Modül / Tür' : 'Module / Type' ?></label>
                            <select name="item_type" class="form-control form-control-sm custom-select">
                                <option value=""><?= $isTr ? 'Tüm Türler' : 'All Types' ?></option>
                                <option value="asset" <?= $f_item_type === 'asset' ? 'selected' : '' ?>><?= $isTr ? 'Demirbaş' : 'Asset' ?></option>
                                <option value="license" <?= $f_item_type === 'license' ? 'selected' : '' ?>><?= $isTr ? 'Lisans' : 'License' ?></option>
                                <option value="accessory" <?= $f_item_type === 'accessory' ? 'selected' : '' ?>><?= $isTr ? 'Aksesuar' : 'Accessory' ?></option>
                                <option value="consumable" <?= $f_item_type === 'consumable' ? 'selected' : '' ?>><?= $isTr ? 'Sarf' : 'Consumable' ?></option>
                                <option value="component" <?= $f_item_type === 'component' ? 'selected' : '' ?>><?= $isTr ? 'Bileşen' : 'Component' ?></option>
                                <option value="user" <?= ($f_item_type === 'user' || $f_item_type === 'people') ? 'selected' : '' ?>><?= $isTr ? 'Personel' : 'Personnel' ?></option>
                            </select>
                        </div>
                        <div class="col-md-1 col-sm-12 d-flex gap-1">
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-filter mr-1"></i><?= $isTr ? 'Filtrele' : 'Filter' ?></button>
                            <a href="raporlar?view=activity" class="btn btn-light btn-sm ml-1" title="<?= $isTr ? 'Sıfırla' : 'Reset' ?>"><i class="fas fa-sync-alt"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow border-0" style="border-radius:15px; overflow:hidden;">
                <div class="card-header bg-white border-bottom p-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title font-weight-bold m-0 text-dark"><i class="fas fa-list text-primary mr-2"></i><?= $isTr ? 'Tüm Etkinlik Geçmişi' : 'All Activity History' ?></h5>
                    <div class="d-flex align-items-center">
                        <span class="badge badge-light px-3 py-2 border" style="border-radius:20px;"><?= $total_records ?> <?= $isTr ? 'Kayıt' : 'Records' ?></span>
                    </div>
                </div>

                <?php if($total_pages > 1): ?>
                <div class="card-footer bg-white border-bottom p-3 d-flex justify-content-between align-items-center">
                    <div class="small text-muted">
                        <?php 
                        if ($isTr) {
                            echo "$total_records kayıttan " . (($offset)+1) . "-" . min($offset+$limit, $total_records) . " arası gösteriliyor.";
                        } else {
                            echo "Showing " . (($offset)+1) . " to " . min($offset+$limit, $total_records) . " of $total_records entries.";
                        }
                        ?>
                    </div>
                    <nav>
                        <ul class="pagination pagination-sm m-0">
                                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="raporlar?view=activity&page=<?= $page-1 ?>&search=<?= urlencode($q_search) ?>&date_from=<?= urlencode($f_date_from) ?>&date_to=<?= urlencode($f_date_to) ?>&event_type=<?= urlencode($f_event_type) ?>&item_type=<?= urlencode($f_item_type) ?>"><i class="fas fa-chevron-left"></i></a>
                                </li>
                                <?php for($i=1; $i<=$total_pages; $i++): if($i < 4 || $i > $total_pages - 3 || ($i >= $page - 1 && $i <= $page + 1)): ?>
                                    <li class="page-item <?= ($i == $page) ? 'active' : '' ?>"><a class="page-link" href="raporlar?view=activity&page=<?= $i ?>&search=<?= urlencode($q_search) ?>&date_from=<?= urlencode($f_date_from) ?>&date_to=<?= urlencode($f_date_to) ?>&event_type=<?= urlencode($f_event_type) ?>&item_type=<?= urlencode($f_item_type) ?>"><?= $i ?></a></li>
                                <?php elseif($i == 4 || $i == $total_pages - 3): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; endfor; ?>
                                <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="raporlar?view=activity&page=<?= $page+1 ?>&search=<?= urlencode($q_search) ?>&date_from=<?= urlencode($f_date_from) ?>&date_to=<?= urlencode($f_date_to) ?>&event_type=<?= urlencode($f_event_type) ?>&item_type=<?= urlencode($f_item_type) ?>"><i class="fas fa-chevron-right"></i></a>
                                </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 text-sm ds-table" id="activity-logs-table">
                            <thead class="bg-light text-xs text-uppercase text-muted">
                                <tr>
                                    <th class="pl-4">
                                        <div class="custom-control custom-checkbox custom-checkbox-lg">
                                            <input type="checkbox" class="custom-control-input" id="checkAll">
                                            <label class="custom-control-label" for="checkAll"></label>
                                        </div>
                                    </th>
                                    <th><?= __('date') ?? 'Tarih' ?></th>
                                    <th><?= __('user') ?? 'Kullanıcı' ?></th>
                                    <th><?= __('action') ?? 'İşlem' ?></th>
                                    <th><?= __('type') ?? 'Tür' ?></th>
                                    <th><?= __('target') ?? 'Hedef / Varlık' ?></th>
                                    <th><?= $isTr ? 'Açıklama' : 'Description' ?></th>
                                    <th class="pr-4 text-right"><?= $isTr ? 'Değişiklikler' : 'Changes' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($logs as $log): 
                                    $formatted = format_log_entry($log, $isTr);
                                    
                                    $icon = 'fa-info-circle'; $badgeClass = 'badge-info-soft';
                                    if($log['event_type'] == 'created' || $log['event_type'] == 'create' || $log['event_type'] == 'timeline_created') { $icon = 'fa-plus-circle'; $badgeClass = 'badge-success-soft'; }
                                    elseif($log['event_type'] == 'updated' || $log['event_type'] == 'update' || $log['event_type'] == 'timeline_updated') { $icon = 'fa-edit'; $badgeClass = 'badge-info-soft'; }
                                    elseif($log['event_type'] == 'deleted' || $log['event_type'] == 'delete' || $log['event_type'] == 'timeline_deleted') { $icon = 'fa-trash'; $badgeClass = 'badge-danger-soft'; }
                                    elseif($log['event_type'] == 'checkout' || $log['event_type'] == 'handover' || $log['event_type'] == 'timeline_checkout') { $icon = 'fa-user-check'; $badgeClass = 'badge-warning-soft'; }
                                    elseif($log['event_type'] == 'checkin' || $log['event_type'] == 'timeline_checkin') { $icon = 'fa-undo'; $badgeClass = 'badge-secondary-soft'; }
                                    elseif($log['event_type'] == 'restored' || $log['event_type'] == 'timeline_restored') { $icon = 'fa-trash-restore'; $badgeClass = 'badge-success-soft'; }
                                    
                                    $itemName = '-';
                                    $itemLink = '#';
                                    $targetId = intval($log['asset_id']);
                                    if ($log['item_type'] == 'asset') {
                                        $itemName = $pdo->query("SELECT name FROM assets WHERE id = $targetId")->fetchColumn();
                                        $itemLink = "varlik-detay/$targetId?highlight_id=$targetId";
                                    } elseif ($log['item_type'] == 'license') {
                                        $itemName = $pdo->query("SELECT software_name FROM asset_licenses WHERE id = $targetId")->fetchColumn();
                                        $itemLink = "varliklar?view=licenses&highlight_id=$targetId";
                                    } elseif ($log['item_type'] == 'accessory') {
                                        $itemName = $pdo->query("SELECT name FROM asset_accessories WHERE id = $targetId")->fetchColumn();
                                        $itemLink = "varliklar?view=accessories&highlight_id=$targetId";
                                    } elseif ($log['item_type'] == 'consumable') {
                                        $itemName = $pdo->query("SELECT name FROM asset_consumables WHERE id = $targetId")->fetchColumn();
                                        $itemLink = "varliklar?view=consumables&highlight_id=$targetId";
                                    } elseif ($log['item_type'] == 'component') {
                                        $itemName = $pdo->query("SELECT name FROM asset_components WHERE id = $targetId")->fetchColumn();
                                        $itemLink = "varliklar?view=components&highlight_id=$targetId";
                                    } elseif ($log['item_type'] == 'people' || $log['item_type'] == 'user') {
                                        $itemName = $pdo->query("SELECT fullname FROM users WHERE id = $targetId")->fetchColumn();
                                        $itemLink = "kullanici-listele?highlight=$targetId";
                                    }
                                    if (!$itemName) $itemName = "ID: " . $log['asset_id'];
                                ?>
                                <tr>
                                    <td class="pl-4">
                                        <div class="custom-control custom-checkbox custom-checkbox-lg">
                                            <input type="checkbox" class="custom-control-input log-cb" id="log_<?= $log['id'] ?>" value="<?= $log['id'] ?>">
                                            <label class="custom-control-label" for="log_<?= $log['id'] ?>"></label>
                                        </div>
                                    </td>
                                    <td class="text-nowrap text-muted font-weight-bold small"><?= date('d.m.Y H:i', strtotime($log['created_at'])) ?></td>
                                    <td><strong><?= htmlspecialchars($log['fullname'] ?: 'Sistem') ?></strong></td>
                                    <td><span class="badge <?= $badgeClass ?> px-2 py-1"><i class="fas <?= $icon ?> mr-1"></i><?= htmlspecialchars($formatted['action']) ?></span></td>
                                    <td><span class="badge badge-secondary-soft px-2 py-1"><?= htmlspecialchars($formatted['type']) ?></span></td>
                                    <td>
                                        <?php if($itemLink !== '#'): ?>
                                            <a href="<?= $itemLink ?>" class="font-weight-bold text-primary"><i class="fas fa-link mr-1"></i><?= htmlspecialchars($itemName) ?></a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($itemName) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($formatted['desc']) ?></td>
                                    <td class="pr-4 text-right">
                                        <?php if(!empty($formatted['changes'])): 
                                            $tags = explode(',', $formatted['changes']);
                                            foreach($tags as $t): 
                                                $tClean = trim($t);
                                                if(empty($tClean)) continue;
                                        ?>
                                            <span class="change-tag text-left m-1 px-2 py-1" style="border-radius:6px;"><?= htmlspecialchars($tClean) ?></span>
                                        <?php endforeach; endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ACTIVITY PDF MODAL -->
            <div class="modal fade" id="activityPdfModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content border-0 shadow-lg" style="border-radius:15px; overflow:hidden;">
                        <div class="modal-header bg-dark text-white p-3">
                            <h5 class="modal-title font-weight-bold m-0 text-white">
                                <i class="fas fa-file-pdf text-danger mr-2"></i><?= $isTr ? 'Etkinlik Logları PDF Raporu' : 'Activity Logs PDF Report' ?>
                            </h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body p-4 bg-light">
                            <div class="mb-3">
                                <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-file-alt text-primary mr-1"></i><?= $isTr ? 'Sayfa Yönü' : 'Page Orientation' ?></label>
                                <div class="d-flex gap-3">
                                    <div class="custom-control custom-radio mr-3">
                                        <input type="radio" id="act_orient_l" name="act_pdf_orientation" value="landscape" class="custom-control-input" checked>
                                        <label class="custom-control-label font-weight-bold" for="act_orient_l"><?= $isTr ? 'Yatay (Landscape)' : 'Landscape' ?></label>
                                    </div>
                                    <div class="custom-control custom-radio">
                                        <input type="radio" id="act_orient_p" name="act_pdf_orientation" value="portrait" class="custom-control-input">
                                        <label class="custom-control-label font-weight-bold" for="act_orient_p"><?= $isTr ? 'Dikey (Portrait)' : 'Portrait' ?></label>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-globe text-info mr-1"></i><?= $isTr ? 'Rapor Dili' : 'Report Language' ?></label>
                                <select id="act_pdf_lang" class="form-control custom-select">
                                    <option value="tr" <?= $isTr ? 'selected' : '' ?>>Türkçe (Turkish)</option>
                                    <option value="en" <?= !$isTr ? 'selected' : '' ?>>English</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer bg-white p-3 d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary px-4" data-dismiss="modal"><?= $isTr ? 'Vazgeç' : 'Cancel' ?></button>
                            <button type="button" class="btn btn-danger px-4 font-weight-bold" onclick="runActivityPdfExport()">
                                <i class="fas fa-file-pdf mr-2"></i><?= $isTr ? 'PDF Oluştur' : 'Generate PDF' ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
                        <?php elseif ($view == 'csat'): ?>
            <!-- CSAT 5-STAR RATING & AGENT PERFORMANCE REPORT -->
            <?php 
            $avgRating = 5.0;
            $totalRatings = 0;
            $agentStats = [];
            $recentRatings = [];

            try {
                $avgRating = round(floatval($pdo->query("SELECT AVG(rating) FROM ticket_ratings")->fetchColumn() ?: 5.0), 1);
                $totalRatings = intval($pdo->query("SELECT COUNT(*) FROM ticket_ratings")->fetchColumn());

                $stmtAgents = $pdo->query("
                    SELECT u.fullname as agent_name, COUNT(tr.id) as total_votes, ROUND(AVG(tr.rating), 1) as avg_score 
                    FROM ticket_ratings tr 
                    JOIN users u ON tr.agent_id = u.id 
                    GROUP BY tr.agent_id 
                    ORDER BY avg_score DESC, total_votes DESC
                ");
                $agentStats = $stmtAgents->fetchAll(PDO::FETCH_ASSOC);

                $stmtRecent = $pdo->query("
                    SELECT tr.*, u.fullname as user_name, t.title as ticket_title, t.ticket_no, a.fullname as agent_name
                    FROM ticket_ratings tr 
                    JOIN users u ON tr.user_id = u.id 
                    JOIN tickets t ON tr.ticket_id = t.id 
                    LEFT JOIN users a ON tr.agent_id = a.id
                    ORDER BY tr.created_at DESC 
                    LIMIT 25
                ");
                $recentRatings = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
            ?>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-6 col-lg-3">
                    <div class="card border-0 shadow-sm p-3" style="border-radius:15px; background: #ffffff;">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-warning text-white d-flex align-items-center justify-content-center mr-3" style="width:48px; height:48px;">
                                <i class="fas fa-star fa-lg"></i>
                            </div>
                            <div>
                                <div class="text-muted small font-weight-bold text-uppercase"><?= $isTr ? 'Ortalama Memnuniyet' : 'Avg CSAT Score' ?></div>
                                <div class="h3 font-weight-bold mb-0 text-dark"><?= $avgRating ?> <small style="font-size:14px;" class="text-warning">/ 5.0 <i class="fas fa-star text-warning"></i></small></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="card border-0 shadow-sm p-3" style="border-radius:15px; background: #ffffff;">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-info text-white d-flex align-items-center justify-content-center mr-3" style="width:48px; height:48px;">
                                <i class="fas fa-poll-h fa-lg"></i>
                            </div>
                            <div>
                                <div class="text-muted small font-weight-bold text-uppercase"><?= $isTr ? 'Toplam Değerlendirme' : 'Total Ratings' ?></div>
                                <div class="h3 font-weight-bold mb-0 text-dark"><?= $totalRatings ?> <small style="font-size:14px;" class="text-muted"><?= $isTr ? 'Oy' : 'Votes' ?></small></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Agent Scores & Recent Feedback -->
            <div class="row g-3">
                <div class="col-lg-5 mb-4">
                    <div class="card shadow border-0 h-100" style="border-radius:15px; overflow:hidden;">
                        <div class="card-header bg-white border-bottom p-3">
                            <h5 class="card-title font-weight-bold m-0 text-dark"><i class="fas fa-user-shield text-primary mr-2"></i><?= $isTr ? 'Personel Performans Puanları' : 'Agent Performance Scores' ?></h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 text-sm ds-table">
                                    <thead>
                                        <tr>
                                            <th class="pl-4"><?= $isTr ? 'Destek Personeli' : 'Support Agent' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Değerlendirme Sayısı' : 'Votes' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Ortalama Puan' : 'Avg Score' ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($agentStats)): ?>
                                            <tr><td colspan="3" class="text-center py-4 text-muted"><?= $isTr ? 'Henüz oy kullanan müşteri yok.' : 'No customer votes yet.' ?></td></tr>
                                        <?php else: ?>
                                            <?php foreach($agentStats as $ag): ?>
                                            <tr>
                                                <td class="pl-4 font-weight-bold text-dark"><i class="fas fa-user-circle text-info mr-2"></i><?= htmlspecialchars($ag['agent_name']) ?></td>
                                                <td class="text-center font-weight-bold text-muted"><?= $ag['total_votes'] ?></td>
                                                <td class="text-center font-weight-bold text-warning" style="font-size:1.1rem;"><?= $ag['avg_score'] ?> ⭐</td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7 mb-4">
                    <div class="card shadow border-0 h-100" style="border-radius:15px; overflow:hidden;">
                        <div class="card-header bg-white border-bottom p-3">
                            <h5 class="card-title font-weight-bold m-0 text-dark"><i class="fas fa-comments text-warning mr-2"></i><?= $isTr ? 'Son Müşteri Yorumları & Değerlendirmeler' : 'Recent Customer Ratings' ?></h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 text-sm ds-table">
                                    <thead>
                                        <tr>
                                            <th class="pl-4"><?= $isTr ? 'Müşteri' : 'Customer' ?></th>
                                            <th><?= $isTr ? 'Bilet / Destek Kaydı' : 'Ticket Title' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Puan' : 'Rating' ?></th>
                                            <th><?= $isTr ? 'Yorum' : 'Comment' ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($recentRatings)): ?>
                                            <tr><td colspan="4" class="text-center py-4 text-muted"><?= $isTr ? 'Henüz yorum yapılmamış.' : 'No feedback comments yet.' ?></td></tr>
                                        <?php else: ?>
                                            <?php foreach($recentRatings as $rr): ?>
                                            <tr>
                                                <td class="pl-4 font-weight-bold text-dark"><?= htmlspecialchars($rr['user_name']) ?></td>
                                                <td><a href="bilet-detay/<?= $rr['ticket_id'] ?>" class="font-weight-bold text-primary">#<?= htmlspecialchars($rr['ticket_no']) ?> <?= htmlspecialchars($rr['ticket_title']) ?></a></td>
                                                 <td class="text-center text-warning" style="white-space: nowrap;">
                                                     <?php for($i=1; $i<=5; $i++): ?>
                                                         <i class="fas fa-star <?= $i <= intval($rr['rating']) ? 'text-warning' : 'text-muted' ?>" style="font-size: 11px;"></i>
                                                     <?php endfor; ?>
                                                 </td>
                                                <td class="text-muted small"><?= htmlspecialchars($rr['comment'] ?: '-') ?></td>
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
            <?php elseif ($view == 'analytics'): ?>
            <!-- GRAPHICAL ANALYTICS & PERFORMANCE REPORT -->
            <?php
            $statusStats = [];
            $monthlyStats = [];
            $queueStats = [];
            $agentSlaStats = [];

            try {
                // 1. Status Distribution
                $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM tickets GROUP BY status");
                $statusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // 2. Last 12 Months Volume
                $stmt = $pdo->query("
                    SELECT DATE_FORMAT(create_date, '%Y-%m') as month_label, COUNT(*) as count 
                    FROM tickets 
                    WHERE create_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                    GROUP BY month_label
                    ORDER BY month_label ASC
                ");
                $monthlyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // 3. Queue Distribution
                $stmt = $pdo->query("
                    SELECT q.name as queue_name, COUNT(t.id) as count 
                    FROM tickets t 
                    JOIN queues q ON t.queue_id = q.id 
                    GROUP BY q.id
                    ORDER BY count DESC
                ");
                $queueStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // 4. Agent SLA Resolution Time
                $stmt = $pdo->query("
                    SELECT u.fullname as agent_name, 
                           ROUND(AVG(TIMESTAMPDIFF(HOUR, t.create_date, COALESCE(t.closed_date, t.resolved_date, t.update_date))), 1) as avg_hours
                    FROM tickets t
                    JOIN users u ON t.{$personnelCol} = u.id
                    WHERE t.status IN ('resolved', 'closed') AND t.{$personnelCol} IS NOT NULL AND t.{$personnelCol} > 0
                    GROUP BY u.id
                    ORDER BY avg_hours ASC
                    LIMIT 10
                ");
                $agentSlaStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            $statusLabelsMap = [
                'open' => $isTr ? 'Açık' : 'Open',
                'pending' => $isTr ? 'Beklemede' : 'Pending',
                'resolved' => $isTr ? 'Çözüldü' : 'Resolved',
                'closed' => $isTr ? 'Kapandı' : 'Closed'
            ];
            ?>

            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

            <div class="row mb-4">
                <!-- Chart 1: Last 12 Months Volume -->
                <div class="col-lg-8 mb-4">
                    <div class="card border-0 shadow-sm" style="border-radius:15px; overflow:hidden; background: #fff;">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="card-title font-weight-bold m-0 text-dark">
                                <i class="fas fa-chart-line text-primary mr-2"></i><?= $isTr ? 'Son 12 Aylık Destek Kaydı Hacmi' : 'Last 12 Months Ticket Volume' ?>
                            </h5>
                        </div>
                        <div class="card-body p-4" style="height: 350px;">
                            <canvas id="monthlyVolumeChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Chart 2: Status Distribution -->
                <div class="col-lg-4 mb-4">
                    <div class="card border-0 shadow-sm" style="border-radius:15px; overflow:hidden; background: #fff;">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="card-title font-weight-bold m-0 text-dark">
                                <i class="fas fa-circle-notch text-success mr-2"></i><?= $isTr ? 'Bilet Durum Oranları' : 'Ticket Status Distribution' ?>
                            </h5>
                        </div>
                        <div class="card-body p-4" style="height: 350px;">
                            <canvas id="statusDistributionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Chart 3: Queue Distribution -->
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm" style="border-radius:15px; overflow:hidden; background: #fff;">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="card-title font-weight-bold m-0 text-dark">
                                <i class="fas fa-chart-bar text-warning mr-2"></i><?= $isTr ? 'Kuyruk / Departman Yük Dağılımı' : 'Queue / Department Load' ?>
                            </h5>
                        </div>
                        <div class="card-body p-4" style="height: 350px;">
                            <canvas id="queueDistributionChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Chart 4: Agent SLA Resolution Time -->
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm" style="border-radius:15px; overflow:hidden; background: #fff;">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="card-title font-weight-bold m-0 text-dark">
                                <i class="fas fa-history text-danger mr-2"></i><?= $isTr ? 'Ortalama Çözüm Süresi (Saat)' : 'Avg Resolution Time (Hours)' ?>
                            </h5>
                        </div>
                        <div class="card-body p-4" style="height: 350px;">
                            <canvas id="agentSlaChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const colors = {
                        primary: '#3b82f6',
                        success: '#10b981',
                        warning: '#f59e0b',
                        danger: '#ef4444',
                        purple: '#8b5cf6',
                        gray: '#64748b'
                    };

                    // 1. Line Chart: Monthly Volume
                    const monthlyData = <?= json_encode($monthlyStats) ?>;
                    const monthlyLabels = monthlyData.map(d => d.month_label);
                    const monthlyCounts = monthlyData.map(d => d.count);
                    
                    new Chart(document.getElementById('monthlyVolumeChart'), {
                        type: 'line',
                        data: {
                            labels: monthlyLabels,
                            datasets: [{
                                label: '<?= $isTr ? "Açılan Bilet Sayısı" : "Tickets Opened" ?>',
                                data: monthlyCounts,
                                borderColor: colors.primary,
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                fill: true,
                                tension: 0.3,
                                borderWidth: 3
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                                x: { grid: { display: false } }
                            }
                        }
                    });

                    // 2. Doughnut Chart: Status
                    const statusData = <?= json_encode($statusStats) ?>;
                    const statusLabelsMap = <?= json_encode($statusLabelsMap) ?>;
                    const statusLabels = statusData.map(d => statusLabelsMap[d.status] || d.status);
                    const statusCounts = statusData.map(d => d.count);
                    const statusColors = statusData.map(d => {
                        if (d.status === 'open') return colors.primary;
                        if (d.status === 'pending') return colors.warning;
                        if (d.status === 'resolved') return colors.success;
                        return colors.gray;
                    });

                    new Chart(document.getElementById('statusDistributionChart'), {
                        type: 'doughnut',
                        data: {
                            labels: statusLabels,
                            datasets: [{
                                data: statusCounts,
                                backgroundColor: statusColors,
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15 } }
                            }
                        }
                    });

                    // 3. Bar Chart: Queue Distribution
                    const queueData = <?= json_encode($queueStats) ?>;
                    const queueLabels = queueData.map(d => d.queue_name);
                    const queueCounts = queueData.map(d => d.count);

                    new Chart(document.getElementById('queueDistributionChart'), {
                        type: 'bar',
                        data: {
                            labels: queueLabels,
                            datasets: [{
                                label: '<?= $isTr ? "Bilet Sayısı" : "Tickets Count" ?>',
                                data: queueCounts,
                                backgroundColor: colors.warning,
                                borderRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                                x: { grid: { display: false } }
                            }
                        }
                    });

                    // 4. Horizontal Bar Chart: Agent SLA
                    const agentData = <?= json_encode($agentSlaStats) ?>;
                    const agentLabels = agentData.map(d => d.agent_name);
                    const agentHours = agentData.map(d => d.avg_hours);

                    new Chart(document.getElementById('agentSlaChart'), {
                        type: 'bar',
                        data: {
                            labels: agentLabels,
                            datasets: [{
                                label: '<?= $isTr ? "Ortalama Süre (Saat)" : "Avg Time (Hours)" ?>',
                                data: agentHours,
                                backgroundColor: colors.danger,
                                borderRadius: 6
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                x: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                                y: { grid: { display: false } }
                            }
                        }
                    });
                });
            </script>

            <?php else: ?>
            <!-- CONSUMABLE USAGE & ROLLOVER MATRIX REPORT -->
            <?php
            $currentYear = intval(date('Y')); // 2026
            $last5Years = [];
            for ($y = $currentYear; $y >= $currentYear - 4; $y--) {
                $last5Years[] = $y;
            }
            
            $f_year = intval($_GET['year'] ?? $currentYear);
            if (!in_array($f_year, $last5Years)) {
                $f_year = $currentYear;
            }
            
            $f_cat = intval($_GET['category_id'] ?? 0);
            $f_item_id = intval($_GET['consumable_id'] ?? 0);
            
            // Strictly fetch consumable categories (type = 'consumable')
            $categories = [];
            try {
                $categories = $pdo->query("SELECT id, name FROM asset_categories WHERE type = 'consumable' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($categories)) {
                    $categories = $pdo->query("SELECT DISTINCT cat.id, cat.name FROM asset_categories cat JOIN asset_consumables c ON c.category_id = cat.id ORDER BY cat.name ASC")->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Throwable $e) {}

            // Consumable Items with Category
            $whereCon = " WHERE 1=1 ";
            $paramsCon = [];
            if ($f_cat > 0) {
                $whereCon .= " AND c.category_id = ? ";
                $paramsCon[] = $f_cat;
            }
            if ($f_item_id > 0) {
                $whereCon .= " AND c.id = ? ";
                $paramsCon[] = $f_item_id;
            }
            
            $consumablesList = [];
            try {
                $stmtCon = $pdo->prepare("SELECT c.*, cat.name as category_name FROM asset_consumables c LEFT JOIN asset_categories cat ON c.category_id = cat.id $whereCon ORDER BY cat.name ASC, c.name ASC");
                $stmtCon->execute($paramsCon);
                $consumablesList = $stmtCon->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}

            // Full list of all consumables for dropdown filter
            $allConsumables = [];
            try {
                $allConsumables = $pdo->query("SELECT id, name FROM asset_consumables ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}

            // Full list of all departments for PDF modal filter
            $allDepartments = [];
            try {
                $allDepartments = $pdo->query("SELECT id, bolum_adi FROM bolumler ORDER BY bolum_adi ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}

            // Full list of all users for PDF modal filter
            $allUsers = [];
            try {
                $allUsers = $pdo->query("SELECT id, fullname FROM users ORDER BY fullname ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}

            // Calculate totals for Summary Matrix Table based on PRECISE Item Purchase Year and Checkouts
            $sumPurchasedYear = 0;
            $sumRolloverYear = 0;
            $sumTotalUsedYear = 0;
            $sumCurrentRemainingYear = 0;

            $itemStats = [];
            foreach ($consumablesList as $con) {
                $conId = intval($con['id']);
                $totStock = intval($con['total_qty'] ?? $con['quantity'] ?? $con['seats'] ?? 0);
                
                // Determine item creation / purchase year
                $purchaseDateRaw = $con['purchase_date'] ?: $con['created_at'];
                $itemCreatedYear = intval(date('Y', strtotime($purchaseDateRaw)));
                
                // Filter checkouts in the selected year ($f_year)
                $usedInSelectedYear = 0;
                try {
                    $usedInSelectedYear = intval($pdo->query("
                        SELECT COALESCE(SUM(CASE WHEN transaction_type = 'checkin' THEN -quantity ELSE quantity END), 0) 
                        FROM asset_consumable_checkouts 
                        WHERE consumable_id = $conId AND YEAR(created_at) = $f_year
                    ")->fetchColumn());
                } catch (Throwable $e) {}
                if ($usedInSelectedYear < 0) $usedInSelectedYear = 0;

                // Checkouts prior to selected year
                $usedBeforeSelectedYear = 0;
                try {
                    $usedBeforeSelectedYear = intval($pdo->query("
                        SELECT COALESCE(SUM(CASE WHEN transaction_type = 'checkin' THEN -quantity ELSE quantity END), 0) 
                        FROM asset_consumable_checkouts 
                        WHERE consumable_id = $conId AND YEAR(created_at) < $f_year
                    ")->fetchColumn());
                } catch (Throwable $e) {}
                if ($usedBeforeSelectedYear < 0) $usedBeforeSelectedYear = 0;

                // MATHEMATICALLY PRECISE CALCULATIONS PER SELECTED YEAR
                if ($itemCreatedYear > $f_year) {
                    $purchasedInYear = 0;
                    $rolloverInYear = 0;
                    $usedInSelectedYear = 0;
                    $remInSelectedYear = 0;
                } elseif ($itemCreatedYear === $f_year) {
                    $purchasedInYear = $totStock;
                    $rolloverInYear = 0;
                    $remInSelectedYear = max(0, $purchasedInYear - $usedInSelectedYear);
                } else {
                    $purchasedInYear = 0;
                    $rolloverInYear = max(0, $totStock - $usedBeforeSelectedYear);
                    $remInSelectedYear = max(0, $rolloverInYear - $usedInSelectedYear);
                }

                $sumPurchasedYear += $purchasedInYear;
                $sumRolloverYear += $rolloverInYear;
                $sumTotalUsedYear += $usedInSelectedYear;
                $sumCurrentRemainingYear += $remInSelectedYear;

                $itemStats[$conId] = [
                    'item_created_year' => $itemCreatedYear,
                    'purchased' => $purchasedInYear,
                    'rollover' => $rolloverInYear,
                    'used' => $usedInSelectedYear,
                    'remaining' => $remInSelectedYear
                ];
            }

            // Department Usage Breakdown (Filtered strictly by selected Year, Category, and Consumable Item)
            $deptUsage = [];
            try {
                $whereDept = " WHERE d.bolum_adi IS NOT NULL AND YEAR(ck.created_at) = ? ";
                $paramsDept = [$f_year];
                if ($f_cat > 0) {
                    $whereDept .= " AND c.category_id = ? ";
                    $paramsDept[] = $f_cat;
                }
                if ($f_item_id > 0) {
                    $whereDept .= " AND c.id = ? ";
                    $paramsDept[] = $f_item_id;
                }

                $stmtDept = $pdo->prepare("
                    SELECT d.bolum_adi as dept_name, c.name as consumable_name, DATE_FORMAT(ck.created_at, '%Y-%m') as year_month, SUM(ck.quantity) as qty
                    FROM asset_consumable_checkouts ck
                    JOIN asset_consumables c ON ck.consumable_id = c.id
                    LEFT JOIN assets a ON ck.asset_id = a.id
                    LEFT JOIN bolumler d ON a.department_id = d.id
                    $whereDept
                    GROUP BY d.bolum_adi, c.name, year_month
                    ORDER BY qty DESC, year_month DESC
                ");
                $stmtDept->execute($paramsDept);
                $deptUsage = $stmtDept->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}

            // User Usage Breakdown (Filtered strictly by selected Year, Category, and Consumable Item)
            $userUsage = [];
            try {
                $whereUser = " WHERE u.fullname IS NOT NULL AND YEAR(ck.created_at) = ? ";
                $paramsUser = [$f_year];
                if ($f_cat > 0) {
                    $whereUser .= " AND c.category_id = ? ";
                    $paramsUser[] = $f_cat;
                }
                if ($f_item_id > 0) {
                    $whereUser .= " AND c.id = ? ";
                    $paramsUser[] = $f_item_id;
                }

                $stmtUser = $pdo->prepare("
                    SELECT u.fullname as user_name, c.name as consumable_name, ck.created_at as checkout_date, ck.quantity as qty, ck.notes
                    FROM asset_consumable_checkouts ck
                    JOIN asset_consumables c ON ck.consumable_id = c.id
                    LEFT JOIN users u ON ck.user_id = u.id
                    $whereUser
                    ORDER BY ck.created_at DESC
                    LIMIT 50
                ");
                $stmtUser->execute($paramsUser);
                $userUsage = $stmtUser->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
            ?>

            <!-- Dynamic Consumables Filter Bar -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius:15px; background: #ffffff;">
                <div class="card-body p-3">
                    <form method="GET" action="raporlar" class="row align-items-end g-2">
                        <input type="hidden" name="view" value="consumables">
                        <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                            <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Rapor Yılı (Son 5 Yıl)' : 'Report Year (Last 5 Years)' ?></label>
                            <select name="year" class="form-control form-control-sm custom-select">
                                <?php foreach($last5Years as $yrOption): ?>
                                    <option value="<?= $yrOption ?>" <?= $f_year == $yrOption ? 'selected' : '' ?>><?= $yrOption ?> <?= $isTr ? 'Yılı' : 'Year' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                            <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Kategori Filtresi' : 'Category Filter' ?></label>
                            <select name="category_id" class="form-control form-control-sm custom-select">
                                <option value="0"><?= $isTr ? 'Tüm Kategoriler' : 'All Categories' ?></option>
                                <?php foreach($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $f_cat == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                            <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Sarf Malzeme Seçimi' : 'Select Consumable Item' ?></label>
                            <select name="consumable_id" class="form-control form-control-sm custom-select">
                                <option value="0"><?= $isTr ? 'Tüm Sarf Malzemeler (Yan Yana Tablo)' : 'All Consumable Items (Side-by-Side Table)' ?></option>
                                <?php foreach($allConsumables as $acon): ?>
                                    <option value="<?= $acon['id'] ?>" <?= $f_item_id == $acon['id'] ? 'selected' : '' ?>><?= htmlspecialchars($acon['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-sm-12 d-flex gap-1">
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-filter mr-1"></i><?= $isTr ? 'Filtrele' : 'Filter' ?></button>
                            <a href="raporlar?view=consumables" class="btn btn-light btn-sm ml-1" title="<?= $isTr ? 'Sıfırla' : 'Reset' ?>"><i class="fas fa-sync-alt"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- EXCEL MATRİS ÖZET TABLOSU -->
            <div class="card shadow border-0 mb-4" id="printable-matrix-card" style="border-radius:15px; overflow:hidden;">
                <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h5 class="font-weight-bold m-0 text-white" style="font-size: 1.15rem; line-height: 1.4;">
                            <i class="fas fa-table text-warning mr-2"></i><?= $isTr ? 'Sarf Malzeme Devir & Tüketim Matrisi' : 'Consumable Rollover & Usage Matrix' ?>
                        </h5>
                        <div class="small mt-1" style="font-size: 12px; color: #cbd5e1 !important; line-height: 1.4;">
                            <i class="fas fa-info-circle text-info mr-1"></i><?= $isTr ? 'Raporlama Son 5 Yıl Dönemine Göre Hesaplanmaktadır (' . ($currentYear - 4) . ' - ' . $currentYear . ')' : 'Report calculated for 5-year period (' . ($currentYear - 4) . ' - ' . $currentYear . ')' ?>
                        </div>
                    </div>
                    <div>
                        <span class="badge badge-warning font-weight-bold px-3 py-2 shadow-sm" style="font-size:13px; border-radius:8px;"><?= $f_year ?> <?= $isTr ? 'Yılı Dönemi' : 'Period' ?></span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0 ds-table" id="consumables-matrix-table">
                            <thead>
                                <tr class="bg-light text-dark">
                                    <th style="width: 70px;" class="text-center">YIL</th>
                                    <th style="width: 170px;">İŞLEM TÜRÜ</th>
                                    <?php if(empty($consumablesList)): ?>
                                        <th class="text-center">KAYITLI SARF MALZEME YOK</th>
                                    <?php else: ?>
                                        <?php foreach($consumablesList as $con): ?>
                                            <th class="text-center font-weight-bold"><?= htmlspecialchars($con['name']) ?></th>
                                        <?php endforeach; ?>
                                        <th class="text-center font-weight-bold bg-primary text-white">GENEL TOPLAM</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="excel-row-purchased">
                                    <td class="text-center font-weight-bold"><?= $f_year ?></td>
                                    <td class="font-weight-bold"><?= $isTr ? 'SATIN ALMA' : 'PURCHASED' ?></td>
                                    <?php if(empty($consumablesList)): ?>
                                        <td class="excel-cell-val">0</td>
                                    <?php else: ?>
                                        <?php foreach($consumablesList as $con): 
                                            $st = $itemStats[$con['id']]['purchased'] ?? 0;
                                        ?>
                                            <td class="excel-cell-val"><?= $st ?></td>
                                        <?php endforeach; ?>
                                        <td class="excel-cell-val font-weight-bold text-white bg-dark"><?= $sumPurchasedYear ?></td>
                                    <?php endif; ?>
                                </tr>

                                <tr class="excel-row-rollover">
                                    <td class="text-center font-weight-bold"><?= $f_year - 1 ?></td>
                                    <td class="font-weight-bold"><?= $isTr ? 'DEVİR' : 'ROLLOVER' ?></td>
                                    <?php if(empty($consumablesList)): ?>
                                        <td class="excel-cell-val">0</td>
                                    <?php else: ?>
                                        <?php foreach($consumablesList as $con): 
                                            $st = $itemStats[$con['id']]['rollover'] ?? 0;
                                        ?>
                                            <td class="excel-cell-val"><?= $st ?></td>
                                        <?php endforeach; ?>
                                        <td class="excel-cell-val font-weight-bold text-white bg-dark"><?= $sumRolloverYear ?></td>
                                    <?php endif; ?>
                                </tr>

                                <tr class="excel-row-used">
                                    <td class="text-center font-weight-bold"><?= $isTr ? 'TOPLAM' : 'TOTAL' ?></td>
                                    <td class="font-weight-bold"><?= $isTr ? 'KULLANILAN (' . $f_year . ')' : 'CONSUMED (' . $f_year . ')' ?></td>
                                    <?php if(empty($consumablesList)): ?>
                                        <td class="excel-cell-val">0</td>
                                    <?php else: ?>
                                        <?php foreach($consumablesList as $con): 
                                            $st = $itemStats[$con['id']]['used'] ?? 0;
                                        ?>
                                            <td class="excel-cell-val"><?= $st ?></td>
                                        <?php endforeach; ?>
                                        <td class="excel-cell-val font-weight-bold text-white bg-dark"><?= $sumTotalUsedYear ?></td>
                                    <?php endif; ?>
                                </tr>

                                <tr class="excel-row-current">
                                    <td class="text-center font-weight-bold"><?= $isTr ? 'KALAN' : 'REMAINING' ?></td>
                                    <td class="font-weight-bold"><?= $isTr ? 'GÜNCEL STOK (' . $f_year . ' Sonu)' : 'STOCK (End of ' . $f_year . ')' ?></td>
                                    <?php if(empty($consumablesList)): ?>
                                        <td class="excel-cell-val">0</td>
                                    <?php else: ?>
                                        <?php foreach($consumablesList as $con): 
                                            $st = $itemStats[$con['id']]['remaining'] ?? 0;
                                        ?>
                                            <td class="excel-cell-val"><?= $st ?></td>
                                        <?php endforeach; ?>
                                        <td class="excel-cell-val font-weight-bold text-white bg-dark"><?= $sumCurrentRemainingYear ?></td>
                                    <?php endif; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- DETAYLI ANALİZ TABLARI -->
            <div class="card shadow border-0" style="border-radius:15px; overflow:hidden;">
                <div class="card-header bg-white border-bottom p-3">
                    <ul class="nav nav-tabs card-header-tabs border-0" id="matrixSubTabs">
                        <li class="nav-item">
                            <a class="nav-link active font-weight-bold" data-toggle="tab" href="#subtab-items"><i class="fas fa-list mr-2 text-primary"></i><?= $isTr ? 'Sarf Malzeme Kalem Detayı (' . $f_year . ')' : 'Item Details (' . $f_year . ')' ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold" data-toggle="tab" href="#subtab-dept"><i class="fas fa-building mr-2 text-success"></i><?= $isTr ? 'Departman / Bölüm Dağılımı (' . $f_year . ')' : 'Department Usage (' . $f_year . ')' ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold" data-toggle="tab" href="#subtab-users"><i class="fas fa-users mr-2 text-info"></i><?= $isTr ? 'Personel Kullanımı (' . $f_year . ')' : 'User Usage (' . $f_year . ')' ?></a>
                        </li>
                    </ul>
                </div>
                <div class="card-body p-0">
                    <div class="tab-content">
                        <!-- TAB 1: Kalem Detay Tablosu -->
                        <div class="tab-pane fade show active" id="subtab-items">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 text-sm ds-table" id="subtab-items-table">
                                    <thead>
                                        <tr>
                                            <th class="pl-4"><?= $isTr ? 'Sarf Malzeme Adı' : 'Consumable Name' ?></th>
                                            <th><?= $isTr ? 'Kategori' : 'Category' ?></th>
                                            <th><?= $isTr ? 'Parça / Stok No' : 'Item No' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Satın Alınan (' . $f_year . ')' : 'Purchased (' . $f_year . ')' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Geçen Yıl Devir' : 'Rollover' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Tüketilen / Zimmetli (' . $f_year . ')' : 'Consumed (' . $f_year . ')' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Kalan Stok (' . $f_year . ')' : 'Remaining (' . $f_year . ')' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Durum' : 'Status' ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($consumablesList)): ?>
                                            <tr><td colspan="8" class="text-center py-5 text-muted"><?= $isTr ? 'Kayıtlı sarf malzeme bulunamadı.' : 'No consumables found.' ?></td></tr>
                                        <?php else: ?>
                                            <?php foreach($consumablesList as $con): 
                                                $st = $itemStats[$con['id']] ?? ['item_created_year'=>$f_year,'purchased'=>0,'rollover'=>0,'used'=>0,'remaining'=>0];
                                                $statusBadge = '<span class="badge badge-success-soft px-3 py-1">' . ($isTr ? 'Yeterli' : 'Sufficient') . '</span>';
                                                if ($st['item_created_year'] > $f_year) {
                                                    $statusBadge = '<span class="badge badge-secondary-soft px-3 py-1">' . ($isTr ? 'Henüz Alınmadı' : 'Not Purchased Yet') . '</span>';
                                                } elseif ($st['remaining'] <= 0) {
                                                    $statusBadge = '<span class="badge badge-danger-soft px-3 py-1">' . ($isTr ? 'Tükendi' : 'Out of Stock') . '</span>';
                                                }
                                            ?>
                                            <tr>
                                                <td class="pl-4 font-weight-bold text-dark"><?= htmlspecialchars($con['name']) ?></td>
                                                <td><span class="badge badge-secondary-soft px-2 py-1"><?= htmlspecialchars($con['category_name'] ?? '-') ?></span></td>
                                                <td class="text-muted small"><?= htmlspecialchars($con['item_no'] ?? '-') ?></td>
                                                <td class="text-center font-weight-bold"><?= $st['purchased'] ?></td>
                                                <td class="text-center font-weight-bold text-secondary"><?= $st['rollover'] ?></td>
                                                <td class="text-center font-weight-bold text-warning"><?= $st['used'] ?></td>
                                                <td class="text-center font-weight-bold <?= $st['remaining'] <= 0 ? 'text-danger' : 'text-success' ?>"><?= $st['remaining'] ?></td>
                                                <td class="text-center"><?= $statusBadge ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- TAB 2: Departman Dağılımı -->
                        <div class="tab-pane fade" id="subtab-dept">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 text-sm ds-table" id="subtab-dept-table">
                                    <thead>
                                        <tr>
                                            <th class="pl-4"><?= $isTr ? 'Departman / Bölüm Adı' : 'Department Name' ?></th>
                                            <th><?= $isTr ? 'Kullanılan Sarf Malzeme' : 'Consumable Item' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Dönem (Yıl - Ay)' : 'Period (Year - Month)' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Teslim Edilen Adet' : 'Delivered Qty' ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($deptUsage)): ?>
                                            <tr><td colspan="4" class="text-center py-5 text-muted"><?= $isTr ? 'Seçilen yılda (' . $f_year . ') departman zimmet kaydı bulunamadı.' : 'No department checkout history for ' . $f_year . '.' ?></td></tr>
                                        <?php else: ?>
                                            <?php foreach($deptUsage as $du): ?>
                                            <tr>
                                                <td class="pl-4 font-weight-bold text-primary"><i class="fas fa-building mr-2"></i><?= htmlspecialchars($du['dept_name']) ?></td>
                                                <td class="font-weight-bold text-dark"><?= htmlspecialchars($du['consumable_name']) ?></td>
                                                <td class="text-center font-weight-bold text-muted"><?= htmlspecialchars($du['year_month']) ?></td>
                                                <td class="text-center font-weight-bold text-success" style="font-size:1.1rem;"><?= $du['qty'] ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- TAB 3: Personel Kullanımı -->
                        <div class="tab-pane fade" id="subtab-users">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 text-sm ds-table" id="subtab-users-table">
                                    <thead>
                                        <tr>
                                            <th class="pl-4"><?= $isTr ? 'Personel Adı Soyadı' : 'Employee Name' ?></th>
                                            <th><?= $isTr ? 'Kullanılan Sarf Malzeme' : 'Consumable Item' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Zimmet Tarihi' : 'Checkout Date' ?></th>
                                            <th class="text-center"><?= $isTr ? 'Teslim Edilen Adet' : 'Delivered Qty' ?></th>
                                            <th><?= $isTr ? 'Açıklama / Not' : 'Notes' ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($userUsage)): ?>
                                            <tr><td colspan="5" class="text-center py-5 text-muted"><?= $isTr ? 'Seçilen yılda (' . $f_year . ') personel zimmet kaydı bulunamadı.' : 'No user checkout history for ' . $f_year . '.' ?></td></tr>
                                        <?php else: ?>
                                            <?php foreach($userUsage as $uu): ?>
                                            <tr>
                                                <td class="pl-4 font-weight-bold text-dark"><i class="fas fa-user-circle mr-2 text-info"></i><?= htmlspecialchars($uu['user_name']) ?></td>
                                                <td class="font-weight-bold text-dark"><?= htmlspecialchars($uu['consumable_name']) ?></td>
                                                <td class="text-center text-muted font-weight-bold small"><?= date('d.m.Y H:i', strtotime($uu['checkout_date'])) ?></td>
                                                <td class="text-center font-weight-bold text-warning" style="font-size:1.1rem;"><?= $uu['qty'] ?></td>
                                                <td class="text-muted small"><?= htmlspecialchars($uu['notes'] ?: '-') ?></td>
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

                        <!-- CONSUMABLES PDF EXPORT MODAL -->
            <div class="modal fade" id="consumablesPdfModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                    <div class="modal-content border-0 shadow-lg" style="border-radius:15px; overflow:hidden;">
                        <div class="modal-header bg-dark text-white p-3">
                            <h5 class="modal-title font-weight-bold m-0 text-white">
                                <i class="fas fa-file-pdf text-danger mr-2"></i><?= $isTr ? 'Sarf Malzeme PDF Rapor Özelleştirme' : 'Customize Consumables PDF Report' ?>
                            </h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body p-4 bg-light">
                            <div class="row g-3">
                                <!-- Rapor Dönemi -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-calendar-alt text-primary mr-1"></i><?= $isTr ? 'Rapor Dönemi / Yıl' : 'Report Period / Year' ?></label>
                                    <select id="cons_pdf_year" class="form-control custom-select">
                                        <option value="<?= $f_year ?>" selected><?= $f_year ?> <?= $isTr ? 'Yılı (Aktif Seçim)' : 'Year (Active)' ?></option>
                                        <option value="all"><?= $isTr ? 'Tüm Yıllar (Son 5 Yıl Özet)' : 'All Years (5-Year Summary)' ?></option>
                                        <?php foreach($last5Years as $yrOption): ?>
                                            <option value="<?= $yrOption ?>"><?= $yrOption ?> <?= $isTr ? 'Yılı' : 'Year' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Kategori Filtresi -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-tags text-info mr-1"></i><?= $isTr ? 'Kategoriye Özel Filtre' : 'Category Filter' ?></label>
                                    <select id="cons_pdf_cat" class="form-control custom-select">
                                        <option value="0" <?= $f_cat == 0 ? 'selected' : '' ?>><?= $isTr ? 'Tüm Kategoriler' : 'All Categories' ?></option>
                                        <?php foreach($categories as $cat): ?>
                                            <option value="<?= htmlspecialchars($cat['name']) ?>" <?= ($f_cat > 0 && isset($con) && $con['category_id'] == $cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Sarf Malzeme Filtresi -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-boxes text-warning mr-1"></i><?= $isTr ? 'Sarf Malzemeye Özel Filtre' : 'Consumable Item Filter' ?></label>
                                    <select id="cons_pdf_item" class="form-control custom-select">
                                        <option value="0" <?= $f_item_id == 0 ? 'selected' : '' ?>><?= $isTr ? 'Tüm Sarf Malzemeler' : 'All Consumable Items' ?></option>
                                        <?php foreach($allConsumables as $acon): ?>
                                            <option value="<?= htmlspecialchars($acon['name']) ?>"><?= htmlspecialchars($acon['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Departman / Bölüm Filtresi -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-building text-success mr-1"></i><?= $isTr ? 'Bölüm / Departmana Özel Filtre' : 'Department Filter' ?></label>
                                    <select id="cons_pdf_dept" class="form-control custom-select">
                                        <option value="0"><?= $isTr ? 'Tüm Bölümler / Departmanlar' : 'All Departments' ?></option>
                                        <?php foreach($allDepartments as $dept): ?>
                                            <option value="<?= htmlspecialchars($dept['bolum_adi']) ?>"><?= htmlspecialchars($dept['bolum_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Personel Filtresi -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-user text-purple mr-1"></i><?= $isTr ? 'Personele Özel Filtre' : 'Personnel Filter' ?></label>
                                    <select id="cons_pdf_user" class="form-control custom-select">
                                        <option value="0"><?= $isTr ? 'Tüm Personeller' : 'All Personnel' ?></option>
                                        <?php foreach($allUsers as $u): ?>
                                            <option value="<?= htmlspecialchars($u['fullname']) ?>"><?= htmlspecialchars($u['fullname']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Rapor Kapsamı -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-layer-group text-primary mr-1"></i><?= $isTr ? 'Rapor Kapsamı / İçeriği' : 'Report Scope / Content' ?></label>
                                    <select id="cons_pdf_scope" class="form-control custom-select">
                                        <option value="all"><?= $isTr ? 'Tüm Detaylar & Tablolar (Eksiksiz Rapor)' : 'Full Report (All Tables)' ?></option>
                                        <option value="matrix"><?= $isTr ? 'Yalnızca Özet Devir & Tüketim Matrisi' : 'Summary Matrix Only' ?></option>
                                        <option value="items"><?= $isTr ? 'Yalnızca Sarf Malzeme Kalem Detayı' : 'Item Details Only' ?></option>
                                        <option value="dept"><?= $isTr ? 'Yalnızca Departman / Bölüm Dağılımı' : 'Department Usage Only' ?></option>
                                        <option value="users"><?= $isTr ? 'Yalnızca Personel Kullanımı' : 'User Usage Only' ?></option>
                                    </select>
                                </div>

                                <!-- Page Orientation -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-file-alt text-secondary mr-1"></i><?= $isTr ? 'Sayfa Yönü' : 'Page Orientation' ?></label>
                                    <div class="d-flex gap-3">
                                        <div class="custom-control custom-radio mr-3">
                                            <input type="radio" id="cons_orient_l" name="cons_pdf_orientation" value="landscape" class="custom-control-input" checked>
                                            <label class="custom-control-label font-weight-bold" for="cons_orient_l"><?= $isTr ? 'Yatay (Landscape - Tavsiye Edilen)' : 'Landscape (Recommended)' ?></label>
                                        </div>
                                        <div class="custom-control custom-radio">
                                            <input type="radio" id="cons_orient_p" name="cons_pdf_orientation" value="portrait" class="custom-control-input">
                                            <label class="custom-control-label font-weight-bold" for="cons_orient_p"><?= $isTr ? 'Dikey (Portrait)' : 'Portrait' ?></label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Language Option -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-globe text-info mr-1"></i><?= $isTr ? 'Rapor Dili' : 'Report Language' ?></label>
                                    <select id="cons_pdf_lang" class="form-control custom-select">
                                        <option value="tr" <?= $isTr ? 'selected' : '' ?>>Türkçe (Turkish)</option>
                                        <option value="en" <?= !$isTr ? 'selected' : '' ?>>English</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-white p-3 d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary px-4" data-dismiss="modal"><?= $isTr ? 'Vazgeç' : 'Cancel' ?></button>
                            <button type="button" class="btn btn-danger px-4 font-weight-bold" onclick="runConsumablesPdfExport()">
                                <i class="fas fa-file-pdf mr-2"></i><?= $isTr ? 'PDF Rapor Oluştur' : 'Generate PDF' ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
<?php endif; ?>

            <!-- CSAT PDF EXPORT MODAL -->
            <div class="modal fade" id="csatPdfModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content shadow-lg border-0" style="border-radius:15px; overflow:hidden;">
                        <div class="modal-header bg-danger text-white p-3">
                            <h5 class="modal-title font-weight-bold" id="csatPdfModalTitle">
                                <i class="fas fa-file-pdf text-white mr-2"></i><?= $isTr ? 'Müşteri Memnuniyeti PDF Raporu' : 'CSAT PDF Report' ?>
                            </h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body p-4 bg-light">
                            <div class="row">
                                <!-- Orientation Option -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-arrows-alt text-info mr-1"></i><?= $isTr ? 'Yönlendirme' : 'Orientation' ?></label>
                                    <select id="csat_pdf_orientation" class="form-control custom-select">
                                        <option value="portrait" selected><?= $isTr ? 'Dikey (Portrait)' : 'Portrait' ?></option>
                                        <option value="landscape"><?= $isTr ? 'Yatay (Landscape)' : 'Landscape' ?></option>
                                    </select>
                                </div>
                                <!-- Paper Size Option -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-file text-info mr-1"></i><?= $isTr ? 'Kağıt Boyutu' : 'Paper Size' ?></label>
                                    <select id="csat_pdf_size" class="form-control custom-select">
                                        <option value="a4" selected>A4</option>
                                        <option value="a3">A3</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-white p-3 d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary px-4" data-dismiss="modal"><?= $isTr ? 'Vazgeç' : 'Cancel' ?></button>
                            <button type="button" class="btn btn-danger px-4 font-weight-bold" onclick="runCsatPdfExport()">
                                <i class="fas fa-file-pdf mr-2"></i><?= $isTr ? 'PDF Oluştur' : 'Generate PDF' ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ANALYTICS PDF EXPORT MODAL -->
            <div class="modal fade" id="analyticsPdfModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content shadow-lg border-0" style="border-radius:15px; overflow:hidden;">
                        <div class="modal-header bg-danger text-white p-3">
                            <h5 class="modal-title font-weight-bold" id="analyticsPdfModalTitle">
                                <i class="fas fa-file-pdf text-white mr-2"></i><?= $isTr ? 'Grafik Raporlar PDF Raporu' : 'Charts & Analytics PDF Report' ?>
                            </h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body p-4 bg-light">
                            <div class="row">
                                <!-- Orientation Option -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-arrows-alt text-info mr-1"></i><?= $isTr ? 'Yönlendirme' : 'Orientation' ?></label>
                                    <select id="analytics_pdf_orientation" class="form-control custom-select">
                                        <option value="portrait" selected><?= $isTr ? 'Dikey (Portrait)' : 'Portrait' ?></option>
                                        <option value="landscape"><?= $isTr ? 'Yatay (Landscape)' : 'Landscape' ?></option>
                                    </select>
                                </div>
                                <!-- Paper Size Option -->
                                <div class="col-md-6 mb-3">
                                    <label class="small font-weight-bold text-dark mb-1"><i class="fas fa-file text-info mr-1"></i><?= $isTr ? 'Kağıt Boyutu' : 'Paper Size' ?></label>
                                    <select id="analytics_pdf_size" class="form-control custom-select">
                                        <option value="a4" selected>A4</option>
                                        <option value="a3">A3</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-white p-3 d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary px-4" data-dismiss="modal"><?= $isTr ? 'Vazgeç' : 'Cancel' ?></button>
                            <button type="button" class="btn btn-danger px-4 font-weight-bold" onclick="runAnalyticsPdfExport()">
                                <i class="fas fa-file-pdf mr-2"></i><?= $isTr ? 'PDF Oluştur' : 'Generate PDF' ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Export Scripts with Clean Turkish Character Formatting & Table Extractor -->
<!-- Export Scripts with Clean Turkish Character Formatting & Table Extractor -->
<script>
    function trClean(str) {
        if (!str) return '';
        str = String(str);
        return str
            .replace(/—/g, ' -> ')
            .replace(/–/g, ' -> ')
            .replace(/→/g, ' -> ')
            .replace(/!’/g, ' -> ')
            .replace(/!'/g, ' -> ')
            .replace(/â€”/g, ' -> ')
            .replace(/â†’/g, ' -> ')
            .replace(/Ğ/g, 'G').replace(/ğ/g, 'g')
            .replace(/Ü/g, 'U').replace(/ü/g, 'u')
            .replace(/Ş/g, 'S').replace(/ş/g, 's')
            .replace(/İ/g, 'I').replace(/ı/g, 'i')
            .replace(/Ö/g, 'O').replace(/ö/g, 'o')
            .replace(/Ç/g, 'C').replace(/ç/g, 'c');
    }

    const trToEnDict = {
        "TARİH": "DATE", "KULLANICI": "USER", "İŞLEM": "ACTION", "TÜR": "TYPE", "HEDEF / VARLIK": "TARGET / ASSET", "AÇIKLAMA": "DESCRIPTION", "DEĞİŞİKLİKLER": "CHANGES",
        "Tarih": "Date", "Kullanıcı": "User", "İşlem": "Action", "Tür": "Type", "Hedef / Varlık": "Target / Asset", "Açıklama": "Description", "Değişiklikler": "Changes",
        "Oluşturuldu": "Created", "Güncellendi": "Updated", "Silindi": "Deleted", "Zimmetlendi": "Checked Out", "Geri Alındı": "Checked In", "Teslim Edildi": "Handover", "Geri Yüklendi": "Restored",
        "Demirbaş": "Asset", "Lisans": "License", "Aksesuar": "Accessory", "Sarf": "Consumable", "Bileşen": "Component", "Personel": "Personnel",
        "YIL": "YEAR", "İŞLEM TÜRÜ": "ACTION TYPE", "GENEL TOPLAM": "OVERALL TOTAL",
        "SATIN ALMA": "PURCHASED", "DEVİR": "ROLLOVER", "TOPLAM": "TOTAL", "KALAN": "REMAINING",
        "KULLANILAN": "CONSUMED", "GÜNCEL STOK": "CURRENT STOCK",
        "Sarf Malzeme Adı": "Consumable Name", "Kategori": "Category", "Parça / Stok No": "Item No",
        "Satın Alınan": "Purchased", "Geçen Yıl Devir": "Rollover", "Tüketilen / Zimmetli": "Consumed", "Kalan Stok": "Remaining Stock", "Durum": "Status",
        "Yeterli": "Sufficient", "Henüz Alınmadı": "Not Purchased Yet", "Tükendi": "Out of Stock",
        "Departman / Bölüm Adı": "Department Name", "Kullanılan Sarf Malzeme": "Consumable Item", "Dönem (Yıl - Ay)": "Period (Year - Month)", "Teslim Edilen Adet": "Delivered Qty",
        "Personel Adı Soyadı": "Employee Name", "Zimmet Tarihi": "Checkout Date", "Açıklama / Not": "Notes",
        "Bilgi güncellendi:": "Info updated:", "Cihaz API donanım bilgisi güncellendi.": "Device API hardware info updated."
    };

    function translateText(txt, isEn) {
        if (!txt) return '';
        let clean = trClean(txt);
        if (!isEn) return clean;
        
        if (trToEnDict[txt]) return trClean(trToEnDict[txt]);
        if (trToEnDict[clean]) return trClean(trToEnDict[clean]);
        
        let res = clean;
        for (let k in trToEnDict) {
            if (k.length > 3 && res.includes(k)) {
                res = res.replaceAll(k, trToEnDict[k]);
            }
        }
        return trClean(res);
    }

    function extractTableData(tableId, filterOpts = {}, isEn = false) {
        const table = document.getElementById(tableId);
        if (!table) return null;
        
        let head = [];
        let body = [];
        
        const ths = table.querySelectorAll('thead th');
        let headRow = [];
        ths.forEach(th => {
            let txt = th.innerText.trim();
            if (txt !== '' && !th.querySelector('input[type="checkbox"]')) {
                headRow.push(translateText(txt, isEn));
            }
        });
        if (headRow.length > 0) head.push(headRow);
        
        const trs = table.querySelectorAll('tbody tr');
        trs.forEach(tr => {
            let rowData = [];
            const tds = tr.querySelectorAll('td');
            if (tds.length === 1 && tds[0].getAttribute('colspan')) {
                return; // Skip empty message rows
            }
            
            let rowTextFull = tr.innerText.toLowerCase();

            // Apply filter options
            if (filterOpts.item && filterOpts.item !== '0') {
                if (!rowTextFull.includes(filterOpts.item.toLowerCase())) return;
            }
            if (filterOpts.cat && filterOpts.cat !== '0') {
                if (!rowTextFull.includes(filterOpts.cat.toLowerCase())) return;
            }
            if (filterOpts.dept && filterOpts.dept !== '0' && tableId === 'subtab-dept-table') {
                if (!rowTextFull.includes(filterOpts.dept.toLowerCase())) return;
            }
            if (filterOpts.user && filterOpts.user !== '0' && tableId === 'subtab-users-table') {
                if (!rowTextFull.includes(filterOpts.user.toLowerCase())) return;
            }

            tds.forEach((td, idx) => {
                if (ths[idx] && (ths[idx].innerText.trim() === '' || ths[idx].querySelector('input[type="checkbox"]'))) {
                    return;
                }
                let txt = td.innerText.trim().replace(/\s+/g, ' ');
                rowData.push(translateText(txt, isEn));
            });
            if (rowData.length > 0) body.push(rowData);
        });
        
        return { head, body };
    }

    function runActivityPdfExport() {
        $('#activityPdfModal').modal('hide');
        const selOrient = $('input[name="act_pdf_orientation"]:checked').val() || 'landscape';
        const selLang = $('#act_pdf_lang').val() || 'tr';
        const isEn = selLang === 'en';

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: selOrient, unit: 'mm', format: 'a4' });
        
        let titleText = isEn ? 'System Activity Logs Report' : 'Sistem Etkinlik Loglari Raporu';
        doc.setFontSize(15);
        doc.setTextColor(30, 41, 59);
        doc.text(trClean(titleText), 14, 15);

        doc.setFontSize(9);
        doc.setTextColor(100, 116, 139);
        let nowStr = new Date().toLocaleString();
        doc.text(trClean((isEn ? "Generated At: " : "Olusturulma Tarihi: ") + nowStr), 14, 21);

        const data = extractTableData('activity-logs-table', {}, isEn);
        if (data && data.body.length > 0) {
            doc.autoTable({
                head: data.head,
                body: data.body,
                startY: 27,
                styles: { fontSize: 8, cellPadding: 2.5, halign: 'left' },
                headStyles: { fillColor: [30, 41, 59], textColor: [255, 255, 255], fontStyle: 'bold' }
            });
        }

        doc.save('Sistem_Etkinlik_Loglari_Raporu.pdf');
    }

    function runConsumablesPdfExport() {
        $('#consumablesPdfModal').modal('hide');
        
        const selYear = $('#cons_pdf_year').val();
        const selCat = $('#cons_pdf_cat').val();
        const selItem = $('#cons_pdf_item').val();
        const selDept = $('#cons_pdf_dept').val();
        const selUser = $('#cons_pdf_user').val();

        const selScope = $('#cons_pdf_scope').val();
        const selOrient = $('input[name="cons_pdf_orientation"]:checked').val() || 'landscape';
        const selLang = $('#cons_pdf_lang').val() || 'tr';
        const isEn = selLang === 'en';

        const filterOpts = {
            cat: selCat,
            item: selItem,
            dept: selDept,
            user: selUser
        };

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: selOrient, unit: 'mm', format: 'a4' });
        
        let titleText = isEn 
            ? `Consumable Usage & Rollover Report (${selYear === 'all' ? '5-Year Summary' : selYear + ' Period'})` 
            : `Sarf Malzeme Devir & Tuketim Raporu (${selYear === 'all' ? '5 Yillik Ozet' : selYear + ' Donemi'})`;
        
        doc.setFontSize(15);
        doc.setTextColor(30, 41, 59);
        doc.text(trClean(titleText), 14, 15);
        
        doc.setFontSize(9);
        doc.setTextColor(100, 116, 139);
        let nowStr = new Date().toLocaleString();
        doc.text(trClean((isEn ? "Generated At: " : "Olusturulma Tarihi: ") + nowStr), 14, 21);

        let currentY = 27;

        const addTableToDoc = (tableId, sectionTitle) => {
            const data = extractTableData(tableId, filterOpts, isEn);
            if (!data || data.body.length === 0) return;

            doc.setFontSize(11);
            doc.setTextColor(15, 23, 42);
            doc.text(trClean(sectionTitle), 14, currentY);
            currentY += 4;

            doc.autoTable({ 
                head: data.head,
                body: data.body,
                startY: currentY,
                styles: { fontSize: 8, cellPadding: 2.5, halign: 'center' },
                headStyles: { fillColor: [30, 41, 59], textColor: [255, 255, 255], fontStyle: 'bold' }
            });

            currentY = doc.lastAutoTable.finalY + 12;
        };

        if (selScope === 'all' || selScope === 'matrix') {
            addTableToDoc('consumables-matrix-table', isEn ? "Summary Rollover & Usage Matrix" : "Ozet Devir & Tuketim Matrisi");
        }
        if (selScope === 'all' || selScope === 'items') {
            addTableToDoc('subtab-items-table', isEn ? "Consumable Item Details" : "Sarf Malzeme Kalem Detayi");
        }
        if (selScope === 'all' || selScope === 'dept') {
            addTableToDoc('subtab-dept-table', isEn ? "Department Usage Breakdown" : "Departman / Bolum Dagilimi");
        }
        if (selScope === 'all' || selScope === 'users') {
            addTableToDoc('subtab-users-table', isEn ? "Personnel Usage History" : "Personel Kullanimi");
        }

        doc.save(`Sarf_Malzeme_Raporu_${selYear}.pdf`);
    }

    function runCsatPdfExport() {
        $('#csatPdfModal').modal('hide');
        const { jsPDF } = window.jspdf;
        
        const selOrient = $('#csat_pdf_orientation').val() || 'portrait';
        const selSize = $('#csat_pdf_size').val() || 'a4';
        
        const doc = new jsPDF({ orientation: selOrient, unit: 'mm', format: selSize });
        
        // Title
        doc.setFontSize(16);
        doc.text(trClean("Musteri Memnuniyeti (CSAT) Raporu"), 15, 20);
        
        doc.setFontSize(10);
        doc.text(trClean("Tarih: " + new Date().toLocaleString('tr-TR')), 15, 28);
        
        const avgScore = <?= json_encode($avgRating) ?>;
        const totalVotes = <?= json_encode($totalRatings) ?>;
        doc.text(trClean(`Ortalama Skor: ${avgScore} / 5.0    |    Toplam Oy Sayisi: ${totalVotes}`), 15, 36);

        // 1. Temsilci Performanslari
        const agentRows = [];
        <?php foreach($agentStats as $ag): ?>
            agentRows.push([
                trClean(<?= json_encode($ag['agent_name']) ?>),
                trClean(<?= json_encode($ag['total_votes']) ?>),
                trClean(<?= json_encode($ag['avg_score'] . ' / 5') ?>)
            ]);
        <?php endforeach; ?>
        
        doc.text(trClean("Temsilci Performans Tablosu"), 15, 46);
        doc.autoTable({
            startY: 50,
            head: [[trClean("Temsilci"), trClean("Oy Sayisi"), trClean("Ortalama Puan")]],
            body: agentRows,
            theme: 'striped',
            styles: { fontSize: 9 }
        });

        // 2. Son Musteri Yorumlari
        const commentRows = [];
        <?php foreach($recentRatings as $rr): ?>
            commentRows.push([
                trClean(<?= json_encode($rr['user_name']) ?>),
                trClean(<?= json_encode('#' . $rr['ticket_no'] . ' ' . $rr['ticket_title']) ?>),
                trClean(<?= json_encode($rr['rating'] . ' / 5') ?>),
                trClean(<?= json_encode($rr['comment'] ?: '-') ?>)
            ]);
        <?php endforeach; ?>

        doc.text(trClean("Son Musteri Yorumlari"), 15, doc.lastAutoTable.finalY + 12);
        doc.autoTable({
            startY: doc.lastAutoTable.finalY + 16,
            head: [[trClean("Musteri"), trClean("Destek Talebi"), trClean("Puan"), trClean("Yorum")]],
            body: commentRows,
            theme: 'striped',
            styles: { fontSize: 8 },
            columnStyles: { 3: { cellWidth: 80 } }
        });

        doc.save('Musteri_Memnuniyeti_CSAT_Raporu.pdf');
    }

    function runAnalyticsPdfExport() {
        $('#analyticsPdfModal').modal('hide');
        const { jsPDF } = window.jspdf;
        
        const selOrient = $('#analytics_pdf_orientation').val() || 'portrait';
        const selSize = $('#analytics_pdf_size').val() || 'a4';
        
        const doc = new jsPDF({ orientation: selOrient, unit: 'mm', format: selSize });
        
        // Calculate page width and height
        const docWidth = selOrient === 'landscape' ? (selSize === 'a3' ? 420 : 297) : (selSize === 'a3' ? 297 : 210);
        const docHeight = selOrient === 'landscape' ? (selSize === 'a3' ? 297 : 210) : (selSize === 'a3' ? 420 : 297);
        
        const imgWidth = docWidth - 30; // 15mm margin
        const imgHeight = imgWidth * 0.42; // Aspect ratio
        
        doc.setFontSize(16);
        doc.text(trClean("Grafik Raporlar ve Analiz Raporu"), 15, 20);
        doc.setFontSize(10);
        doc.text(trClean("Tarih: " + new Date().toLocaleString('tr-TR')), 15, 28);

        try {
            const c1 = document.getElementById('monthlyVolumeChart');
            const c2 = document.getElementById('statusDistributionChart');
            const c3 = document.getElementById('queueDistributionChart');
            const c4 = document.getElementById('agentSlaChart');

            // Add monthly volume chart
            doc.text(trClean("Aylik Destek Talebi Hacmi"), 15, 38);
            doc.addImage(c1.toDataURL('image/png'), 'PNG', 15, 42, imgWidth, imgHeight);

            // Add status distribution chart
            const secondChartTop = 42 + imgHeight + 15;
            // Check if second chart fits on page 1, else add page
            if (secondChartTop + imgHeight > docHeight - 15) {
                doc.addPage();
                doc.text(trClean("Taleplerin Durum Dagilimi"), 15, 20);
                doc.addImage(c2.toDataURL('image/png'), 'PNG', 15, 24, imgWidth, imgHeight);
                
                doc.addPage();
                doc.text(trClean("Kuyruk ve Departman Yuku"), 15, 20);
                doc.addImage(c3.toDataURL('image/png'), 'PNG', 15, 24, imgWidth, imgHeight);
                
                doc.addPage();
                doc.text(trClean("Temsilci SLA Cozum Sureleri (Saat)"), 15, 20);
                doc.addImage(c4.toDataURL('image/png'), 'PNG', 15, 24, imgWidth, imgHeight);
            } else {
                doc.text(trClean("Taleplerin Durum Dagilimi"), 15, secondChartTop - 4);
                doc.addImage(c2.toDataURL('image/png'), 'PNG', 15, secondChartTop, imgWidth, imgHeight);
                
                doc.addPage();
                doc.text(trClean("Kuyruk ve Departman Yuku"), 15, 20);
                doc.addImage(c3.toDataURL('image/png'), 'PNG', 15, 24, imgWidth, imgHeight);
                
                doc.text(trClean("Temsilci SLA Cozum Sureleri (Saat)"), 15, secondChartTop - 4);
                doc.addImage(c4.toDataURL('image/png'), 'PNG', 15, secondChartTop, imgWidth, imgHeight);
            }
        } catch (e) {
            doc.text(trClean("Grafikler yuklenirken bir hata olustu veya sayfada grafik bulunmuyor."), 15, 38);
        }

        doc.save('Grafik_Raporlar_ve_Analiz.pdf');
    }
</script>