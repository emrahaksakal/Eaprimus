<?php
if (isset($_GET['search_select2'])) {
    while (ob_get_level()) { ob_end_clean(); }
    require_once __DIR__ . '/../ajax/get_dynamic_fields.php';
    exit;
}
file_put_contents(__DIR__ . '/../logs/ajax_debug.txt', date('Y-m-d H:i:s') . " - REQ: " . $_SERVER['REQUEST_URI'] . " | GET: " . json_encode($_GET) . " | POST: " . json_encode($_POST) . "\n", FILE_APPEND);
require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/logger.php";
requireLogin();

$pdo = db();
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? 2;
$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

// GiriÅŸ yapan personelin baÄŸlÄ± olduÄŸu MÃ¼ÅŸteri ve Organizasyon bilgilerini bul
$my_linked_customer_id = null;
$my_linked_organization_id = null;
$my_linked_customer_name = '';
$my_linked_organization_name = '';

if ($current_user_role == 2) {
    $stmtMyCust = $pdo->prepare("SELECT c.id, c.name, c.organization_id, o.name as organization_name 
        FROM customers c 
        LEFT JOIN organizations o ON c.organization_id = o.id 
        WHERE c.email COLLATE utf8mb4_general_ci = (SELECT mail COLLATE utf8mb4_general_ci FROM users WHERE id = ?) LIMIT 1");
    $stmtMyCust->execute([$current_user_id]);
    $myCustData = $stmtMyCust->fetch(PDO::FETCH_ASSOC);
    if ($myCustData) {
        $my_linked_customer_id = $myCustData['id'];
        $my_linked_organization_id = $myCustData['organization_id'];
        $my_linked_customer_name = $myCustData['name'];
        $my_linked_organization_name = $myCustData['organization_name'];
    }
}

// Hazır Yanıt Şablonlarını Çek (Kişisel, Takım ve Genel)
$cannedResponses = [];
if ($current_user_id) {
    try {
        $myTeams = [];
        $stmtT = $pdo->prepare("SELECT team_id FROM teams_users WHERE user_id = ?");
        $stmtT->execute([$current_user_id]);
        $myTeams = $stmtT->fetchAll(PDO::FETCH_COLUMN);

        $teamClause = "";
        if (!empty($myTeams)) {
            $inClause = implode(',', array_map('intval', $myTeams));
            $teamClause = " OR (sharing_type = 'team' AND team_id IN ($inClause))";
        }

        $stmtC = $pdo->prepare("SELECT * FROM canned_responses WHERE (user_id = ? AND sharing_type = 'personal') OR (sharing_type = 'team' AND team_id IN (" . (!empty($myTeams) ? implode(',', array_map('intval', $myTeams)) : '0') . ")) OR sharing_type = 'global' ORDER BY category ASC, title ASC");
        $stmtC->execute([$current_user_id]);
        $cannedResponses = $stmtC->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Dynamic paginated Select2 AJAX Autocomplete search endpoint for ticket-olustur page
if (isset($_GET['search_select2'])) {
    try {
        file_put_contents(__DIR__ . '/../logs/ajax_debug.txt', date('Y-m-d H:i:s') . " - ENTERED AJAX BLOCK: " . $_GET['search_select2'] . "\n", FILE_APPEND);
    if (ob_get_length()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    
    $type = trim($_GET['search_select2']);
    $q = trim($_GET['q'] ?? '');
    $page = intval($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $results = [];
    $more = false;
    
    if ($type === 'customers') {
        $orgId = !empty($_GET['organization_id']) ? (int)$_GET['organization_id'] : null;
        $where = " WHERE 1=1";
        $params = [];
        if ($orgId) {
            $where .= " AND organization_id = ?";
            $params[] = $orgId;
        }
        if (!empty($q)) {
            $where .= " AND (name LIKE ? OR email LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        
        $stmt = $pdo->prepare("SELECT id, CONCAT(COALESCE(name, email, ''), CASE WHEN email IS NOT NULL AND email != '' THEN CONCAT(' (', email, ')') ELSE '' END) as text, organization_id FROM customers $where ORDER BY COALESCE(name, email) ASC LIMIT " . ($limit + 1) . " OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) {
            $more = true;
            array_pop($rows);
        }
        $results = $rows;
    } elseif ($type === 'assets') {
        $where = " WHERE deleted_at IS NULL";
        $params = [];
        
        $orgId = !empty($_GET['organization_id']) ? (int)$_GET['organization_id'] : null;
        $custId = !empty($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;

        if ($current_user_role == 2) {
            // Personel (Role 2) iÃ§in sadece kendi zimmetli cihazlarÄ± gelsin
            $where .= " AND assigned_user_id = ?";
            $params[] = $current_user_id;
        } else {
            // Admin ve Teknik Destek iÃ§in: EÄŸer organizasyon veya mÃ¼ÅŸteri seÃ§ilmiÅŸse ona gÃ¶re filtrele
            // MÃ¼ÅŸteri seÃ§ildiyse, mÃ¼ÅŸteriye ait olan (veya baÄŸlÄ± olduÄŸu kuruma ait) cihazlar
            if ($custId || $orgId) {
                // EÄŸer customer_id varsa, mÃ¼ÅŸterinin kullanÄ±cÄ± ID'sini bulup o kullanÄ±cÄ±ya zimmetli cihazlarÄ± da dahil etmeliyiz
                $customerUserId = null;
                if ($custId) {
                    $stmtCu = $pdo->prepare("SELECT u.id FROM users u JOIN customers c ON c.email COLLATE utf8mb4_general_ci = u.mail COLLATE utf8mb4_general_ci WHERE c.id = ?");
                    $stmtCu->execute([$custId]);
                    $customerUserId = $stmtCu->fetchColumn();
                }

                $orConditions = [];
                if ($orgId) {
                    $orConditions[] = "organization_id = " . $orgId;
                }
                if ($customerUserId) {
                    $orConditions[] = "assigned_user_id = " . $customerUserId;
                }

                if (!empty($orConditions)) {
                    $where .= " AND (" . implode(" OR ", $orConditions) . ")";
                }
            } elseif ($current_user_role != 1) {
                // Herhangi bir filtre seÃ§ilmediyse ve Teknik Destek ise kendi/sahipsiz cihazlar
                $where .= " AND (assigned_user_id = ? OR assigned_user_id IS NULL)";
                $params[] = $current_user_id;
            }
        }
        
        if (!empty($q)) {
            $where .= " AND (device_name LIKE ? OR name LIKE ? OR ip_address LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        
        $stmt = $pdo->prepare("
            SELECT id, 
                CONCAT(
                    COALESCE(device_name, name, ''), 
                    CASE WHEN ip_address IS NOT NULL AND ip_address != '' THEN CONCAT(' (', ip_address, ')') ELSE '' END,
                    CASE WHEN assigned_user_id = " . (int)$current_user_id . " THEN ' (Kendi CihazÄ±nÄ±z)' ELSE '' END
                ) as text 
            FROM assets 
            $where 
            ORDER BY (assigned_user_id = " . (int)$current_user_id . ") DESC, COALESCE(device_name, name, '') ASC 
            LIMIT " . ($limit + 1) . " OFFSET $offset
        ");
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
    } catch (Throwable $e) {
        file_put_contents(__DIR__ . '/../logs/ajax_debug.txt', date('Y-m-d H:i:s') . " - ERROR IN AJAX: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
        echo json_encode(['results' => [], 'pagination' => ['more' => false], 'error' => $e->getMessage()]);
    }
    exit;
}

function ticketsPersonnelColumn(PDO $pdo): string
{
    static $col = null;
    if ($col !== null) {
        return $col;
    }
    try {
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'personnel_id'");
        $stmt->execute([$dbName]);
        $hasPersonnel = (int) $stmt->fetchColumn() > 0;
        $col = $hasPersonnel ? 'personnel_id' : 'assigned_to';
        return $col;
    } catch (Throwable $e) {
        $col = 'assigned_to';
        return $col;
    }
}
$personnelCol = ticketsPersonnelColumn($pdo);

// Form POST iÅŸlemi
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_csrf_token();
    if (function_exists('checkRateLimit') && !checkRateLimit('ticket_create', 5, 60)) {
        $error = ($isTr ?? true) ? "⚠️ Çok fazla bilet oluşturma isteği gönderdiniz. Lütfen 1 dakika bekleyip tekrar deneyiniz." : "⚠️ Too many ticket creation requests. Please wait 1 minute before trying again.";
        goto skip_creation;
    }
    file_put_contents(__DIR__ . '/../../app/logs/post_debug.txt', date('Y-m-d H:i:s') . " - POST DATA: " . print_r($_POST, true) . "\n", FILE_APPEND);
    $title = trim($_POST['title'] ?? '');
    $description = $_POST['description'] ?? '';
    $priority = $_POST['priority'] ?? 'normal';
    $queue_id = $_POST['queue_id'] ?? null;
    $asset_id = $_POST['asset_id'] ?: null;
    $customer_id = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : null;
    $organization_id = !empty($_POST['organization_id']) ? (int) $_POST['organization_id'] : null;
    $tags = trim($_POST['tags'] ?? '');

    if ($current_user_role == 2) {
        $customer_id = $my_linked_customer_id;
        $organization_id = $my_linked_organization_id;
    }

    // If customer is selected but organization is not, try to get target organization from customer
    if ($customer_id && !$organization_id) {
        $stmtCustOrg = $pdo->prepare("SELECT organization_id FROM customers WHERE id = ?");
        $stmtCustOrg->execute([$customer_id]);
        $organization_id = $stmtCustOrg->fetchColumn() ?: null;
    }

    if (!empty($title) && !empty($description) && !empty($queue_id)) {
        // SLA SÃ¼releri hesapla
        $stmtQ = $pdo->prepare("SELECT name, sla_resolution_hours, sla_response_hours, critical_keywords, default_priority, team_id, auto_assign_mode FROM queues WHERE id = ?");
        $stmtQ->execute([$queue_id]);
        $queueData = $stmtQ->fetch(PDO::FETCH_ASSOC);

        $slaRes = $queueData['sla_resolution_hours'] ?? 24;
        $slaResp = $queueData['sla_response_hours'] ?? 4;
        $teamId = $queueData['team_id'] ?? null;
        $autoMode = $queueData['auto_assign_mode'] ?? 'manual';

        // Kritik Kelime KontrolÃ¼
        $keywords = $queueData['critical_keywords'] ?? '';
        if (!empty($keywords)) {
            foreach (explode(',', $keywords) as $k) {
                if (trim($k) !== '' && (stripos($title, trim($k)) !== false || stripos($description, trim($k)) !== false)) {
                    $priority = 'critical';
                    break;
                }
            }
        }

        // Manuel Atama (Admin/Manager iÃ§in)
        $assigned_to = !empty($_POST['personnel_id']) ? (int) $_POST['personnel_id'] : null;

        // Otomatik Atama (EÄŸer manuel seÃ§ilmediyse)
        if (!$assigned_to) {
            if ($autoMode == 'least_active' && $teamId) {
                $stmtA = $pdo->prepare("SELECT u.id FROM users u
                    JOIN teams_users tu ON u.id = tu.user_id
                    WHERE tu.team_id = ? AND u.status = 1
                    ORDER BY (SELECT COUNT(id) FROM tickets WHERE $personnelCol = u.id AND status NOT IN ('closed','resolved')) ASC LIMIT 1");
                $stmtA->execute([$teamId]);
                $assigned_to = $stmtA->fetchColumn() ?: null;
            } elseif ($autoMode == 'round_robin' && $teamId) {
                $stmtRR = $pdo->prepare("SELECT user_id FROM teams_users WHERE team_id = ? ORDER BY RAND() LIMIT 1");
                $stmtRR->execute([$teamId]);
                $assigned_to = $stmtRR->fetchColumn() ?: null;
            }
        }

        $status = $assigned_to ? 'assigned' : 'open';

        // Adjust SLA resolution & response hours based on selected priority
        switch (strtolower($priority)) {
            case 'critical':
                $slaRes = 2;
                $slaResp = 1;
                break;
            case 'urgent':
                $slaRes = 4;
                $slaResp = 2;
                break;
            case 'high':
                $slaRes = 8;
                $slaResp = 4;
                break;
            case 'low':
                $slaRes = 72;
                $slaResp = 24;
                break;
            case 'normal':
            default:
                // Keep queue defaults
                break;
        }

        $calcRes = date('Y-m-d H:i:s', strtotime("+$slaRes hours"));
        $calcFirst = date('Y-m-d H:i:s', strtotime("+$slaResp hours"));

        // 0. Dosya Uzantı Güvenlik Kontrolü (Bilet Eklemeden Önce!)
        $allowedExts = getAllowedUploadExtensions();
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $idx => $fname) {
                if ($_FILES['attachments']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                $originalName = basename($fname);
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (empty($ext) || !in_array($ext, $allowedExts)) {
                    $error = ($isTr 
                        ? "⚠️ Desteklenmeyen Dosya Formatı! Yüklemeye çalıştığınız <strong>'" . htmlspecialchars($originalName) . "'</strong> dosyası güvenlik kuralları gereği kabul edilmemektedir. <br>İzin verilen formatlar: <strong class='text-uppercase'>" . implode(', ', $allowedExts) . "</strong>"
                        : "⚠️ Unsupported File Format! The file <strong>'" . htmlspecialchars($originalName) . "'</strong> is not allowed. <br>Allowed formats: <strong class='text-uppercase'>" . implode(', ', $allowedExts) . "</strong>");
                    goto skip_creation;
                }
            }
        }

        $ticketPrefix = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ticket_prefix'")->fetchColumn() ?: 'TCK';
        $ticketNo = $ticketPrefix . '-' . date('YmdHis') . rand(10, 99);

        try {
            $stmtInsert = $pdo->prepare("INSERT INTO tickets
                (ticket_no, title, description, priority, status, queue_id, creator_id, $personnelCol, customer_id, organization_id, asset_id, tags, sla_due_date, first_response_deadline, agent_read, unread_replies_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");
            $stmtInsert->execute([$ticketNo, $title, $description, $priority, $status, $queue_id, $current_user_id, $assigned_to, $customer_id, $organization_id, $asset_id, $tags, $calcRes, $calcFirst]);
            $ticketId = $pdo->lastInsertId();
        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/../../app/logs/sql_error.txt', date('Y-m-d H:i:s') . " - TICKET INSERT ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            $error = "VeritabanÄ± hatasÄ± oluÅŸtu: " . $e->getMessage();
            goto skip_creation;
        }

        // Custom AlanlarÄ± Kaydet
        if (!empty($_POST['custom_fields'])) {
            $stmtCF = $pdo->prepare("INSERT INTO customer_field_values (field_id, ticket_id, value) VALUES (?, ?, ?)");
            foreach ($_POST['custom_fields'] as $fid => $fval) {
                if ($fval !== '') {
                    $stmtCF->execute([(int)$fid, $ticketId, $fval]);
                }
            }
        }

        // Log
        ticketLogAl($pdo, $current_user_id, 'OLUSTURULDU', $ticketNo, $title);

        // Dosya Yükleme
        if (!empty($_FILES['attachments']['name'][0])) {
            $uploadDir = __DIR__ . '/../../public/uploads/tickets/';
            if (!is_dir($uploadDir))
                mkdir($uploadDir, 0755, true);

            $allowedExts  = getAllowedUploadExtensions();
            $allowedMimes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/pjpeg', 'image/x-png',
                'application/pdf', 'application/x-pdf', 'application/acrobat', 'applications/vnd.pdf', 'text/pdf', 'text/x-pdf',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/x-msword', 'application/vnd.ms-word',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/msexcel', 'application/x-msexcel', 'application/x-ms-excel', 'application/x-excel', 'application/xls', 'application/x-xls',
                'application/zip', 'application/x-zip-compressed', 'application/x-zip', 'multipart/x-zip',
                'application/x-rar-compressed', 'application/x-rar', 'application/vnd.rar', 'application/rar',
                'application/x-7z-compressed',
                'text/plain', 'text/csv', 'text/x-comma-separated-values', 'text/comma-separated-values', 'application/csv',
                'application/octet-stream'
            ];
            $dangerousMimes = ['text/html', 'text/javascript', 'application/javascript', 'application/x-javascript', 'application/x-php', 'application/x-httpd-php', 'application/x-executable', 'application/x-msdownload', 'application/x-sh'];

            foreach ($_FILES['attachments']['name'] as $idx => $fname) {
                if ($_FILES['attachments']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                $originalName = basename($fname);
                $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExts)) continue;
                // Gerçek MIME kontrolü
                $realMime = mime_content_type($_FILES['attachments']['tmp_name'][$idx]);
                if (!empty($realMime) && in_array(strtolower($realMime), $dangerousMimes)) continue;
                if (empty($realMime)) $realMime = 'application/octet-stream';

                // Diskteki isim rastgele, DB'de orijinal isim
                $safeFile = $ticketId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($_FILES['attachments']['tmp_name'][$idx], $uploadDir . $safeFile)) {
                    $pdo->prepare("INSERT INTO ticket_attachments (ticket_id, uploader_id, file_name, file_path, file_type, file_size) VALUES (?,?,?,?,?,?)")
                        ->execute([$ticketId, $current_user_id, $originalName, 'uploads/tickets/' . $safeFile, $realMime, $_FILES['attachments']['size'][$idx]]);
                }
            }
        }

        // ============================================================
        // BÄ°LDÄ°RÄ°MLER VE OTOMASYON
        // ============================================================
        require_once __DIR__ . '/../includes/rule_engine.php';
        runTicketRules($pdo, $ticketId);

        require_once __DIR__ . '/../includes/notification_helper.php';
        sendNewTicketNotifications($ticketId, $pdo);
        // ============================================================

        $ticketDetailUrl = 'bilet-detay/' . $ticketId;
        $_SESSION['mesaj'] = "✅ " . t("your_support_ticket_has_been_received", "Destek Talebiniz Alındı") . " (" . t('ticket_no', 'Bilet No') . ": <strong>$ticketNo</strong>)";
        header("Location: " . $ticketDetailUrl);
        exit;

        skip_creation:
    } else {
        $error = __("fill_required_fields");
    }
}

// Kuyruklar
$queues = $pdo->query("SELECT id, name, team_id FROM queues WHERE status = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Cihazlar (Dinamik AJAX Autocomplete kullanÄ±ldÄ±ÄŸÄ± iÃ§in boÅŸ bÄ±rakÄ±lmÄ±ÅŸtÄ±r)
$assets = [];

// Personel Listesi (Atama iÃ§in - Kuyruk seçildiğinde dinamik yüklenecek)
$allPersonnel = [];
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
<style>
    .ticket-create-card { border: none; box-shadow: 0 4px 20px rgba(0, 0, 0, .08); border-radius: 12px; }
    .ticket-header-bg { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); border-radius: 12px 12px 0 0; }
    .priority-btn { border: 2px solid; border-radius: 20px; padding: 4px 14px; margin: 4px; cursor: pointer; font-size: 13px; font-weight: 600; transition: all .2s; }
    .priority-btn:hover, .priority-btn.selected { color: #fff !important; transform: scale(1.05); }
    .priority-btn.low { border-color: #28a745; color: #28a745; }
    .priority-btn.low.selected { background: #28a745; }
    .priority-btn.normal { border-color: #17a2b8; color: #17a2b8; }
    .priority-btn.normal.selected { background: #17a2b8; }
    .priority-btn.high { border-color: #fd7e14; color: #fd7e14; }
    .priority-btn.high.selected { background: #fd7e14; }
    .priority-btn.critical { border-color: #dc3545; color: #dc3545; }
    .priority-btn.critical.selected { background: #dc3545; }
    #editor-container { height: 300px; background: #fff; }
    .drop-zone { border: 2px dashed #ced4da; border-radius: 8px; padding: 30px; text-align: center; cursor: pointer; transition: all .2s; background: #f8f9fa; }
    .drop-zone:hover, .drop-zone.dragover { border-color: #1e3c72; background: #eff6ff; }
    .drop-zone i { font-size: 2rem; color: #ced4da; }
    .file-preview-item { display: flex; align-items: center; background: #f8f9fa; padding: 8px 12px; border-radius: 6px; margin-top: 8px; }
    .file-preview-item i { margin-right: 10px; color: #1e3c72; }

    body.dark-mode .ticket-create-card { background: #343a40 !important; border-color: #444; }
    body.dark-mode .ticket-header-bg { background: linear-gradient(135deg, #12213e 0%, #1a325c 100%) !important; }
    body.dark-mode .card-footer.bg-light { background: #24282d !important; border-top: 1px solid #3a424a; }
    body.dark-mode .form-control,
    body.dark-mode .custom-file-label,
    body.dark-mode .note-editor,
    body.dark-mode .drop-zone,
    body.dark-mode .file-preview-item {
        background: #1f242a !important;
        color: #e5e7eb !important;
        border-color: #3a424a !important;
    }
    body.dark-mode .note-editing-area { background: #1f242a !important; color: #e5e7eb !important; }
    body.dark-mode .note-toolbar { background: #2b3035 !important; border-bottom: 1px solid #444 !important; }
    body.dark-mode .note-btn { color: #e5e7eb !important; background: #343a40 !important; border-color: #4b5563 !important; }
    body.dark-mode .note-btn:hover { background: #4b5563 !important; }
    body.dark-mode .note-btn.active { background: #6c757d !important; color: #fff !important; }
    body.dark-mode .text-muted { color: #94a3b8 !important; }
</style>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible shadow-sm">
                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div class="card ticket-create-card">
            <div class="ticket-header-bg p-4 text-white">
                <h4 class="mb-0"><i class="fas fa-plus-circle mr-2"></i><?= __('create_new_ticket_title') ?></h4>
                <p class="mb-0 opacity-75 small mt-1"><?= __('create_new_ticket_desc') ?></p>
            </div>

            <form method="POST" enctype="multipart/form-data" id="ticketForm">
                <?= csrf_field() ?>
                <div class="card-body p-4">
                    <div class="form-group">
                        <label class="font-weight-bold"><?= __('subject_title_label') ?> <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="title" class="form-control form-control-lg" placeholder="<?= __('subject_placeholder') ?>" required maxlength="255">
                        <div id="kb-suggestions" class="mt-2" style="display:none;"></div>
                        <small class="text-muted" id="titleCount">0 / 255 <?= __('char_count') ?></small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold"><?= __('support_queue_label') ?> <span class="text-danger">*</span></label>
                            <select name="queue_id" id="queue_id" class="form-control select2" style="width:100%" required>
                                <option value=""><?= __('select_queue_placeholder') ?></option>
                                <?php foreach ($queues as $q): ?>
                                    <option value="<?php echo $q['id']; ?>" data-team-id="<?php echo $q['team_id']; ?>"><?php echo htmlspecialchars($q['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($current_user_role == 2): ?>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold"><i class="fas fa-building mr-1"></i><?= __('organization') ?></label>
                                <div class="form-control bg-light font-weight-bold text-dark border-left-4" style="border-radius:10px; border-left: 4px solid #1e3c72 !important; height:calc(2.25rem + 10px); padding: 8px 12px; font-size:15px;">
                                    <?= htmlspecialchars($my_linked_organization_name ?: 'GÜRSOYLAR A.Ş.') ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold"><i class="fas fa-building mr-1"></i><?= __('organization') ?></label>
                                <select name="organization_id" id="organization_id" class="form-control select2" style="width:100%">
                                    <option value=""><?= __('select_organization') ?></option>
                                    <?php
                                    $orgList = $pdo->query("SELECT id, name FROM organizations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($orgList as $o):
                                    ?>
                                        <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <?php if ($current_user_role == 2): ?>
                            <div class="col-md-12 form-group">
                                <label class="font-weight-bold"><i class="fas fa-user-circle mr-1"></i><?= __('contact') ?></label>
                                <div class="form-control bg-light font-weight-bold text-dark border-left-4" style="border-radius:10px; border-left: 4px solid #10b981 !important; height:calc(2.25rem + 10px); padding: 8px 12px; font-size:15px;">
                                    <?= htmlspecialchars($_SESSION['fullname']) ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold"><i class="fas fa-user-circle mr-1"></i><?= __('contact') ?> <small class="text-muted">(<?= $isTr ? 'Kendiniz iÃ§in aÃ§Ä±yorsanÄ±z boÅŸ bÄ±rakÄ±n' : 'Leave empty for yourself' ?>)</small></label>
                                <select name="customer_id" id="customer_id" class="form-control select2-ajax" data-type="customers" style="width:100%">
                                    <option value=""><?= __('select_contact') ?></option>
                                    <?php if (!empty($_POST['customer_id'])): 
                                        $stmtSelCust = $pdo->prepare("SELECT id, name, email FROM customers WHERE id = ?");
                                        $stmtSelCust->execute([(int)$_POST['customer_id']]);
                                        $selCust = $stmtSelCust->fetch(PDO::FETCH_ASSOC);
                                        if ($selCust):
                                    ?>
                                        <option value="<?= $selCust['id'] ?>" selected><?= htmlspecialchars($selCust['name']) ?> (<?= htmlspecialchars($selCust['email']) ?>)</option>
                                    <?php endif; endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold"><i class="fas fa-user-tag mr-1"></i><?= __('assigned_personnel') ?></label>
                                <select name="personnel_id" id="personnel_id" class="form-control select2" style="width:100%">
                                    <option value=""><?= $isTr ? "Lütfen önce bir kuyruk seçin" : "Please select a queue first" ?></option>
                                    <?php foreach ($allPersonnel as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['fullname']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- DINAMIK ALANLAR -->
                    <div id="dynamic-fields-container" class="mt-2"></div>

                    <div class="form-group mt-3">
                        <label class="font-weight-bold"><?= __('related_device_label') ?></label>
                        <?php if ($current_user_role == 2): ?>
                            <?php
                            // GiriÅŸ yapan personelin kendi aktif zimmetli cihazlarÄ±nÄ± Ã§ek
                            $stmtMyAssets = $pdo->prepare("SELECT id, name, asset_tag FROM assets WHERE assigned_user_id = ? AND deleted_at IS NULL ORDER BY name ASC");
                            $stmtMyAssets->execute([$current_user_id]);
                            $myAssets = $stmtMyAssets->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <select name="asset_id" id="asset_id" class="form-control select2" style="width:100%">
                                <option value=""><?= __('select_device_placeholder') ?></option>
                                <?php foreach ($myAssets as $a): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?> <?= !empty($a['asset_tag']) ? "({$a['asset_tag']})" : "" ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <select name="asset_id" id="asset_id" class="form-control select2-ajax" data-type="assets" style="width:100%">
                                <option value=""><?= __('select_device_placeholder') ?></option>
                                <?php if (!empty($_POST['asset_id'])): 
                                    $stmtSelAsset = $pdo->prepare("SELECT id, name, ip_address FROM assets WHERE id = ?");
                                    $stmtSelAsset->execute([(int)$_POST['asset_id']]);
                                    $selAsset = $stmtSelAsset->fetch(PDO::FETCH_ASSOC);
                                    if ($selAsset):
                                ?>
                                    <option value="<?= $selAsset['id'] ?>" selected><?= htmlspecialchars($selAsset['name']) ?> <?= $selAsset['ip_address'] ? "({$selAsset['ip_address']})" : '' ?></option>
                                <?php endif; endif; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="mb-2"><label class="font-weight-bold mb-0"><?= __('priority_label') ?></label></div>
                    <div class="mb-3">
                        <button type="button" class="priority-btn low" data-val="low"><?= __('low_prio') ?></button>
                        <button type="button" class="priority-btn normal selected" data-val="normal"><?= __('normal_prio') ?></button>
                        <button type="button" class="priority-btn high" data-val="high"><?= __('high_prio') ?></button>
                        <button type="button" class="priority-btn critical" data-val="critical"><?= __('critical_prio') ?></button>
                        <input type="hidden" name="priority" id="priorityInput" value="normal">
                    </div>

                    <div class="form-group">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="font-weight-bold mb-0"><?= __('description_label') ?> <span class="text-danger">*</span></label>
                            <?php if (!empty($cannedResponses)): ?>
                            <div class="d-flex align-items-center bg-white border rounded px-3 py-1 shadow-sm" style="border-color: #cbd5e1; font-size: 13px;">
                                <i class="fas fa-bolt text-warning mr-2"></i>
                                <select id="cannedResponseSelect" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0" style="cursor: pointer; font-weight: 500; min-width: 160px;" onchange="insertCannedResponseToSummernote(this.value)">
                                    <option value="" selected><?= $isTr ? '-- Hazır Yanıt Seç --' : '-- Select Canned Response --' ?></option>
                                    <?php foreach ($cannedResponses as $cr):
                                        $cCategory = (!$isTr && !empty($cr['category_en'])) ? $cr['category_en'] : $cr['category'];
                                        $cTitle = (!$isTr && !empty($cr['title_en'])) ? $cr['title_en'] : $cr['title'];
                                        $cContent = (!$isTr && !empty($cr['content_en'])) ? $cr['content_en'] : $cr['content'];
                                    ?>
                                        <option value="<?= htmlspecialchars($cContent, ENT_QUOTES, 'UTF-8') ?>">⚡ [<?= htmlspecialchars($cCategory) ?>] <?= htmlspecialchars($cTitle) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                        </div>
                        <textarea name="description" id="summernote"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold"><?= __('tags_label') ?></label>
                        <input type="text" name="tags" class="form-control" placeholder="<?= __('tags_placeholder') ?>">
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold"><?= __('attachments_label') ?></label>
                        <?php 
                        $uiAllowedExts = getAllowedUploadExtensions();
                        $uiAllowedExtsStr = strtoupper(implode(', ', $uiAllowedExts));
                        $uiAcceptAttr = '.' . implode(',.', $uiAllowedExts);
                        ?>
                        <div class="drop-zone" id="dropZone">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <div class="mt-2"><?= __('drop_zone_text') ?></div>
                            <small class="text-muted"><?= $isTr ? "İzin Verilen Formatlar: <strong class='text-primary'>{$uiAllowedExtsStr}</strong> (Maks 10MB)" : "Allowed Formats: <strong class='text-primary'>{$uiAllowedExtsStr}</strong> (Max 10MB)" ?></small>
                            <input type="file" name="attachments[]" id="fileInput" multiple style="display:none" accept="<?= $uiAcceptAttr ?>">
                        </div>
                        <div id="filePreview" class="mt-2"></div>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-between align-items-center bg-light p-4">
                    <a href="biletler" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i><?= __('cancel') ?></a>
                    <button type="submit" class="btn btn-primary btn-lg px-5 shadow-sm" id="submitBtn">
                        <i class="fas fa-paper-plane mr-2"></i><?= __('send_ticket_btn') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
<script>
function insertCannedResponseToSummernote(content) {
    if (!content) return;
    if ($('#summernote').length) {
        $('#summernote').summernote('code', content);
    } else {
        const txt = document.getElementById('summernote');
        if (txt) txt.value = content;
    }
    const sel = document.getElementById('cannedResponseSelect');
    if (sel) sel.value = '';
}
$(document).ready(function() {
    let kbTimer;
    $("#title").on("input", function() {
        clearTimeout(kbTimer);
        let q = $(this).val();
        if(q.length >= 3) {
            kbTimer = setTimeout(function() {
                $.getJSON("<?= $base_url ?>ajax/kb_search.php", {q: q}, function(res) {
                    if(res && res.length > 0) {
                        let html = "<div class='alert alert-info py-2 px-3' style='border-radius:10px;'><h6 class='font-weight-bold mb-2'><i class='fas fa-lightbulb text-warning mr-2'></i> <?= $isTr ? 'Bu sorunu çözebilecek makaleler bulduk:' : 'We found articles that might solve this:' ?></h6><ul class='mb-0 pl-3'>";
                        res.forEach(item => {
                            html += "<li><a href='anasayfa?route=knowledge_base&action=view&id="+item.id+"' target='_blank' class='text-dark font-weight-bold'>" + item.title + "</a></li>";
                        });
                        html += "</ul></div>";
                        $("#kb-suggestions").html(html).slideDown();
                    } else {
                        $("#kb-suggestions").slideUp();
                    }
                });
            }, 500);
        } else {
            $("#kb-suggestions").slideUp();
        }
    });
        $('#summernote').summernote({
            placeholder: <?= json_encode(__('description_editor_placeholder')) ?>,
            tabsize: 2,
            height: 300,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'clear']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['table', ['table']],
                ['insert', ['link', 'picture']],
                ['view', ['fullscreen', 'codeview']]
            ]
        });

        // Standard Select2 initialization (excluding AJAX ones)
        $('.select2:not(.select2-ajax)').select2();

        // AJAX Select2 for large scale autocompletes (Customers & Assets)
        // Wrapped in setTimeout to ensure our AJAX config runs AFTER dashboard.php's generic .select2() init
        setTimeout(function () {

        $('.select2-ajax').each(function () {
            const $el = $(this);
            const type = $el.data('type');
            $el.select2({
                theme: 'bootstrap4',
                width: '100%',
                ajax: {
                    url: '<?= $base_url ?>bilet-ekle',
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        const query = {
                            search_select2: type,
                            q: params.term || '',
                            page: params.page || 1
                        };
                        if (type === 'customers') {
                            query.organization_id = $('#organization_id').val();
                        }
                        if (type === 'assets') {
                            query.organization_id = $('#organization_id').val();
                            query.customer_id = $('#customer_id').val();
                        }
                        return query;
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
                placeholder: 'SeÃ§iniz / Select...',
                allowClear: true,
                minimumInputLength: 0
            }).on('select2:open', function() {
                setTimeout(function() {
                    const searchInput = $('.select2-container--open .select2-search__field');
                    if (searchInput.length && !searchInput.val()) {
                        searchInput.val(' ').trigger('input');
                        searchInput.val('').trigger('input');
                    }
                }, 100);
            });


        });

        }, 300);

        const orgSelect = $('#organization_id');
        const custSelect = $('#customer_id');
        const dynamicContainer = $('#dynamic-fields-container');

        orgSelect.on('change', function() {
            const orgId = $(this).val();
            custSelect.val(null).trigger('change');
            loadDynamicFields('organization', orgId);
        });

        custSelect.on('change', function() {
            const custId = $(this).val();
            loadDynamicFields('contact', custId);
        });

        function loadDynamicFields(type, id) {
            // Remove old ones of this type
            dynamicContainer.find(`.dynamic-field[data-type="${type}"]`).remove();
            if (!id) return;
            
            $.get('ajax/get-dynamic-fields', { target_type: type, target_id: id }, function(res) {
                if (res.fields && res.fields.length > 0) {
                    let html = `<div class="dynamic-field-group mb-3 dynamic-field" data-type="${type}">`;
                    html += `<h6 class="text-primary font-weight-bold mb-3"><i class="fas ${type == 'contact' ? 'fa-user' : 'fa-building'} mr-1"></i> ${type == 'contact' ? '<?= __('contact_custom_fields') ?>' : '<?= __('organization_custom_fields') ?>'}</h6>`;
                    res.fields.forEach(f => {
                        html += `
                        <div class="form-group mb-2">
                            <label class="small font-weight-bold mb-1">${f.label} ${f.required == 1 ? '<span class="text-danger">*</span>' : ''}</label>
                            ${renderField(f, res.values[f.id] || '')}
                        </div>`;
                    });
                    html += `</div>`;
                    dynamicContainer.append(html);
                }
            });
        }

        function renderField(f, val) {
            const name = `custom_fields[${f.id}]`;
            const req = f.required == 1 ? 'required' : '';
            switch(f.field_type) {
                case 'textarea': return `<textarea name="${name}" class="form-control form-control-sm" ${req}>${val}</textarea>`;
                case 'dropdown':
                    let opts = (f.options || '').split(',').map(o => o.trim());
                    return `<select name="${name}" class="form-control form-control-sm" ${req}>
                        <option value=""><?= __('select') ?>...</option>
                        ${opts.map(o => `<option value="${o}" ${o == val ? 'selected' : ''}>${o}</option>`).join('')}
                    </select>`;
                case 'checkbox':
                    return `<div class="custom-control custom-checkbox"><input type="checkbox" name="${name}" class="custom-control-input" id="cf_${f.id}" value="1" ${val == '1' ? 'checked' : ''} ${req}><label class="custom-control-label" for="cf_${f.id}">${f.label}</label></div>`;
                case 'date': return `<input type="date" name="${name}" class="form-control form-control-sm" value="${val}" ${req}>`;
                case 'number': return `<input type="number" name="${name}" class="form-control form-control-sm" value="${val}" ${req}>`;
                default: return `<input type="text" name="${name}" class="form-control form-control-sm" value="${val}" ${req}>`;
            }
        }

        // Karakter sayacÄ±
        document.getElementById('title').addEventListener('input', function () { document.getElementById('titleCount').textContent = this.value.length + ' / 255 <?= __('char_count') ?>'; });

        // Ã–ncelik
        $('.priority-btn').click(function() {
            $('.priority-btn').removeClass('selected');
            $(this).addClass('selected');
            $('#priorityInput').val($(this).data('val'));
        });

        // Dosyalar
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const filePreview = document.getElementById('filePreview');
        const allowedExtsJS = <?= json_encode(getAllowedUploadExtensions()) ?>;

        dropZone.onclick = () => fileInput.click();
        dropZone.ondragover = e => { e.preventDefault(); dropZone.classList.add('dragover'); };
        dropZone.ondragleave = () => dropZone.classList.remove('dragover');
        dropZone.ondrop = e => { 
            e.preventDefault(); 
            dropZone.classList.remove('dragover'); 
            if (validateFiles(e.dataTransfer.files)) {
                fileInput.files = e.dataTransfer.files; 
                showPreviews(e.dataTransfer.files); 
            }
        };
        fileInput.onchange = () => {
            if (validateFiles(fileInput.files)) {
                showPreviews(fileInput.files);
            }
        };

        function validateFiles(files) {
            if (!files || files.length === 0) return true;
            let invalidFiles = [];
            Array.from(files).forEach(f => {
                let parts = f.name.split('.');
                let ext = parts.length > 1 ? parts.pop().toLowerCase() : '';
                if (!allowedExtsJS.includes(ext)) {
                    invalidFiles.push(f.name);
                }
            });

            if (invalidFiles.length > 0) {
                fileInput.value = '';
                filePreview.innerHTML = '';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: '<?= $isTr ? "Desteklenmeyen Dosya Formatı!" : "Unsupported File Format!" ?>',
                        html: '<?= $isTr ? "Seçtiğiniz dosya(lar) güvenlik politikaları gereği yüklenemez:" : "The selected file(s) are not allowed:" ?><br><strong class="text-danger mt-1 d-block">' + invalidFiles.join(', ') + '</strong><br><small class="text-muted"><?= $isTr ? "İzin Verilen Formatlar:" : "Allowed Formats:" ?> <b class="text-uppercase">' + allowedExtsJS.join(', ') + '</b></small>',
                        confirmButtonText: '<?= $isTr ? "Anladım" : "OK" ?>',
                        confirmButtonColor: '#e11d48'
                    });
                } else {
                    alert('<?= $isTr ? "Desteklenmeyen Dosya Formatı! İzin verilen formatlar: " : "Unsupported File Format! Allowed formats: " ?>' + allowedExtsJS.join(', ').toUpperCase());
                }
                return false;
            }
            return true;
        }

        function showPreviews(files) {
            filePreview.innerHTML = '';
            Array.from(files).forEach(f => { filePreview.innerHTML += `<div class="file-preview-item"><i class="fas fa-file"></i><span>${f.name}</span><small class="ml-auto text-muted">${(f.size / 1024).toFixed(1)} KB</small></div>`; });
        }

        $('#queue_id').on('change', function() {
            const selectedOption = $(this).find('option:selected');
            const teamId = selectedOption.data('team-id');
            const personnelSelect = $('#personnel_id');
            
            if (personnelSelect.length === 0) return;
            
            personnelSelect.empty().append('<option value=""><?= $isTr ? "Yükleniyor..." : "Loading..." ?></option>').trigger('change');
            
            if (teamId) {
                $.getJSON('<?= $base_url ?>ajax/get_team_assignees.php', { team_id: teamId }, function(data) {
                    personnelSelect.empty().append('<option value=""><?= __("select") ?> (<?= __("automation") ?>)</option>');
                    if (data && data.length > 0) {
                        data.forEach(function(p) {
                            const escapedName = $('<div>').text(p.fullname).html();
                            personnelSelect.append('<option value="' + p.id + '">' + escapedName + '</option>');
                        });
                    }
                    personnelSelect.trigger('change');
                }).fail(function() {
                    personnelSelect.empty().append('<option value=""><?= $isTr ? "Hata oluştu" : "Error occurred" ?></option>').trigger('change');
                });
            } else {
                personnelSelect.empty().append('<option value=""><?= $isTr ? "Lütfen önce bir kuyruk seçin" : "Please select a queue first" ?></option>').trigger('change');
            }
        });

        // Trigger change event on page load in case a queue is pre-selected
        $('#queue_id').trigger('change');

        $('#ticketForm').on('submit', function () {
            const queueVal = ($('#queue_id').val() || '').trim();
            const titleVal = ($('#title').val() || '').trim();
            const content = $('#summernote').summernote('code') || '';
            
            // Safely extract plain text without jQuery selector parsing syntax errors
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = content;
            const plainText = (tempDiv.textContent || tempDiv.innerText || '').trim();
            
            if (queueVal === '' || titleVal === '') {
                $('#submitBtn').prop('disabled', false).html('<i class="fas fa-paper-plane mr-2"></i><?= __('send_ticket_btn') ?>');
                return false;
            }

            if (plainText === '' && content.indexOf('<img') === -1 && content.indexOf('<table') === -1) {
                $('#submitBtn').prop('disabled', false).html('<i class="fas fa-paper-plane mr-2"></i><?= __('send_ticket_btn') ?>');
                return false;
            }

            const $btn = $('#submitBtn');
            setTimeout(function () {
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i><?= __('sending') ?>');
            }, 10);

            return true;
        });
    });
</script>

