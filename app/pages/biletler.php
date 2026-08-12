<?php
require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/db.php";
requireLogin();

$pdo = db();
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? 2;

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

// Filtreler
$filterStatus = $_GET['status'] ?? 'open'; // Default to open
$filterPriority = $_GET['priority'] ?? '';
$filterQueue = $_GET['queue_id'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');

// Sorgu Oluştur
$where = [tenantWhere('t')];
$params = [];

// Role/Permission göre görünürlük (Sadece Master Admin (Role 1) tüm biletleri görür)
if ($current_user_role == 1) {
    // Admin (Role 1): Tüm biletleri görebilir
} elseif ($current_user_role == 2) {
    // Personel (Role 2): Sadece kendi oluşturduğu veya müşteri olduğu biletleri görebilir (Başka biletleri göremez)
    $where[] = "(t.creator_id = ? 
        OR t.customer_id = ? 
        OR t.customer_id IN (SELECT id FROM customers WHERE email COLLATE utf8mb4_general_ci = (SELECT mail COLLATE utf8mb4_general_ci FROM users WHERE id = ?)))";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
    $params[] = $current_user_id;
} else {
    // Teknik Destek (Role 3): Kendisine atanan, kendi oluşturduğu, müşteri olduğu veya üye olduğu takımın kuyruğundaki biletler
    $where[] = "(t.creator_id = ? 
        OR t.customer_id = ? 
        OR t.customer_id IN (SELECT id FROM customers WHERE email COLLATE utf8mb4_general_ci = (SELECT mail COLLATE utf8mb4_general_ci FROM users WHERE id = ?))
        OR t.$personnelCol = ? 
        OR t.queue_id IN (SELECT id FROM queues WHERE team_id IN (SELECT team_id FROM teams_users WHERE user_id = ?)))";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
    $params[] = $current_user_id;
    $params[] = $current_user_id;
    $params[] = $current_user_id;
}

if ($filterStatus && $filterStatus !== 'all') {
    if ($filterStatus == 'closed') {
        $where[] = "t.status IN ('resolved', 'closed')";
    } elseif ($filterStatus == 'open') {
        $where[] = "t.status NOT IN ('resolved', 'closed')";
    } else {
        $where[] = "t.status = ?";
        $params[] = $filterStatus;
    }
}
if ($filterPriority) {
    $where[] = "t.priority = ?";
    $params[] = $filterPriority;
}
if ($filterQueue) {
    $where[] = "t.queue_id = ?";
    $params[] = $filterQueue;
}
if ($filterSearch) {
    $searchWild = "%$filterSearch%";
    $where[] = "(
        t.title LIKE ? 
        OR t.ticket_no LIKE ? 
        OR t.description LIKE ? 
        OR u.fullname LIKE ? 
        OR u.mail LIKE ? 
        OR a.fullname LIKE ? 
        OR c.name LIKE ? 
        OR o.name LIKE ? 
        OR ast.name LIKE ? 
        OR ast.asset_tag LIKE ? 
        OR ast.serial_no LIKE ?
    )";
    for ($si = 0; $si < 11; $si++) {
        $params[] = $searchWild;
    }
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT t.*,
        u.fullname AS creator_name,
        a.fullname AS agent_name,
        ab.fullname AS assigner_name,
        q.name AS queue_name,
        c.name AS customer_name,
        c.id AS customer_id,
        o.name AS organization_name,
        o.id AS organization_id,
        ast.name AS asset_name,
        ast.asset_tag AS asset_tag,
        lu.fullname AS locked_by_name,
        TIMESTAMPDIFF(MINUTE, NOW(), t.sla_due_date) AS sla_minutes_left
    FROM tickets t
    LEFT JOIN users u ON t.creator_id = u.id
    LEFT JOIN users a ON t.$personnelCol = a.id
    LEFT JOIN users ab ON t.assigned_by = ab.id
    LEFT JOIN users lu ON t.locked_by = lu.id
    LEFT JOIN queues q ON t.queue_id = q.id
    LEFT JOIN customers c ON t.customer_id = c.id
    LEFT JOIN organizations o ON (t.organization_id = o.id OR c.organization_id = o.id)
    LEFT JOIN assets ast ON t.asset_id = ast.id
    $whereSQL
    ORDER BY
        FIELD(t.priority,'critical','high','normal','low'),
        t.create_date DESC
    LIMIT 200
");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Status & Priority Configuration
$priorityColors = ['low' => '#28a745', 'normal' => '#17a2b8', 'high' => '#fd7e14', 'critical' => '#dc3545'];
$priorityLabels = ['low' => __("low"), 'normal' => __("normal"), 'high' => __("high"), 'critical' => __("critical")];

$statusConfig = json_decode($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ticket_statuses_config'")->fetchColumn() ?: '', true);
if (empty($statusConfig)) {
    $statusLabels = [
        'open' => __("ticket_status_open") ?: 'Açık',
        'assigned' => __("ticket_status_assigned") ?: 'Atanmış',
        'waiting_customer' => __("ticket_status_waiting_customer") ?: 'Müşteri Yanıtı Bekleniyor',
        'closed' => __("ticket_status_closed") ?: 'Kapalı'
    ];
    $statusColors = [
        'open' => '#3b82f6', 'assigned' => '#6366f1', 'waiting_customer' => '#8b5cf6', 'closed' => '#64748b'
    ];
} else {
    $statusLabels = []; $statusColors = [];
    foreach($statusConfig as $k => $v) {
        $translated = __("ticket_status_" . $k);
        if ($translated !== "ticket_status_" . $k) {
            $statusLabels[$k] = $translated;
        } else {
            $statusLabels[$k] = $v['label'];
        }
        $statusColors[$k] = $v['color'];
    }
}

// Kuyruklar (Role 1 hariç sadece üye olduğu takımların kuyrukları gelir)
if ($current_user_role == 1) {
    $stmtQ = $pdo->prepare("SELECT id, name FROM queues WHERE status = 1 AND " . tenantWhere());
    $stmtQ->execute();
} else {
    $stmtQ = $pdo->prepare("SELECT id, name FROM queues WHERE status = 1 AND team_id IN (SELECT team_id FROM teams_users WHERE user_id = ?) AND " . tenantWhere());
    $stmtQ->execute([$current_user_id]);
}
$queues = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

$counts = [];
$baseWhere = [tenantWhere()];
$baseParams = [];
if ($current_user_role == 1) {
    // Admin (Role 1): Tüm biletler
} else {
    $baseWhere[] = "(creator_id = ? 
        OR customer_id = ? 
        OR customer_id IN (SELECT id FROM customers WHERE email COLLATE utf8mb4_general_ci = (SELECT mail COLLATE utf8mb4_general_ci FROM users WHERE id = ?))
        OR $personnelCol = ? 
        OR queue_id IN (SELECT id FROM queues WHERE team_id IN (SELECT team_id FROM teams_users WHERE user_id = ?)))";
    $baseParams[] = $current_user_id;
    $baseParams[] = $current_user_id;
    $baseParams[] = $current_user_id;
    $baseParams[] = $current_user_id;
    $baseParams[] = $current_user_id;
}
$baseWhereSQL = $baseWhere ? 'WHERE ' . implode(' AND ', $baseWhere) : '';

$stmtOpen = $pdo->prepare("SELECT COUNT(*) FROM tickets $baseWhereSQL " . ($baseWhereSQL ? "AND" : "WHERE") . " status NOT IN ('closed', 'resolved')");
$stmtOpen->execute($baseParams);
$counts['open'] = $stmtOpen->fetchColumn();

$stmtAssigned = $pdo->prepare("SELECT COUNT(*) FROM tickets $baseWhereSQL " . ($baseWhereSQL ? "AND" : "WHERE") . " status = 'assigned'");
$stmtAssigned->execute($baseParams);
$counts['assigned'] = $stmtAssigned->fetchColumn();

$stmtClosed = $pdo->prepare("SELECT COUNT(*) FROM tickets $baseWhereSQL " . ($baseWhereSQL ? "AND" : "WHERE") . " status IN ('closed', 'resolved')");
$stmtClosed->execute($baseParams);
$counts['closed_total'] = $stmtClosed->fetchColumn();

$stmtAll = $pdo->prepare("SELECT COUNT(*) FROM tickets $baseWhereSQL");
$stmtAll->execute($baseParams);
$counts['all'] = $stmtAll->fetchColumn();

function priorityBadge($p)
{
    return match ($p) {
        'critical' => '<span class="badge badge-danger"><i class="fas fa-exclamation-triangle mr-1"></i>' . __("critical") . '</span>',
        'high' => '<span class="badge badge-warning"><i class="fas fa-arrow-up mr-1"></i>' . __("high") . '</span>',
        'low' => '<span class="badge badge-secondary">' . __("low") . '</span>',
        default => '<span class="badge badge-info">' . __("normal") . '</span>'
    };
}
function statusBadge($s)
{
    return match ($s) {
        'open' => '<span class="badge badge-primary"><i class="fas fa-door-open mr-1"></i>' . (__("ticket_status_open") ?: 'Yeni / Açık') . '</span>',
        'assigned' => '<span class="badge badge-warning"><i class="fas fa-user-check mr-1"></i>' . (__("ticket_status_assigned") ?: 'Atandı') . '</span>',
        'pending' => '<span class="badge badge-secondary"><i class="fas fa-pause mr-1"></i>' . (__("ticket_status_pending") ?: 'Beklemede') . '</span>',
        'waiting_customer' => '<span class="badge badge-info"><i class="fas fa-user-clock mr-1"></i>' . (__("ticket_status_waiting_customer") ?: 'Müşteri Cevabı Bekleniyor') . '</span>',
        'resolved' => '<span class="badge badge-success"><i class="fas fa-check mr-1"></i>' . (__("ticket_status_resolved") ?: 'Çözüldü') . '</span>',
        'closed' => '<span class="badge badge-dark"><i class="fas fa-lock mr-1"></i>' . (__("ticket_status_closed") ?: 'Kapalı') . '</span>',
        default => '<span class="badge badge-light">' . htmlspecialchars(__($s)) . '</span>'
    };
}
function slaColor($t)
{
    if (!is_array($t)) return '';
    $status = $t['status'] ?? '';
    if (in_array($status, ['resolved', 'closed'])) {
        $end_date_str = !empty($t['closed_date']) ? $t['closed_date'] : (!empty($t['resolved_date']) ? $t['resolved_date'] : ($t['update_date'] ?? null));
        if (!$end_date_str || empty($t['sla_due_date'])) return '';
        $end_time = strtotime($end_date_str);
        $due_time = strtotime($t['sla_due_date']);
        if ($end_time > $due_time) {
            return 'table-danger';
        }
        return '';
    }
    $minutes = $t['sla_minutes_left'] ?? null;
    if ($minutes === null) return '';
    if ($minutes < 0) return 'table-danger';
    if ($minutes < 120) return 'table-warning';
    return '';
}
?>

<style>
    .ticket-table td {
        vertical-align: middle;
    }

    .ticket-row:hover {
        cursor: pointer;
    }

    .stat-card {
        border-radius: 10px;
        padding: 16px 20px;
        color: #fff;
    }

    .ticket-no-badge {
        font-family: monospace;
        background: #f0f0f0;
        color: #555;
        border-radius: 4px;
        padding: 2px 7px;
        font-size: 12px;
    }

    .filter-section {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 20px;
    }

    /* Dark Mode Improvements */
    body.dark-mode .filter-section {
        background: #2b3035 !important;
        border: 1px solid #444;
    }

    body.dark-mode .form-control {
        background: #343a40 !important;
        border-color: #495057 !important;
        color: #f8f9fa !important;
    }

    body.dark-mode .ticket-no-badge {
        background: #2b3035;
        border: 1px solid #495057;
        color: #e9ecef;
    }

    body.dark-mode .btn-outline-secondary {
        color: #ccc;
        border-color: #555;
    }

    body.dark-mode .btn-outline-secondary:hover {
        background: #555;
        color: #fff;
    }

    body.dark-mode .table {
        color: #f8f9fa;
        border-color: #444;
    }

    body.dark-mode .table-hover tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }

    body.dark-mode .table-warning,
    body.dark-mode .table-warning>td,
    body.dark-mode .table-warning>th {
        background-color: rgba(255, 193, 7, 0.15) !important;
        color: #e9ecef !important;
    }

    body.dark-mode .table-danger,
    body.dark-mode .table-danger>td,
    body.dark-mode .table-danger>th {
        background-color: rgba(220, 53, 69, 0.15) !important;
        color: #e9ecef !important;
    }

    body.dark-mode .table-hover .table-warning:hover,
    body.dark-mode .table-hover .table-warning:hover>td,
    body.dark-mode .table-hover .table-warning:hover>th {
        background-color: rgba(255, 193, 7, 0.25) !important;
        color: #e9ecef !important;
    }

    body.dark-mode .table-hover .table-danger:hover,
    body.dark-mode .table-hover .table-danger:hover>td,
    body.dark-mode .table-hover .table-danger:hover>th {
        background-color: rgba(220, 53, 69, 0.25) !important;
        color: #e9ecef !important;
    }

    body.dark-mode .card {
        background-color: #343a40;
        border-color: #4b545c;
    }

    body.dark-mode .card-header {
        border-bottom-color: #4b545c;
    }

    body.dark-mode .thead-light th {
        background-color: #2b3035;
        color: #ced4da;
        border-color: #495057;
    }

    /* Fixed Dark Mode Badge Visibility */
    body.dark-mode .badge-pill {
        color: #fff !important;
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    }
    
    body.dark-mode .badge-light {
        background: #334155 !important;
        color: #f1f5f9 !important;
        border: 1px solid #475569;
    }

    .badge-pill {
        padding: 5px 12px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 11px;
    }
</style>

<!-- İstatistik Kartları -->
<div class="row mb-3">
    <div class="col-6 col-md-3 mb-2">
        <div class="stat-card shadow-sm" style="background: linear-gradient(135deg,#667eea,#764ba2)">
            <div class="text-sm font-weight-bold opacity-75"><?= __("ticket_status_open") ?></div>
            <div class="h3 mb-0 font-weight-bold">
                <?php echo $counts['open'] ?? 0; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
        <div class="stat-card shadow-sm" style="background: linear-gradient(135deg,#f093fb,#f5576c)">
            <div class="text-sm font-weight-bold opacity-75"><?= __("ticket_status_assigned") ?></div>
            <div class="h3 mb-0 font-weight-bold">
                <?php echo $counts['assigned'] ?? 0; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
        <div class="stat-card shadow-sm" style="background: linear-gradient(135deg,#4facfe,#00f2fe)">
            <div class="text-sm font-weight-bold opacity-75"><?= __("ticket_status_closed") ?> /
                <?= __("ticket_status_resolved") ?>
            </div>
            <div class="h3 mb-0 font-weight-bold">
                <?php echo $counts['closed_total'] ?? 0; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-2 d-flex align-items-center justify-content-end">
        <a href="ticket-olustur" class="btn btn-success btn-block py-3 shadow-sm rounded-lg text-lg">
            <i class="fas fa-plus mr-1"></i><strong><?= __("create_new_ticket") ?></strong>
        </a>
    </div>
</div>

<!-- Filtre Bölümü -->
<div class="filter-section">
    <form method="GET" action="">
        <input type="hidden" name="route" value="biletler">
        <div class="row align-items-end">
            <div class="col-md-4 form-group mb-0">
                <label class="mb-1 font-weight-bold text-sm">🔍 <?= __("search") ?></label>
                <input type="text" name="search" class="form-control form-control-sm"
                    placeholder="<?= __("ticket_no_title_placeholder") ?>"
                    value="<?php echo htmlspecialchars($filterSearch); ?>">
            </div>
            <div class="col-md-2 form-group mb-0">
                <label class="mb-1 font-weight-bold text-sm"><?= __("status") ?></label>
                <select name="status" class="form-control form-control-sm">
                    <option value="all" <?php echo $filterStatus == 'all' ? 'selected' : ''; ?>><?= __("all") ?> (Tümü)</option>
                    <?php foreach ($statusLabels as $sv => $sl): ?>
                        <option value="<?php echo htmlspecialchars($sv); ?>" <?php echo $filterStatus == $sv ? 'selected' : ''; ?>><?php echo htmlspecialchars($sl); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 form-group mb-0">
                <label class="mb-1 font-weight-bold text-sm"><?= __("priority") ?></label>
                <select name="priority" class="form-control form-control-sm">
                    <option value=""><?= __("all") ?></option>
                    <?php
                    $prioArr = [
                        'critical' => __("critical"),
                        'high' => __("high"),
                        'normal' => __("normal"),
                        'low' => __("low")
                    ];
                    foreach ($prioArr as $val => $lab): ?>
                        <option value="<?php echo $val; ?>" <?php echo $filterPriority == $val ? 'selected' : ''; ?>>
                            <?php echo $lab; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 form-group mb-0">
                <label class="mb-1 font-weight-bold text-sm"><?= __("queue") ?></label>
                <select name="queue_id" class="form-control form-control-sm">
                    <option value=""><?= __("all") ?></option>
                    <?php foreach ($queues as $q): ?>
                        <option value="<?php echo $q['id']; ?>" <?php echo $filterQueue == $q['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($q['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 form-group mb-0">
                <button type="submit" class="btn btn-primary btn-sm btn-block"><i
                        class="fas fa-filter mr-1"></i><?= __("filter") ?></button>
                <a href="biletler" class="btn btn-outline-secondary btn-sm btn-block mt-1"><?= __("clear") ?></a>
            </div>
        </div>
    </form>
</div>

<!-- Tablo -->
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-list mr-1"></i><?= __("ticket_list") ?> <span
                class="badge badge-secondary">
                <?php echo count($tickets); ?>
            </span></h3>
        <div class="card-tools">
            <a href="anasayfa?route=kanban" class="btn btn-sm btn-outline-primary font-weight-bold"><i class="fas fa-columns mr-1"></i> <?= $isTr ? 'Kanban Pano' : 'Kanban Board' ?></a>
        </div>
      </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm ticket-table" id="ticketTable">
                <thead class="thead-light">
                    <tr>
                        <th width="30">#</th>
                        <th><?= t("title") ?></th>
                        <th><?= t("priority") ?></th>
                        <th><?= t("status") ?></th>
                        <th><?= t("customer") ?></th>
                        <th>İşlem Yapan Personel</th>
                        <th><?= t("queue") ?></th>
                        <th>SLA / <?= t("date") ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <div class="text-muted"><?= __("no_tickets") ?></div>
                                <a href="ticket-olustur" class="btn btn-sm btn-success mt-2"><i
                                        class="fas fa-plus mr-1"></i><?= __("create_first_ticket") ?></a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $idx => $t): ?>
                            <tr class="ticket-row <?php echo slaColor($t); ?>"
                                onclick="window.location='bilet-detay/<?php echo $t['id']; ?>'" style="cursor:pointer">
                                <td class="text-muted text-sm font-weight-bold" style="white-space:nowrap;">
                    <?php
                        $tno = !empty($t['ticket_no']) ? $t['ticket_no'] : ('EA-' . $t['id']);
                        echo htmlspecialchars($tno);
                    ?>
                </td>
                                <!-- Subject (Konu) -->
                                <td class="text-sm">
                                    <a href="bilet-detay/<?= $t['id'] ?>" class="text-dark font-weight-bold"
                                        style="text-decoration:none;">
                                        <?php echo htmlspecialchars($t['title']); ?>
                                    </a>
                                    <?php if (!empty($t['asset_name'])): ?>
                                        <div class="mt-1">
                                            <span class="badge badge-info px-2 py-1" style="font-size:10px; border-radius:10px;"><i class="fas fa-desktop mr-1"></i><?= htmlspecialchars($t['asset_name']) ?> <?= $t['asset_tag'] ? '(' . htmlspecialchars($t['asset_tag']) . ')' : '' ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Priority (Öncelik) -->
                                <td class="text-sm">
                                    <span class="badge-pill"
                                        style="border: 1px solid <?php echo $priorityColors[$t['priority']] ?? '#eee'; ?>; color: <?php echo $priorityColors[$t['priority']] ?? '#333'; ?>; background: transparent;">
                                        <?php echo $priorityLabels[$t['priority']] ?? $t['priority']; ?>
                                    </span>
                                </td>

                                <!-- Status (Durum) -->
                                <td class="text-sm">
                                    <span class="badge badge-pill"
                                        style="background: <?php echo $statusColors[$t['status']] ?? '#eee'; ?>; color: #fff;">
                                        <?php echo $statusLabels[$t['status']] ?? $t['status']; ?>
                                    </span>
                                </td>

                                <!-- Customer & Organization -->
                                <td class="text-sm">
                                    <?php 
                                        $displayCust = !empty($t['customer_name']) ? $t['customer_name'] : ($t['creator_name'] ?: '—');
                                        $displayOrg = !empty($t['organization_name']) ? $t['organization_name'] : '';
                                        $custId = (int)$t['customer_id'] ?: 0;
                                        $orgId = (int)$t['organization_id'] ?: 0;
                                    ?>
                                    <div class="d-flex flex-column">
                                        <?php if ($orgId > 0): ?>
                                            <a href="organizasyonlar?q=<?= urlencode($displayOrg) ?>" class="text-primary font-weight-bold" style="text-decoration:none; font-size: 13px;">
                                                <i class="fas fa-building mr-1"></i><?= htmlspecialchars($displayOrg) ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($custId > 0): ?>
                                            <a href="musteri-detay/<?= $custId ?>" class="text-muted" style="text-decoration:none; font-size: 11px;">
                                                <i class="fas fa-user-circle mr-1"></i><?= htmlspecialchars($displayCust) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 11px;"><?= htmlspecialchars($displayCust) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Agent (Atanan / Kilitli) -->
                                <td class="text-sm">
                                    <?php if (!empty($t['locked_by'])): ?>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-user-circle text-primary mr-2"></i>
                                            <span class="font-weight-bold"><?php echo htmlspecialchars($t['locked_by_name'] ?? $t['agent_name']); ?></span>
                                            <i class="fas fa-lock text-warning ml-2" style="font-size:11px;" title="<?= htmlspecialchars('Düzenleme Kilidi: ' . ($t['locked_by_name'] ?? '—')) ?>"></i>
                                        </div>
                                    <?php elseif (!empty($t['agent_name'])): ?>
                                        <div class="d-flex align-items-center text-secondary">
                                            <i class="fas fa-user-circle mr-2"></i>
                                            <span><?php echo htmlspecialchars($t['agent_name']); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small"><i><?= __("not_assigned") ?></i></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Queue (Kuyruk) -->
                                <td class="text-sm">
                                    <span class="badge badge-light"><?php echo htmlspecialchars($t['queue_name']); ?></span>
                                </td>

                                <!-- Date & SLA (Tarih) -->
                                <td class="text-sm">
                                    <div class="d-flex flex-column">
                                        <div class="mb-1">
                                            <?php if (!empty($t['sla_due_date'])):
                                                $isClosed = in_array($t['status'], ['resolved', 'closed']);
                                                if ($isClosed):
                                                    $end_date_str = !empty($t['closed_date']) ? $t['closed_date'] : (!empty($t['resolved_date']) ? $t['resolved_date'] : ($t['update_date'] ?? null));
                                                    $end_time = $end_date_str ? strtotime($end_date_str) : time();
                                                    $due_time = strtotime($t['sla_due_date']);
                                                    if ($end_time <= $due_time):
                                                        echo '<span class="badge badge-success" style="font-size:10px;"><i class="fas fa-check-circle mr-1"></i>' . ($isTr ? 'Süresinde Çözüldü' : 'Met SLA') . '</span>';
                                                    else:
                                                        echo '<span class="badge badge-danger" style="font-size:10px;"><i class="fas fa-clock mr-1"></i>' . __("breached") . '</span>';
                                                    endif;
                                                else:
                                                    $m = $t['sla_minutes_left'] ?? null;
                                                    if ($m !== null):
                                                        if ($m < 0)
                                                            echo '<span class="badge badge-danger" style="font-size:10px;"><i class="fas fa-clock mr-1"></i>' . __("breached") . '</span>';
                                                        elseif ($m < 60)
                                                            echo '<span class="text-warning font-weight-bold" style="font-size:11px;">' . $m . ' ' . __("min_short") . ' ' . __("left") . '</span>';
                                                        elseif ($m < 1440)
                                                            echo '<span class="text-info font-weight-bold" style="font-size:11px;">' . round($m / 60, 1) . ' ' . __("hours_short") . '</span>';
                                                        else
                                                            echo '<span class="text-muted" style="font-size:11px;">' . round($m / 1440, 1) . ' ' . __("days_short") . '</span>';
                                                    endif;
                                                endif;
                                            endif; ?>
                                        </div>
                                        <div class="text-muted" style="font-size: 11px; opacity: 0.8;">
                                            <?php
                                            $create_time = strtotime($t['create_date']);
                                            if (in_array($t['status'], ['resolved', 'closed'])) {
                                                $end_date_str = !empty($t['closed_date']) ? $t['closed_date'] : (!empty($t['resolved_date']) ? $t['resolved_date'] : $t['update_date']);
                                                $end_time = $end_date_str ? strtotime($end_date_str) : time();
                                                $diff = max(0, $end_time - $create_time);
                                                if ($diff < 60) $age_str = $diff . ' ' . __("seconds_ago_closed");
                                                elseif ($diff < 3600) $age_str = floor($diff / 60) . ' ' . __("minutes_ago_closed");
                                                elseif ($diff < 86400) $age_str = floor($diff / 3600) . ' ' . __("hours_ago_closed");
                                                else $age_str = floor($diff / 86400) . ' ' . __("days_ago_closed");
                                            } else {
                                                $now = time();
                                                $diff = max(0, $now - $create_time);
                                                if ($diff < 60) $age_str = $diff . ' ' . __("seconds_ago");
                                                elseif ($diff < 3600) $age_str = floor($diff / 60) . ' ' . __("minutes_ago");
                                                elseif ($diff < 86400) $age_str = floor($diff / 3600) . ' ' . __("hours_ago");
                                                else $age_str = floor($diff / 86400) . ' ' . __("days_ago");
                                            }
                                            echo '<span>' . date('d.m.Y H:i', $create_time) . '</span>';
                                            echo '<span class="ml-1 opacity-75">(' . $age_str . ')</span>';
                                            ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
