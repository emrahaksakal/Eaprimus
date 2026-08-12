<?php
require_once __DIR__ . '/../../app/includes/session.php';
requireLogin();
require_once __DIR__ . '/../../app/config/db.php';
$pdo = db();

header('Content-Type: application/json');

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$status = isset($_POST['status']) ? trim($_POST['status']) : '';
$assigneeId = isset($_POST['assignee_id']) ? intval($_POST['assignee_id']) : 0;
$customerMessage = isset($_POST['customer_message']) ? trim($_POST['customer_message']) : '';

// Resolve personnel column dynamically
$personnelCol = 'assigned_to';
try {
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmtCol = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'personnel_id'");
    $stmtCol->execute([$dbName]);
    $hasPersonnel = (int) $stmtCol->fetchColumn() > 0;
    $personnelCol = $hasPersonnel ? 'personnel_id' : 'assigned_to';
} catch (Throwable $e) {}

// Load statuses dynamically from database
$stEntries = $pdo->query("SELECT * FROM ticket_statuses ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$valid_statuses = [];
$statusLabels = [];

if (!empty($stEntries)) {
    foreach ($stEntries as $st) {
        $key = $st['id_name'];
        $valid_statuses[] = $key;
        $label = __("ticket_status_" . $key);
        if ($label === "ticket_status_" . $key) {
            $label = $st['label'];
        }
        $statusLabels[$key] = $label;
    }
} else {
    $valid_statuses = ['open', 'assigned', 'pending', 'waiting_customer', 'resolved', 'closed'];
    $statusLabels = [
        'open' => ($_SESSION['lang'] ?? 'tr') === 'tr' ? 'Açık' : 'Open',
        'assigned' => ($_SESSION['lang'] ?? 'tr') === 'tr' ? 'Atandı' : 'Assigned',
        'pending' => ($_SESSION['lang'] ?? 'tr') === 'tr' ? 'Beklemede' : 'Pending',
        'waiting_customer' => ($_SESSION['lang'] ?? 'tr') === 'tr' ? 'Müşteri Cevabı Bekleniyor' : 'Waiting on Customer',
        'resolved' => ($_SESSION['lang'] ?? 'tr') === 'tr' ? 'Çözüldü' : 'Resolved',
        'closed' => ($_SESSION['lang'] ?? 'tr') === 'tr' ? 'Kapalı' : 'Closed'
    ];
}

if ($id > 0 && in_array($status, $valid_statuses)) {
    // Get old status to log the transition details
    $stmtOld = $pdo->prepare("SELECT status, ticket_no FROM tickets WHERE id = ?");
    $stmtOld->execute([$id]);
    $tOld = $stmtOld->fetch(PDO::FETCH_ASSOC);
    $oldStatus = $tOld['status'] ?? 'open';
    $ticketNo = $tOld['ticket_no'] ?? '';
    
    // Check if status actually changed (and assignee didn't change)
    if ($oldStatus === $status && !$assigneeId) {
        echo json_encode(['success' => true]);
        exit;
    }

    $res = false;
    
    // Update logic based on closed/resolved/assigned states
    if ($status === 'closed') {
        $stmt = $pdo->prepare("UPDATE tickets SET status = ?, closed_date = NOW(), closed_by = ?, locked_by = ?, locked_at = NOW() WHERE id = ?");
        $res = $stmt->execute([$status, $_SESSION['user_id'], $_SESSION['user_id'], $id]);
    } elseif ($status === 'resolved') {
        $stmt = $pdo->prepare("UPDATE tickets SET status = ?, resolved_date = NOW(), locked_by = ?, locked_at = NOW() WHERE id = ?");
        $res = $stmt->execute([$status, $_SESSION['user_id'], $id]);
    } elseif ($status === 'assigned' && $assigneeId > 0) {
        $stmt = $pdo->prepare("UPDATE tickets SET status = ?, {$personnelCol} = ?, assigned_by = ?, closed_date = NULL, closed_by = NULL, resolved_date = NULL WHERE id = ?");
        $res = $stmt->execute([$status, $assigneeId, $_SESSION['user_id'], $id]);
    } elseif ($status === 'open') {
        $stmt = $pdo->prepare("UPDATE tickets SET status = ?, {$personnelCol} = NULL, assigned_by = NULL, closed_date = NULL, closed_by = NULL, resolved_date = NULL WHERE id = ?");
        $res = $stmt->execute([$status, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE tickets SET status = ?, closed_date = NULL, closed_by = NULL, resolved_date = NULL WHERE id = ?");
        $res = $stmt->execute([$status, $id]);
    }
    
    if ($res) {
        $oldStatusLabel = $statusLabels[$oldStatus] ?? $oldStatus;
        $newStatusLabel = $statusLabels[$status] ?? $status;
        
        $assigneeName = '';
        if ($status === 'assigned' && $assigneeId > 0) {
            $assigneeName = $pdo->query("SELECT fullname FROM users WHERE id = $assigneeId")->fetchColumn();
        }
        
        $logMsg = ($_SESSION['lang'] ?? 'tr') === 'tr' ? 
            "Bilet durumu Kanban panosundan '{$oldStatusLabel}' durumundan '{$newStatusLabel}' durumuna güncellendi." : 
            "Ticket status updated from '{$oldStatusLabel}' to '{$newStatusLabel}' from Kanban board.";
            
        if (!empty($assigneeName)) {
            $logMsg .= ($_SESSION['lang'] ?? 'tr') === 'tr' ?
                " Bilet {$assigneeName} kullanıcısına atandı." :
                " Ticket assigned to {$assigneeName}.";
        }
        
        $logStmt = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_private, created_at) VALUES (?, ?, ?, 1, NOW())");
        $logStmt->execute([$id, $_SESSION['user_id'], $logMsg]);

        if (!empty($customerMessage)) {
            $msgStmt = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_private, created_at) VALUES (?, ?, ?, 0, NOW())");
            $msgStmt->execute([$id, $_SESSION['user_id'], $customerMessage]);

            // Fetch ticket details for email notification
            $stmtTicket = $pdo->prepare("SELECT t.ticket_no, t.title, 
                                                c.email as customer_email, c.name as customer_name, 
                                                u.mail as creator_mail, u.fullname as creator_name 
                                         FROM tickets t 
                                         LEFT JOIN users u ON t.creator_id = u.id 
                                         LEFT JOIN customers c ON t.customer_id = c.id
                                         WHERE t.id = ?");
            $stmtTicket->execute([$id]);
            $ticketInfo = $stmtTicket->fetch(PDO::FETCH_ASSOC);

            if ($ticketInfo) {
                $customerMail = !empty($ticketInfo['customer_email']) ? $ticketInfo['customer_email'] : (!empty($ticketInfo['creator_mail']) ? $ticketInfo['creator_mail'] : '');
                $customerName = !empty($ticketInfo['customer_name']) ? $ticketInfo['customer_name'] : (!empty($ticketInfo['creator_name']) ? $ticketInfo['creator_name'] : 'Müşteri');
                
                if (!empty($customerMail) && file_exists(__DIR__ . '/../../app/includes/mailer.php')) {
                    require_once __DIR__ . '/../../app/includes/mailer.php';
                    
                    // Fetch site settings
                    $settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
                    $settings = $settings ?: [];
                    $siteUrl = rtrim($settings['site_url'] ?? 'http://localhost', '/');
                    $base_url = $siteUrl . '/';
                    
                    $langQuery = $settings['mail_default_lang'] ?? 'tr';
                    $lang = in_array(trim($langQuery), ['tr', 'en']) ? trim($langQuery) : 'tr';
                    
                    $tKey = in_array(strtolower($status), ['resolved', 'closed']) ? 'resolved' : 'reply_cust';
                    if (($settings["mail_{$tKey}_active"] ?? '1') == '1') {
                        $mailVars = [
                            'ticket_no'     => $ticketInfo['ticket_no'],
                            'subject'       => $ticketInfo['title'],
                            'customer_name' => $customerName,
                            'agent_name'    => $_SESSION['fullname'] ?? 'Destek Uzmanı',
                            'message'       => $customerMessage,
                            'link'          => $base_url . "bilet-detay/" . $id
                        ];
                        
                        try {
                            sendTemplatedMail($customerMail, $customerName, $tKey, $mailVars, '', $lang);
                        } catch (\Throwable $mailEx) {
                            @file_put_contents(
                                __DIR__ . '/../../app/logs/mail_errors.log',
                                date('Y-m-d H:i:s') . " Kanban Reply Customer Mail Error [{$customerMail}]: " . $mailEx->getMessage() . "\n",
                                FILE_APPEND
                            );
                        }
                    }
                }
            }
        }
        
        // Also call logger utility
        if (file_exists(__DIR__ . '/../../app/includes/logger.php')) {
            require_once __DIR__ . '/../../app/includes/logger.php';
            $logAction = mb_strtoupper($status, 'UTF-8');
            ticketLogAl($pdo, $_SESSION['user_id'], $logAction, $ticketNo, $logMsg);
        }
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database update failed']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
}
