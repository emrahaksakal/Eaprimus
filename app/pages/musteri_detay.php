<?php
// pages/musteri_detay.php

require_once __DIR__ . '/../includes/session.php';
requireLogin();

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

$current_user_role = $_SESSION['role'];

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

if ($current_user_role != 1) {
    echo '<div class="alert alert-danger m-3">' . __('no_permission') . '</div>';
    return;
}

if (!isset($_GET['id'])) {
    echo '<div class="alert alert-warning m-3">' . __('invalid_request') . '</div>';
    return;
}

$cid = (int) $_GET['id'];

// Fetch customer
$stmt = $pdo->prepare("SELECT c.*, o.name as org_name FROM customers c LEFT JOIN organizations o ON o.id = c.organization_id WHERE c.id = ?");
$stmt->execute([$cid]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
    echo '<div class="alert alert-danger m-3">' . __('customer_not_found') . '</div>';
    return;
}

// Fetch tickets (Direct customer_id match OR creator email/name match)
$tstmt = $pdo->prepare("SELECT t.id, t.ticket_no, t.title, t.status, t.create_date, u.fullname as assigned_name
                        FROM tickets t
                        LEFT JOIN users u ON u.id = t.$personnelCol
                        LEFT JOIN users cu ON t.creator_id = cu.id
                        WHERE t.customer_id = :cid
                        OR cu.mail COLLATE utf8mb4_general_ci = (SELECT email COLLATE utf8mb4_general_ci FROM customers WHERE id = :cid2)
                        OR cu.fullname COLLATE utf8mb4_general_ci = (SELECT name COLLATE utf8mb4_general_ci FROM customers WHERE id = :cid3)
                        ORDER BY t.create_date DESC");
$tstmt->execute(['cid' => $cid, 'cid2' => $cid, 'cid3' => $cid]);
$tickets = $tstmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch custom fields
$fstmt = $pdo->prepare("SELECT cf.label, cf.field_type, cfv.value
                        FROM customer_fields cf
                        LEFT JOIN customer_field_values cfv ON cf.id = cfv.field_id AND cfv.customer_id = ?
                        ORDER BY cf.sort_order ASC");
$fstmt->execute([$cid]);
$customFields = $fstmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.profile-header {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    color: white;
    padding: 40px 30px;
    border-radius: 16px;
    margin-bottom: 30px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.profile-header::after {
    content: '';
    position: absolute;
    right: -50px;
    bottom: -50px;
    width: 250px;
    height: 250px;
    background: rgba(255,255,255,0.03);
    border-radius: 50%;
}
.profile-info-wrapper {
    display: flex;
    align-items: center;
    gap: 20px;
    position: relative;
    z-index: 1;
}
.profile-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    border: 3px solid rgba(255,255,255,0.2);
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    background: white;
}
.profile-title {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
}
.profile-subtitle {
    margin: 5px 0 0 0;
    font-size: 14px;
    color: #cbd5e1;
}
.info-card {
    border-radius: 16px;
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    overflow: hidden;
}
.info-card .card-header {
    background: transparent;
    border-bottom: 1px solid #f1f5f9;
    padding: 20px 24px;
}
.info-card .card-title {
    font-weight: 700;
    color: #334155;
    margin: 0;
    font-size: 16px;
}
.info-item {
    display: flex;
    align-items: flex-start;
    padding: 16px 24px;
    border-bottom: 1px dashed #f1f5f9;
}
.info-item:last-child {
    border-bottom: none;
}
.info-icon {
    width: 40px;
    height: 40px;
    background: #f8fafc;
    color: #3b82f6;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 16px;
    font-size: 18px;
    flex-shrink: 0;
}
.info-content h6 {
    margin: 0 0 4px 0;
    font-size: 13px;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.info-content p {
    margin: 0;
    font-size: 15px;
    color: #1e293b;
    font-weight: 500;
}
.ticket-table thead th {
    border-top: none;
    background: #f8fafc;
    color: #475569;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 12px;
    letter-spacing: 0.5px;
    padding: 15px 20px;
}
.ticket-table tbody td {
    vertical-align: middle;
    padding: 15px 20px;
    color: #334155;
    font-weight: 500;
    border-bottom: 1px solid #f1f5f9;
}
.ticket-table tbody tr:hover {
    background-color: #f8fafc;
}
.modern-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 12px;
    display: inline-block;
}
.status-open { background: #dbeafe; color: #1e40af; }
.status-resolved { background: #d1fae5; color: #065f46; }
.status-closed { background: #f3f4f6; color: #374151; }

/* Dark mode overrides */
body.dark-mode .info-card { background: #1e293b; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
body.dark-mode .info-card .card-header { border-bottom-color: #334155; }
body.dark-mode .info-card .card-title { color: #f1f5f9; }
body.dark-mode .info-item { border-bottom-color: #334155; }
body.dark-mode .info-icon { background: #0f172a; color: #60a5fa; }
body.dark-mode .info-content h6 { color: #94a3b8; }
body.dark-mode .info-content p { color: #f1f5f9; }
body.dark-mode .ticket-table thead th { background: #0f172a; color: #94a3b8; border-bottom: 1px solid #334155; }
body.dark-mode .ticket-table tbody td { color: #e2e8f0; border-bottom: 1px solid #334155; }
body.dark-mode .ticket-table tbody tr:hover { background-color: #0f172a; }
</style>

<div class="profile-header">
    <div class="profile-info-wrapper">
        <?php
            $avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($customer['name'] ?: ($customer['email'] ?? 'C')) . '&background=3b82f6&color=fff&size=128&bold=true';
            if (!empty($customer['avatar']) && file_exists(__DIR__ . '/../../public/uploads/avatars/' . $customer['avatar'])) {
                // Assuming base path is / or standard server structure where public is document root, but just in case, use relative from root:
                $avatarUrl = '/public/uploads/avatars/' . $customer['avatar'];
            }
        ?>
        <img src="<?= $avatarUrl ?>" alt="Avatar" class="profile-avatar" style="object-fit:cover;">
        <div>
            <h1 class="profile-title"><?= htmlspecialchars($customer['name'] ?: ($customer['email'] ?? __('customer'))) ?></h1>
            <p class="profile-subtitle">
                <i class="fas fa-building mr-1"></i> <?= htmlspecialchars($customer['org_name'] ?: ($customer['company'] ?: '-')) ?>
            </p>
        </div>
    </div>
    <div style="position: relative; z-index: 1;">
        <a href="musteri-duzenle/<?= $customer['id'] ?>" class="btn btn-light" style="font-weight: 600; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <i class="fas fa-edit mr-2 text-primary"></i> <?= __('edit') ?>
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <!-- Customer Details Card -->
        <div class="card info-card mb-4">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-address-card mr-2 text-primary"></i> <?= __('customer_info') ?? 'Müşteri Bilgileri' ?></h3>
            </div>
            <div class="card-body p-0">
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-envelope"></i></div>
                    <div class="info-content">
                        <h6><?= __('email') ?></h6>
                        <p><a href="mailto:<?= htmlspecialchars($customer['email']) ?>" style="color:inherit; text-decoration:none;"><?= htmlspecialchars($customer['email']) ?></a></p>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-phone-alt"></i></div>
                    <div class="info-content">
                        <h6><?= __('phone') ?></h6>
                        <p><?= htmlspecialchars($customer['phone'] ?: '-') ?></p>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-sticky-note"></i></div>
                    <div class="info-content">
                        <h6><?= __('notes') ?></h6>
                        <p style="font-size: 14px; font-weight: 400; line-height: 1.5;"><?= nl2br(htmlspecialchars($customer['notes'] ?: '-')) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Custom Fields CRM Card -->
        <div class="card info-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-briefcase mr-2 text-success"></i> <?= __('crm_additional_info') ?></h3>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($customFields)): ?>
                    <?php foreach ($customFields as $cf): ?>
                        <?php if(!empty($cf['value'])): ?>
                        <div class="info-item">
                            <div class="info-icon" style="color: #10b981; background: #ecfdf5;"><i class="fas fa-info-circle"></i></div>
                            <div class="info-content">
                                <h6><?= htmlspecialchars($cf['label']) ?></h6>
                                <p>
                                    <?php if ($cf['field_type'] == 'url'): ?>
                                        <a href="<?= htmlspecialchars((strpos($cf['value'], 'http') !== 0 ? 'http://' : '') . $cf['value']) ?>" target="_blank" class="text-success"><?= htmlspecialchars($cf['value']) ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($cf['value'] ?: '-') ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="p-4 text-center">
                        <i class="fas fa-box-open fa-3x mb-3" style="color: #cbd5e1;"></i>
                        <p class="text-muted m-0" style="font-weight: 500;"><small><?= __('no_custom_fields_found') ?></small></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <!-- Ticket History Card -->
        <div class="card info-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title"><i class="fas fa-ticket-alt mr-2 text-warning"></i> <?= __('ticket_history') ?></h3>
                <span class="badge badge-warning" style="border-radius: 12px; padding: 6px 12px;"><?= count($tickets) ?> Bilet</span>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table ticket-table m-0">
                    <thead>
                        <tr>
                            <th><?= __('ticket_no') ?></th>
                            <th><?= __('title') ?></th>
                            <th><?= __('status') ?></th>
                            <th><?= __('ticket_processed_by') ?></th>
                            <th><?= __('created_at') ?></th>
                            <th class="text-right"><?= __('action') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tickets)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3" style="color:#cbd5e1;"></i>
                                        <p style="font-weight:500; font-size:15px;"><?= __('no_tickets_found') ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tickets as $t): 
                                $statusClass = 'status-open';
                                if ($t['status'] === 'resolved') $statusClass = 'status-resolved';
                                if ($t['status'] === 'closed') $statusClass = 'status-closed';
                            ?>
                                <tr>
                                    <td><strong><a href="bilet-detay/<?= $t['id'] ?>" style="color:inherit; text-decoration:none;"><?= htmlspecialchars($t['ticket_no']) ?></a></strong></td>
                                    <td><?= htmlspecialchars($t['title']) ?></td>
                                    <td><span class="modern-badge <?= $statusClass ?>"><?= htmlspecialchars(__($t['status'])) ?></span></td>
                                    <td>
                                        <?php if($t['assigned_name']): ?>
                                            <div style="display:flex; align-items:center;">
                                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($t['assigned_name']) ?>&background=f1f5f9&color=475569&size=24&bold=true" class="rounded-circle mr-2" alt="Avatar">
                                                <span><?= htmlspecialchars($t['assigned_name']) ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="text-muted" style="font-size: 13px;">
                                            <i class="far fa-clock mr-1"></i>
                                            <?php
                                            $time = strtotime($t['create_date']);
                                            $day = date('d', $time);
                                            $month = date('n', $time);
                                            $year = date('Y', $time);
                                            $timeStr = date('H:i', $time);
                                            $months_tr = [1=>'Oca', 2=>'Şub', 3=>'Mar', 4=>'Nis', 5=>'May', 6=>'Haz', 7=>'Tem', 8=>'Ağu', 9=>'Eyl', 10=>'Eki', 11=>'Kas', 12=>'Ara'];
                                            $months_en = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'May', 6=>'Jun', 7=>'Jul', 8=>'Aug', 9=>'Sep', 10=>'Oct', 11=>'Nov', 12=>'Dec'];
                                            $monthName = ($current_lang === 'tr') ? $months_tr[$month] : $months_en[$month];
                                            echo "$day $monthName $year $timeStr";
                                            ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <a href="bilet-detay/<?= $t['id'] ?>" class="btn btn-sm btn-light" style="border-radius:8px; font-weight:600; color:#3b82f6;">
                                            <?= __('view') ?> <i class="fas fa-arrow-right ml-1" style="font-size:11px;"></i>
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
