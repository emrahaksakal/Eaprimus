<?php
require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/logger.php";
require_once __DIR__ . "/../includes/notification_helper.php";
requireLogin();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Database migration / backfill for customer_id in replies (run dynamically)
try {
    // Step 1: Set customer_id for customer replies (role = 2 or empty/unregistered)
    $pdo->exec("UPDATE ticket_replies r 
                JOIN tickets t ON r.ticket_id = t.id 
                LEFT JOIN users u ON r.user_id = u.id
                SET r.customer_id = t.customer_id 
                WHERE (u.role = 2 OR u.id IS NULL) AND (r.customer_id IS NULL OR r.customer_id = 0)");
                
    // Step 2: Clear customer_id for staff replies (role = 1 or 3)
    $pdo->exec("UPDATE ticket_replies r 
                JOIN users u ON r.user_id = u.id
                SET r.customer_id = NULL 
                WHERE u.role IN (1, 3) AND r.customer_id IS NOT NULL");
} catch (Exception $e) {}

$isTr = ($_SESSION['lang'] === 'tr');

// Güvenli HTML render fonksiyonu
function sanitizeHtml($html)
{
    // Dangerous protokoller kaldır
    $html = preg_replace('#href\s*=\s*["\']?\s*javascript:#i', 'href="#"', $html);
    $html = preg_replace('#src\s*=\s*["\']?\s*javascript:#i', 'src=""', $html);
    $html = preg_replace('#src\s*=\s*["\']?\s*data:text/html#i', 'src=""', $html);
    return $html;
}

function fixImagePaths($html, $baseUrl)
{
    if (empty($html)) return $html;
    $baseUrl = rtrim($baseUrl, '/') . '/';
    // Match src="public/uploads/tickets/..." or src="/public/uploads/tickets/..."
    $html = preg_replace_callback('/src=["\']\/?public\/uploads\/tickets\/([^"\'\s>]+)["\']/i', function($m) use ($baseUrl) {
        return 'src="' . $baseUrl . 'public/uploads/tickets/' . $m[1] . '"';
    }, $html);
    // Match src="uploads/tickets/..." or src="/uploads/tickets/..."
    $html = preg_replace_callback('/src=["\']\/?uploads\/tickets\/([^"\'\s>]+)["\']/i', function($m) use ($baseUrl) {
        return 'src="' . $baseUrl . 'public/uploads/tickets/' . $m[1] . '"';
    }, $html);
    return $html;
}

$pdo = db();
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? 2;

// ticket_ratings tablosunu otomatik oluştur
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

$ticketId = intval($_GET['ticket_id'] ?? 0);
if (!$ticketId) {
    header("Location: " . $base_url . "anasayfa");
    exit;
}

// Bilet Çek
$stmtT = $pdo->prepare("
    SELECT t.*,
        u.fullname AS creator_name,
        u.mail AS creator_mail,
        a.fullname AS agent_name,
        q.name AS queue_name,
        q.team_id,
        ast.name AS asset_name,
        ast.ip_address AS asset_ip,
        c.id AS customer_id,
        c.name AS customer_name,
        c.email AS customer_email,
        (SELECT id FROM customers WHERE email COLLATE utf8mb4_general_ci = u.mail COLLATE utf8mb4_general_ci OR name COLLATE utf8mb4_general_ci = u.fullname COLLATE utf8mb4_general_ci LIMIT 1) as linked_customer_id,
        lu.fullname AS locked_by_name,
        t.locked_by,
        t.locked_at,
        o.name AS organization_name,
        o.id AS organization_id
    FROM tickets t
    LEFT JOIN users u ON t.creator_id = u.id
    LEFT JOIN users a ON t.{$personnelCol} = a.id
    LEFT JOIN queues q ON t.queue_id = q.id
    LEFT JOIN assets ast ON t.asset_id = ast.id
    LEFT JOIN customers c ON t.customer_id = c.id
    LEFT JOIN users lu ON t.locked_by = lu.id
    LEFT JOIN organizations o ON (t.organization_id = o.id OR c.organization_id = o.id)
    WHERE t.id = ?
");
$stmtT->execute([$ticketId]);
$ticket = $stmtT->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    header("Location: " . $base_url . "anasayfa");
    exit;
}

// Bilet Görünürlük Yetki Kontrolü (Role 1 hariç tüm kullanıcılar sadece kendi/takımının biletini görebilir)
if ($current_user_role != 1) {
    $isCreator = ($ticket['creator_id'] == $current_user_id);
    $isAssigned = (!empty($ticket[$personnelCol]) && $ticket[$personnelCol] == $current_user_id);
    $isCustomer = (!empty($ticket['customer_id']) && $ticket['customer_id'] == $current_user_id) || (!empty($ticket['linked_customer_id']) && $ticket['linked_customer_id'] == $current_user_id);
    
    $isTeamMember = false;
    if (!empty($ticket['team_id'])) {
        $stmtTeamCheck = $pdo->prepare("SELECT COUNT(*) FROM teams_users WHERE team_id = ? AND user_id = ?");
        $stmtTeamCheck->execute([(int)$ticket['team_id'], $current_user_id]);
        $isTeamMember = ((int)$stmtTeamCheck->fetchColumn() > 0);
    } elseif (!empty($ticket['queue_id'])) {
        $stmtQueueTeamCheck = $pdo->prepare("SELECT COUNT(*) FROM queues q JOIN teams_users tu ON q.team_id = tu.team_id WHERE q.id = ? AND tu.user_id = ?");
        $stmtQueueTeamCheck->execute([(int)$ticket['queue_id'], $current_user_id]);
        $isTeamMember = ((int)$stmtQueueTeamCheck->fetchColumn() > 0);
    }
    
    if (!$isCreator && !$isAssigned && !$isCustomer && !$isTeamMember) {
        $_SESSION['mesaj'] = "⚠️ " . ($isTr ? "Yetkisiz Erişim: Bu bileti görüntüleme yetkiniz bulunmamaktadır." : "Unauthorized Access: You do not have permission to view this ticket.");
        header("Location: " . $base_url . "biletler");
        exit;
    }
}

// Bilet Değerlendirmesini Çek
$ticketRating = null;
try {
    $stmtR = $pdo->prepare("SELECT * FROM ticket_ratings WHERE ticket_id = ?");
    $stmtR->execute([$ticketId]);
    $ticketRating = $stmtR->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Son yanıt veren kişi ve tarih
$stmtLR = $pdo->prepare("SELECT u.fullname, r.created_at FROM ticket_replies r JOIN users u ON r.user_id = u.id WHERE r.ticket_id = ? AND r.is_private = 0 ORDER BY r.created_at DESC LIMIT 1");
$stmtLR->execute([$ticketId]);
$lastReply = $stmtLR->fetch(PDO::FETCH_ASSOC);

// Bilet'e ilk yanıt veren kişiyi bul (Kilit açma yetkisi için)
$first_responder_id = 0;
$stmtFR = $pdo->prepare("SELECT user_id FROM ticket_replies WHERE ticket_id = ? AND is_private = 0 ORDER BY created_at ASC LIMIT 1");
$stmtFR->execute([$ticketId]);
$first_responder_id = (int) $stmtFR->fetchColumn();

$ticketPersonnelId = (int) ($ticket[$personnelCol] ?? 0);

// Erişim Kontrolü
$can_view_all_biletler = hasPermission('biletler_view_all') || $current_user_role == 1;
$can_view_own_biletler = hasPermission('biletler_view_own') && !$can_view_all_biletler;

if (!$can_view_all_biletler && !$can_view_own_biletler && hasPermission('biletler')) {
    if ($current_user_role == 2 || $current_user_role == 3) {
        $can_view_own_biletler = true;
    } else {
        $can_view_all_biletler = true;
    }
}

$access_granted = false;
if ($can_view_all_biletler) {
    $access_granted = true;
} elseif ($can_view_own_biletler) {
    $stmtCheck = $pdo->prepare("SELECT 1 FROM queues q JOIN teams_users tu ON q.team_id = tu.team_id WHERE q.id = ? AND tu.user_id = ?");
    $stmtCheck->execute([$ticket['queue_id'], $current_user_id]);
    $inTeamQueue = $stmtCheck->fetchColumn();

    if ($inTeamQueue || $ticket['creator_id'] == $current_user_id || $ticketPersonnelId == $current_user_id) {
        $access_granted = true;
    }
}

if (!$access_granted) {
    $_SESSION['mesaj'] = $isTr ? "⚠️ Yetkisiz Erişim: Bu bilet detayını görme yetkiniz bulunmamaktadır." : "⚠️ Unauthorized Access: You do not have permission to view this ticket's details.";
    header("Location: " . $base_url . "anasayfa");
    exit;
}

// Mark ticket as read by agent when viewed
// If assigned, ONLY the assignee should clear the notification. If unassigned, any agent can clear it.
// EXCEPTION: The agent viewing must not be the one who just replied, otherwise they instantly clear their own notification meant for others.
$stmtLastReplier = $pdo->prepare("SELECT user_id FROM ticket_replies WHERE ticket_id = ? AND reply_type != 'system' ORDER BY id DESC LIMIT 1");
$stmtLastReplier->execute([$ticketId]);
$lastReplierId = $stmtLastReplier->fetchColumn();
if (!$lastReplierId) {
    $lastReplierId = $ticket['creator_id'];
}
$isNotLastReplier = ((int)$lastReplierId !== (int)$current_user_id);

$stmtMaxReply = $pdo->prepare("SELECT MAX(id) FROM ticket_replies WHERE ticket_id = ? AND reply_type != 'system'");
$stmtMaxReply->execute([$ticketId]);
$maxReplyId = $stmtMaxReply->fetchColumn();

$ratedVal = null;
try {
    $stR = $pdo->prepare("SELECT rating FROM ticket_ratings WHERE ticket_id = ?");
    $stR->execute([$ticketId]);
    $ratedVal = $stR->fetchColumn();
} catch (Exception $e) {}

$readToken = ($maxReplyId ? (int)$maxReplyId : 'new') . '_' . ($ticket['status'] ?? 'open') . '_' . ($ratedVal ?: '0');
if (!isset($_SESSION['read_ticket_replies'])) {
    $_SESSION['read_ticket_replies'] = [];
}
$_SESSION['read_ticket_replies'][$ticketId] = $readToken;
setcookie('read_ticket_reply_' . $current_user_id . '_' . $ticketId, $readToken, time() + (86400 * 30), '/');

$pdo->prepare("UPDATE tickets SET agent_read = 1, unread_replies_count = 0 WHERE id = ?")->execute([$ticketId]);
$ticket['agent_read'] = 1;
$ticket['unread_replies_count'] = 0;

// Ticket ilk görüntülenince "first_response_date" kaydet
if ($ticketPersonnelId == $current_user_id && !$ticket['first_response_date']) {
    $pdo->prepare("UPDATE tickets SET first_response_date = NOW() WHERE id = ?")->execute([$ticketId]);
}

// OTOMATİK DÜZENLEME KİLİDİ: Eğer bilet kilitli değilse veya kilidi süresi dolmuşsa (28800s) ve inceleyen kullanıcı teknik personel (Role 1 veya 3) ise kilidi otomatik üzerine al
$isLockExpiredOnLoad = empty($ticket['locked_at']) || (time() - strtotime($ticket['locked_at'])) >= 28800;
$isLockHeldByOtherOnLoad = !empty($ticket['locked_by']) && (int)$ticket['locked_by'] !== (int)$current_user_id && !$isLockExpiredOnLoad;
$isClosedOrResolvedOnLoad = in_array($ticket['status'], ['closed', 'resolved']);

if (in_array((int)$current_user_role, [1, 3]) && !$isClosedOrResolvedOnLoad && !$isLockHeldByOtherOnLoad) {
    $pdo->prepare("UPDATE tickets SET locked_by = ?, locked_at = NOW() WHERE id = ?")->execute([$current_user_id, $ticketId]);
    $ticket['locked_by'] = $current_user_id;
    $ticket['locked_by_name'] = $_SESSION['fullname'] ?? 'Personel';
    $ticket['locked_at'] = date('Y-m-d H:i:s');
}

// POST: Yeni Yanıt veya Durum Güncelleme
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_csrf_token();
    $lang = $_SESSION['lang'] ?? 'tr';
    if ($lang !== 'en') $lang = 'tr';
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'rate_ticket') {
            $ratingVal = intval($_POST['rating'] ?? 0);
            $ratingComment = trim($_POST['comment'] ?? '');
            
            if ($ratingVal < 1 || $ratingVal > 5) {
                throw new Exception($isTr ? "Geçersiz değerlendirme puanı." : "Invalid rating value.");
            }
            
            // Check if ticket is resolved/closed
            if (!in_array($ticket['status'], ['resolved', 'closed'])) {
                throw new Exception($isTr ? "Değerlendirme yapabilmek için biletin çözülmüş veya kapatılmış olması gerekir." : "Ticket must be resolved or closed to submit feedback.");
            }

            // Check if user is authorized to rate (must be role 2 and either creator or customer)
            if ($current_user_role != 2 || ((int)$ticket['creator_id'] !== (int)$current_user_id && (int)$ticket['customer_id'] !== (int)$current_user_id)) {
                throw new Exception($isTr ? "Sadece bileti oluşturan veya sahibi olan müşteri değerlendirme yapabilir." : "Only the ticket creator or owner customer can submit feedback.");
            }

            // Insert into database
            $stmtRate = $pdo->prepare("INSERT INTO ticket_ratings (ticket_id, user_id, agent_id, rating, comment) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)");
            $stmtRate->execute([$ticketId, $current_user_id, $ticketPersonnelId, $ratingVal, $ratingComment]);
            
            // 1. Sistem Yanıtı Ekle
            $ratingStars = str_repeat('★', $ratingVal) . str_repeat('☆', 5 - $ratingVal);
            $sysMessage = $isTr 
                ? "<b>Bilet Değerlendirildi:</b> {$ratingVal}/5 {$ratingStars}<br><b>Yorum:</b> " . htmlspecialchars($ratingComment)
                : "<b>Ticket Rated:</b> {$ratingVal}/5 {$ratingStars}<br><b>Comment:</b> " . htmlspecialchars($ratingComment);
            
            $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, reply_type) VALUES (?, ?, ?, 'rating')")
                ->execute([$ticketId, $current_user_id, $sysMessage]);

            // 2. Temsilcinin Görebilmesi İçin Okunmadı Olarak İşaretle (Bildirim Çubuğu İçin)
            $pdo->prepare("UPDATE tickets SET agent_read = 0, unread_replies_count = unread_replies_count + 1 WHERE id = ?")
                ->execute([$ticketId]);

            // 3. Telegram & Webhook Bildirimlerini Tetikle
            try {
                $settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
                $tgToken = $settings['telegram_bot_token'] ?? '';
                $tgChatId = $settings['telegram_admin_chat_id'] ?? '';
                $siteUrl = rtrim($settings['site_url'] ?? '', '/');
                $ticketLink = $siteUrl . "/bilet-detay/" . $ticketId;
                
                $customerName = $_SESSION['fullname'] ?? 'Müşteri';
                $msgText = $isTr
                    ? "⭐ <b>BİLET PUANLANDI</b>\n\n🔖 <b>Bilet No:</b> <code>{$ticket['ticket_no']}</code>\n📌 <b>Konu:</b> {$ticket['title']}\n👤 <b>Müşteri:</b> {$customerName}\n⭐ <b>Puan:</b> {$ratingVal}/5 ({$ratingStars})\n💬 <b>Yorum:</b> {$ratingComment}\n\n🔗 {$ticketLink}"
                    : "⭐ <b>TICKET RATED</b>\n\n🔖 <b>Ticket No:</b> <code>{$ticket['ticket_no']}</code>\n📌 <b>Subject:</b> {$ticket['title']}\n👤 <b>Customer:</b> {$customerName}\n⭐ <b>Rating:</b> {$ratingVal}/5 ({$ratingStars})\n💬 <b>Comment:</b> {$ratingComment}\n\n🔗 {$ticketLink}";
                
                // Telegram
                if ($tgToken && $tgChatId) {
                    $url = "https://api.telegram.org/bot{$tgToken}/sendMessage";
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                        'chat_id' => $tgChatId,
                        'text' => $msgText,
                        'parse_mode' => 'HTML'
                    ]));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    @curl_exec($ch);
                    curl_close($ch);
                }

                // Webhooks
                $slackUrl = $settings['webhook_slack_url'] ?? '';
                if ($slackUrl) {
                    $ch = curl_init($slackUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['text' => $msgText]));
                    @curl_exec($ch);
                    curl_close($ch);
                }

                $discordUrl = $settings['webhook_discord_url'] ?? '';
                if ($discordUrl) {
                    $ch = curl_init($discordUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['content' => str_replace('*', '**', $msgText)]));
                    @curl_exec($ch);
                    curl_close($ch);
                }

                $teamsUrl = $settings['webhook_teams_url'] ?? '';
                if ($teamsUrl) {
                    $ch = curl_init($teamsUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['text' => $msgText]));
                    @curl_exec($ch);
                    curl_close($ch);
                }
            } catch (Exception $e) {}
            
            $_SESSION['mesaj'] = $isTr ? "Değerlendirmeniz başarıyla kaydedildi. Teşekkür ederiz!" : "Your feedback has been saved successfully. Thank you!";
            header("Location: " . $base_url . "bilet-detay/$ticketId");
            exit;
        }

        if ($current_user_role == 2) {
            if ($action !== 'reply' && $action !== 'reopen_ticket' && $action !== 'rate_ticket') {
                throw new Exception($isTr ? "Personel bu işlemi gerçekleştiremez." : "Personnel cannot perform this action.");
            }
            if ($ticket['creator_id'] != $current_user_id) {
                throw new Exception($isTr ? "Sadece kendi oluşturduğunuz biletlere cevap yazabilirsiniz." : "You can only reply to your own tickets.");
            }
            if ($action === 'reply') {
                // Müşteri yanıt verince bilet tekrar "Açık" olmalı (personel cevap bekliyor)
                $_POST['status'] = 'open';
                $_POST['priority'] = $ticket['priority'];
                unset($_POST['assignee_id']);
                unset($_POST['is_private']);
            }
        }

    if (in_array($action, ['reply', 'update_status', 'update_priority'])) {
        if ($action == 'reply') {
            if (function_exists('checkRateLimit') && !checkRateLimit('ticket_reply', 10, 60)) {
                $_SESSION['mesaj'] = "⚠️ " . ($isTr ? "Çok fazla yanıt gönderdiniz. Lütfen 1 dakika bekleyip tekrar deneyiniz." : "Too many reply attempts. Please wait 1 minute before trying again.");
                header("Location: " . $base_url . "bilet-detay/$ticketId");
                exit;
            }
        }
        $message = $_POST['message'] ?? '';
        $is_private = isset($_POST['is_private']) && $_POST['is_private'] == '1' ? 1 : 0;

        $newStatus = (isset($_POST['status']) && $_POST['status'] !== '') ? $_POST['status'] : $ticket['status'];

        $isCustomerReplyAction = ((int)$current_user_role === 2 || ((int)$ticket['user_id'] === (int)$current_user_id && (int)$ticketPersonnelId !== (int)$current_user_id));

        // Personel cevap yazdıysa ve durumu manuel değiştirmediyse otomatik "Müşteri Cevabı Bekleniyor" yap (Eğer müşteri rolünde yanıtlamıyorsa)
        if ($action == 'reply' && empty($_POST['status']) && !in_array($ticket['status'], ['resolved', 'closed'])) {
            if (in_array((int) $current_user_role, [1, 3]) && !$isCustomerReplyAction) {
                $newStatus = 'waiting_customer';
            } elseif ($isCustomerReplyAction && $ticket['status'] === 'waiting_customer') {
                // Müşteri (veya müşteri rolündeki personel) cevap yazarsa bileti tekrar açık/atandı yap
                $newStatus = ($ticketPersonnelId > 0) ? 'assigned' : 'open';
            }
        }

        $newPriority = $_POST['priority'] ?? $ticket['priority'];

        // --- DOSYA EKLERİ GÜVENLİK VE UZANTI KONTROLÜ (CEVAP EKLENMEDEN ÖNCE!) ---
        $allowedExts = getAllowedUploadExtensions();
        if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $i => $fname) {
                if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $originalName = basename($fname);
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (empty($ext) || !in_array($ext, $allowedExts)) {
                    throw new Exception($isTr 
                        ? "⚠️ Desteklenmeyen Dosya Formatı! Yüklemeye çalıştığınız '" . htmlspecialchars($originalName) . "' dosyası güvenlik kuralları gereği kabul edilmemektedir. İzin verilen formatlar: " . strtoupper(implode(', ', $allowedExts))
                        : "⚠️ Unsupported File Format! The file '" . htmlspecialchars($originalName) . "' is not allowed. Allowed formats: " . strtoupper(implode(', ', $allowedExts)));
                }
            }
        }

        $msgAdded = false;
        if (!empty(strip_tags($message))) {
            $time_spent = isset($_POST['time_spent_minutes']) ? (int) $_POST['time_spent_minutes'] : 0;
            $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_private, time_spent_minutes) VALUES (?,?,?,?,?)")
                ->execute([$ticketId, $current_user_id, $message, $is_private, $time_spent]);
            $replyId = $pdo->lastInsertId();
            $msgAdded = true;

            // Trigger notifications (Telegram, Slack, Webhooks, etc.)
            if (function_exists('sendReplyNotifications')) {
                sendReplyNotifications($ticketId, $replyId, $pdo);
            }

            // --- DOSYA EKLERİNİ İŞLE ---
            if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
                $upload_dir = __DIR__ . '/../../public/uploads/tickets/';
                if (!is_dir($upload_dir))
                    mkdir($upload_dir, 0755, true);

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

                foreach ($_FILES['attachments']['name'] as $i => $fname) {
                    if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $originalName = basename($fname);
                    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExts)) continue;
                    // Gerçek MIME kontrolü
                    $realMime = mime_content_type($_FILES['attachments']['tmp_name'][$i]);
                    if (!empty($realMime) && in_array(strtolower($realMime), $dangerousMimes)) continue;
                    if (empty($realMime)) $realMime = 'application/octet-stream';
                    
                    // Diskteki isim rastgele, DB'de orijinal isim
                    $new_name = 'att_' . bin2hex(random_bytes(8)) . '_' . $ticketId . '.' . $ext;
                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $upload_dir . $new_name)) {
                        $pdo->prepare("INSERT INTO ticket_attachments (ticket_id, reply_id, uploader_id, file_name, file_path, file_type, file_size) VALUES (?,?,?,?,?,?,?)")
                            ->execute([$ticketId, $replyId, $current_user_id, $originalName, 'uploads/tickets/' . $new_name, $realMime, $_FILES['attachments']['size'][$i]]);
                    }
                }
            }

            // TICKET LOCKED: Sadece yetkili/personel (Role 1 veya 3) cevap verirken kilidi üzerine alır. Müşteri (Role 2) cevap verdiğinde var olan kilit DEĞİŞMEZ/SİLİNMEZ.
            if (in_array((int)$current_user_role, [1, 3])) {
                $pdo->prepare("UPDATE tickets SET locked_by = ?, locked_at = NOW() WHERE id = ?")
                    ->execute([$current_user_id, $ticketId]);
                $ticket['locked_by'] = $current_user_id;
                $ticket['locked_by_name'] = $_SESSION['fullname'] ?? 'Unknown';
                $ticket['locked_at'] = date('Y-m-d H:i:s');
            }

            if (!$ticket['first_response_date']) {
                $pdo->prepare("UPDATE tickets SET first_response_date = NOW() WHERE id = ?")->execute([$ticketId]);
            }

            // [Auto Assign] Sadece teknik personel (role 1 veya 3) cevap atarsa atama yap
            // Musteri (role 2) cevap verse zimmetleme yapma
            if (empty($ticketPersonnelId) && $current_user_id != $ticket['creator_id'] && in_array((int) $current_user_role, [1, 3])) {
                $pdo->prepare("UPDATE tickets SET {$personnelCol} = ?, assigned_by = ?, status = 'assigned' WHERE id = ? AND ({$personnelCol} IS NULL OR {$personnelCol} = 0)")
                    ->execute([$current_user_id, $current_user_id, $ticketId]);
                $ticketPersonnelId = $current_user_id;

                $responderName = $_SESSION['fullname'] ?? 'Sistem';
                $pdo->prepare("INSERT INTO system_logs (action, details) VALUES (?, ?)")
                    ->execute(['Auto Assign', "ID: {$ticketId} - Bilet ilk yanit veren {$responderName} tarafindan otomatik ustlenildi."]);

                ticketLogAl($pdo, $current_user_id, 'OTOMATIK ATAMA (ILK CEVAP)', $ticket['ticket_no'], "Bilet {$responderName} uzerine otomatik alindi.");
            }
        }

        $statusChanged = ($newStatus !== $ticket['status']);
        $priorityChanged = ($newPriority !== $ticket['priority']);

        if ($msgAdded) {
            $islem_tipi = $is_private ? 'IC NOT EKLENDI' : 'YANITLANDI';
            ticketLogAl($pdo, $current_user_id, $islem_tipi, $ticket['ticket_no'], mb_substr(strip_tags($message), 0, 100, 'UTF-8'));
        }

        $assigneeChanged = false;
        $postedPersonnelId = 0;
        if ($newStatus === 'assigned' && isset($_POST['assignee_id']) && intval($_POST['assignee_id']) > 0) {
            $postedPersonnelId = intval($_POST['assignee_id']);
            if ($postedPersonnelId !== $ticketPersonnelId) {
                $assigneeChanged = true;
            }
        }

        if ($statusChanged || $priorityChanged || $assigneeChanged) {
            $updateClauses = ["status = ?", "priority = ?"];
            $updateParams = [$newStatus, $newPriority];

            if ($assigneeChanged && $postedPersonnelId > 0) {
                $updateClauses[] = "{$personnelCol} = ?";
                $updateParams[] = $postedPersonnelId;
                $updateClauses[] = "assigned_by = ?";
                $updateParams[] = $current_user_id;

                // Temsilci değiştiğinde, eğer atanan kişi şu anki kullanıcı değilse, kilidi de temizle!
                // Böylece yeni atanan kişi bilet detayını açtığında kilit uyarısı ile karşılaşmaz.
                if ($postedPersonnelId !== (int)$current_user_id) {
                    $updateClauses[] = "locked_by = NULL";
                    $updateClauses[] = "locked_at = NULL";
                }
            }

            // "Kim kapata tıklarsa atanan direkt o olsun" kuralı ve OTOMATİK KİLİTLEME
            if ($statusChanged && in_array($newStatus, ['resolved', 'closed'])) {
                if (in_array((int) $current_user_role, [1, 3])) {
                    $ticketPersonnelId = $current_user_id;
                    $updateClauses[] = "{$personnelCol} = ?";
                    $updateParams[] = $current_user_id;
                    $updateClauses[] = "assigned_by = ?";
                    $updateParams[] = $current_user_id;
                }
                // Durum "resolved" veya "closed" ise bileti kilitle
                $updateClauses[] = "locked_by = ?";
                $updateParams[] = $current_user_id;
                $updateClauses[] = "locked_at = NOW()";
            }

            // Kapalı/Çözüldü alanlarını ve tarihleri set et
            if ($statusChanged) {
                $updateClauses[] = "agent_read = 0";
                if ($newStatus === 'closed') {
                    $updateClauses[] = "closed_date = NOW()";
                    $updateClauses[] = "closed_by = ?";
                    $updateParams[] = $current_user_id;
                } elseif ($newStatus === 'resolved') {
                    $updateClauses[] = "resolved_date = NOW()";
                } else {
                    // Reopened or other status change
                    $updateClauses[] = "closed_date = NULL";
                    $updateClauses[] = "closed_by = NULL";
                    $updateClauses[] = "resolved_date = NULL";
                }
            }
            
            $updateParams[] = $ticketId;
            $pdo->prepare("UPDATE tickets SET " . implode(', ', $updateClauses) . " WHERE id = ?")
                ->execute($updateParams);

            if ($assigneeChanged && $postedPersonnelId > 0) {
                $stmtUser = $pdo->prepare("SELECT fullname, mail FROM users WHERE id = ?");
                $stmtUser->execute([$postedPersonnelId]);
                $assignedUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

                if ($assignedUser) {
                    // Log Atama
                    $agentName = $_SESSION['fullname'] ?? 'Sistem';
                    $logMsg = "ID: {$ticketId} - Bilet {$assignedUser['fullname']} temsilcisine atandı (Yanıt kutusundan). İşlemi Yapan: {$agentName}";
                    $pdo->prepare("INSERT INTO system_logs (action, details) VALUES (?, ?)")->execute(['Ticket Assigned', $logMsg]);

                    ticketLogAl($pdo, $current_user_id, 'ATANDI', $ticket['ticket_no'], "Kime: {$assignedUser['fullname']}");

                    // UI'da görünecek sistem mesajı
                    $histMsg = str_replace(':user', '<b>'.$assignedUser['fullname'].'</b>', __("history_assigned"));
                    $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, reply_type) VALUES (?, ?, ?, 'system')")
                        ->execute([$ticketId, $current_user_id, $histMsg]);

                    // Bildirim E-postası (Sadece temsilciye)
                    if ($assignedUser['mail']) {
                        $mailHelper = __DIR__ . '/../../app/includes/mailer.php';
                        if (file_exists($mailHelper)) require_once $mailHelper;
                        
                        $mailVarsAssign = [
                            'ticket_no'     => $ticket['ticket_no'],
                            'subject'       => $ticket['title'],
                            'agent_name'    => $assignedUser['fullname'],
                            'customer_name' => $ticket['customer_name'] ?: $ticket['creator_name'],
                            'message'       => $ticket['description'],
                            'link'          => $base_url . "bilet-detay/" . $ticketId
                        ];
                        try {
                            sendTemplatedMail($assignedUser['mail'], $assignedUser['fullname'], 'new_ticket_agent', $mailVarsAssign, '', $lang);
                        } catch (\Throwable $mailEx) {
                            @file_put_contents(
                                __DIR__ . '/../logs/mail_errors.log',
                                date('Y-m-d H:i:s') . " Assign Mail Error [{$assignedUser['mail']}]: " . $mailEx->getMessage() . "\n",
                                FILE_APPEND
                            );
                        }
                    }
                }
            }

            if ($statusChanged) {
                if ($newStatus == 'closed') {
                    ticketLogAl($pdo, $current_user_id, 'KAPATILDI', $ticket['ticket_no'], __("ticket_closed_log_desc"));
                } else if ($newStatus == 'resolved') {
                    ticketLogAl($pdo, $current_user_id, 'ÇÖZÜLDÜ', $ticket['ticket_no'], "Bilet çözüldü olarak işaretlendi.");
                } else {
                    $yeniDurumStr = strtoupper($newStatus);
                    ticketLogAl($pdo, $current_user_id, 'DURUM GUNCELLEMESI', $ticket['ticket_no'], __("status_update_log_desc") . ": {$yeniDurumStr}");
                }
            }
            if ($priorityChanged && !$statusChanged) {
                $prioStr = strtoupper($newPriority);
                ticketLogAl($pdo, $current_user_id, 'ONCELIK GUNCELLEMESI', $ticket['ticket_no'], __("priority_update_log_desc") . ": {$prioStr}");
            }
        }

        // Bildirimler
        if (!$is_private && ($msgAdded || $statusChanged || $assigneeChanged)) {
            $settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            $tgToken = $settings['telegram_bot_token'] ?? '';
            $tgChatId = $settings['telegram_admin_chat_id'] ?? '';

            $mailHelper = __DIR__ . '/../../app/includes/mailer.php';
            if (file_exists($mailHelper))
                require_once $mailHelper;

            $agentName = $_SESSION['fullname'] ?? 'Destek Ekibi';
            $statusLabelsTR = ['open' => 'Açık', 'resolved' => 'Çözüldü', 'closed' => 'Çözüldü\Kapalı'];

            $userSigStmt = $pdo->prepare("SELECT signature FROM users WHERE id = ?");
            $userSigStmt->execute([$current_user_id]);
            $sigRow = $userSigStmt->fetch();
            $signature = ($sigRow && !empty($sigRow['signature'])) ? $sigRow['signature'] : '';

            $isResolved = in_array($newStatus, ['resolved', 'closed']);
            $statusMsg = $statusLabelsTR[$newStatus] ?? $newStatus;

            // Telegram
            if ($tgToken && $tgChatId) {
                $safeTitle = htmlspecialchars((string) ($ticket['title'] ?? ''), ENT_NOQUOTES, 'UTF-8');
                $ticketLink = $base_url . "bilet-detay/" . $ticketId;
                
                // Temsilci hangi dili kullanıyorsa e-postalar o dilde gitsin (müşteriye giden mailin dili temsilcinin seçtiği dile göre belirlenir)
                $lang = $_SESSION['lang'] ?? ($settings['mail_default_lang'] ?? 'tr');
                if ($lang !== 'en') $lang = 'tr'; // Default to TR

                $statusConfig = json_decode($settings['ticket_statuses_config'] ?? '', true);
                $localizedStatuses = ['tr' => [], 'en' => []];
                if (!empty($statusConfig)) {
                    foreach ($statusConfig as $k => $v) {
                        $localizedStatuses['tr'][$k] = $v['label'];
                        $localizedStatuses['en'][$k] = $v['label'];
                    }
                } else {
                    $localizedStatuses = [
                        'tr' => [
                            'open' => 'Açık',
                            'assigned' => 'Atandı',
                            'pending' => 'Beklemede',
                            'waiting_customer' => 'Müşteri Cevabı Bekleniyor',
                            'resolved' => 'Çözüldü',
                            'closed' => 'Çözüldü\Kapalı'
                        ],
                        'en' => [
                            'open' => 'Open',
                            'assigned' => 'Assigned',
                            'pending' => 'Pending',
                            'waiting_customer' => 'Waiting for Customer',
                            'resolved' => 'Resolved',
                            'closed' => 'Resolved/Closed'
                        ]
                    ];
                }

                $oldStatus = $ticket['status'] ?? 'open';
                $oldStatusMsg = $localizedStatuses[$lang][$oldStatus] ?? ($localizedStatuses['tr'][$oldStatus] ?? $oldStatus);
                $newStatusMsg = $localizedStatuses[$lang][$newStatus] ?? ($localizedStatuses['tr'][$newStatus] ?? $newStatus);
                $performerName = $_SESSION['fullname'] ?? 'Sistem';
                
                $newAgentName = '';
                if ($assigneeChanged && isset($assignedUser['fullname'])) {
                    $newAgentName = $assignedUser['fullname'];
                }
                
                $safeMsg = mb_substr(strip_tags(html_entity_decode((string)$message, ENT_QUOTES, 'UTF-8')), 0, 400);
                if (empty($safeMsg)) $safeMsg = '...';

                $tgTpl = null;
                $tgVars = null;

                if ($isResolved) {
                    if ($lang === 'en') {
                        $tgTpl = $settings['tg_resolved_ticket_en_tpl'] ?? "✅ <b>TICKET RESOLVED</b>\n\n🔖 <b>Ticket No:</b> <code>{{ticket_no}}</code>\n📌 <b>Subject:</b> {{subject}}\n✅ <b>Status:</b> {{status}}\n🧑‍💻 <b>Resolved By:</b> {{agent_name}}\n\n📝 <b>Message:</b>\n{{message}}\n\n🔗 {{link}}";
                    } else {
                        $tgTpl = $settings['tg_resolved_ticket_tr_tpl'] ?? ($settings['tg_resolved_ticket_tpl'] ?? "✅ <b>TALEP TAMAMLANDI</b>\n\n🔖 <b>Takip No:</b> <code>{{ticket_no}}</code>\n📌 <b>Konu:</b> {{subject}}\n✅ <b>Durum:</b> {{status}}\n🧑‍💻 <b>İşlemi Yapan:</b> {{agent_name}}\n\n📝 <b>Mesaj:</b>\n{{message}}\n\n🔗 {{link}}");
                    }
                    $tgVars = [
                        'ticket_no' => $ticket['ticket_no'],
                        'subject' => $safeTitle,
                        'status' => $newStatusMsg,
                        'agent_name' => $performerName,
                        'message' => $safeMsg,
                        'link' => $ticketLink
                    ];
                } else if ($assigneeChanged) {
                    if ($lang === 'en') {
                        $tgTpl = $settings['tg_assigned_en_tpl'] ?? "🧑‍💻 <b>TICKET ASSIGNED</b>\n\n🔖 <b>Ticket No:</b> <code>{{ticket_no}}</code>\n📌 <b>Subject:</b> {{subject}}\n🧑‍💻 <b>Newly Assigned Agent:</b> {{agent_name}}\n👤 <b>Assigned By:</b> {{performer_name}}\n\n🔗 {{link}}";
                    } else {
                        $tgTpl = $settings['tg_assigned_tr_tpl'] ?? "🧑‍💻 <b>BİLET ATANDI</b>\n\n🔖 <b>Bilet No:</b> <code>{{ticket_no}}</code>\n📌 <b>Konu:</b> {{subject}}\n🧑‍💻 <b>Yeni Atanan Temsilci:</b> {{agent_name}}\n👤 <b>Atamayı Yapan:</b> {{performer_name}}\n\n🔗 {{link}}";
                    }
                    $tgVars = [
                        'ticket_no' => $ticket['ticket_no'],
                        'subject' => $safeTitle,
                        'agent_name' => $newAgentName,
                        'performer_name' => $performerName,
                        'link' => $ticketLink
                    ];
                } else if ($statusChanged) {
                    if ($lang === 'en') {
                        $tgTpl = $settings['tg_status_update_en_tpl'] ?? "🔄 <b>STATUS UPDATED</b>\n\n🔖 <b>Ticket No:</b> <code>{{ticket_no}}</code>\n📌 <b>Subject:</b> {{subject}}\n🔄 <b>Old Status:</b> {{old_status}}\n➡️ <b>New Status:</b> {{status}}\n🧑‍💻 <b>Updated By:</b> {{performer_name}}\n\n🔗 {{link}}";
                    } else {
                        $tgTpl = $settings['tg_status_update_tr_tpl'] ?? "🔄 <b>DURUM GÜNCELLENDİ</b>\n\n🔖 <b>Bilet No:</b> <code>{{ticket_no}}</code>\n📌 <b>Konu:</b> {{subject}}\n🔄 <b>Eski Durum:</b> {{old_status}}\n➡️ <b>Yeni Durum:</b> {{status}}\n🧑‍💻 <b>İşlemi Yapan:</b> {{performer_name}}\n\n🔗 {{link}}";
                    }
                    $tgVars = [
                        'ticket_no' => $ticket['ticket_no'],
                        'subject' => $safeTitle,
                        'old_status' => $oldStatusMsg,
                        'status' => $newStatusMsg,
                        'performer_name' => $performerName,
                        'link' => $ticketLink
                    ];
                } else if ($msgAdded) {
                    if ($lang === 'en') {
                        $tgTpl = $settings['tg_reply_ticket_en_tpl'] ?? "💬 <b>NEW REPLY ON TICKET</b>\n\n🔖 <b>Ticket No:</b> <code>{{ticket_no}}</code>\n👤 <b>From:</b> {{user_name}}\n\n📝 <b>Message:</b>\n{{message}}\n\n🔗 {{link}}";
                    } else {
                        $tgTpl = $settings['tg_reply_ticket_tr_tpl'] ?? ($settings['tg_reply_ticket_tpl'] ?? "💬 <b>BİLETE YANIT GELDİ</b>\n\n🔖 <b>Bilet No:</b> <code>{{ticket_no}}</code>\n👤 <b>Kimden:</b> {{user_name}}\n\n📝 <b>Mesaj:</b>\n{{message}}\n\n🔗 {{link}}");
                    }
                    $tgVars = [
                        'ticket_no' => $ticket['ticket_no'],
                        'user_name' => $performerName,
                        'agent_name' => $performerName,
                        'subject' => $safeTitle,
                        'message' => $safeMsg,
                        'link' => $ticketLink
                    ];
                }

                if (isset($tgVars) && isset($tgTpl)) {
                    $tgMsg = $tgTpl;
                    foreach ($tgVars as $k => $v) {
                        $tgMsg = str_ireplace(['{{' . $k . '}}', '{{ ' . $k . ' }}'], (string)$v, $tgMsg);
                    }
                }

                if (isset($tgMsg)) {
                    $ch = curl_init("https://api.telegram.org/bot{$tgToken}/sendMessage");
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true, 
                        CURLOPT_RETURNTRANSFER => true, 
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_CONNECTTIMEOUT => 2,
                        CURLOPT_TIMEOUT => 3,
                        CURLOPT_POSTFIELDS => ['chat_id' => $tgChatId, 'text' => $tgMsg, 'parse_mode' => 'HTML']
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                }
            }

            // E-posta Gönderimi (Şablonlu)
            $customerMail = !empty($ticket['customer_email']) ? $ticket['customer_email'] : (!empty($ticket['creator_mail']) ? $ticket['creator_mail'] : '');
            $customerName = !empty($ticket['customer_name']) ? $ticket['customer_name'] : (!empty($ticket['creator_name']) ? $ticket['creator_name'] : 'Müşteri');

            $mailVars = [
                'ticket_no'     => $ticket['ticket_no'],
                'subject'       => $ticket['title'],
                'customer_name' => $customerName,
                'agent_name'    => $agentName,
                'message'       => $message,
                'link'          => $base_url . "bilet-detay/" . $ticketId
            ];

            $replyAttachments = [];
            if ($msgAdded && isset($replyId) && $replyId > 0) {
                try {
                    $attStmt = $pdo->prepare("SELECT file_name, file_path FROM ticket_attachments WHERE reply_id = ?");
                    $attStmt->execute([$replyId]);
                    $attList = $attStmt->fetchAll(PDO::FETCH_ASSOC);
                    $siteUrl = rtrim($settings['site_url'] ?? 'http://localhost', '/');
                    foreach ($attList as $att) {
                        $fpath = $att['file_path'];
                        if (strpos($fpath, 'public/') === 0) {
                            $fpath = substr($fpath, 7);
                        }
                        $fpath = ltrim($fpath, '/');
                        
                        $physicalPath = __DIR__ . '/../../public/' . $fpath;
                        $downloadUrl = $siteUrl . '/' . $fpath;
                        
                        $replyAttachments[] = [
                            'path' => $physicalPath,
                            'name' => $att['file_name'],
                            'url'  => $downloadUrl
                        ];
                    }
                } catch (Exception $ex) {
                    // ignore
                }
            }

            // 1) Mesaj Eklendiyse
            if ($msgAdded) {
                // Eğer işlemi yapan bir Staff ise (Admin/Agent) -> Müşteriye bildirim gider (Atandı durumunda müşteriye mail gitmesini engelliyoruz)
                if (in_array((int)$current_user_role, [1, 3]) && $newStatus !== 'assigned') { 
                    $tKey = $isResolved ? 'resolved' : 'reply_cust';
                    if (($settings["mail_{$tKey}_active"] ?? '1') == '1') {
                        try {
                            sendTemplatedMail($customerMail, $customerName, $tKey, $mailVars, $signature, $lang, $replyAttachments);
                        } catch (\Throwable $mailEx) {
                            @file_put_contents(
                                __DIR__ . '/../logs/mail_errors.log',
                                date('Y-m-d H:i:s') . " Reply Customer Mail Error [{$customerMail}]: " . $mailEx->getMessage() . "\n",
                                FILE_APPEND
                            );
                        }
                    }
                } 
                // Eğer işlemi yapan bir Müşteri ise -> Atanan Temsilciye e-posta gönder
                else {
                    // Canlı veritabanı sorgusu ile atanmış temsilciyi ($personnelCol) çek
                    $stmtAgentLive = $pdo->prepare("
                        SELECT u.id, u.mail, u.fullname 
                        FROM tickets t 
                        JOIN users u ON NULLIF(t.{$personnelCol}, 0) = u.id 
                        WHERE t.id = ? AND u.deleted_at IS NULL AND u.mail IS NOT NULL AND u.mail != ''
                    ");
                    $stmtAgentLive->execute([$ticketId]);
                    $agent = $stmtAgentLive->fetch(PDO::FETCH_ASSOC);

                    if ($agent && !empty($agent['mail'])) {
                        $mailVars['agent_name'] = $agent['fullname'];
                        if (($settings['mail_reply_agent_active'] ?? '1') == '1') {
                            try {
                                sendTemplatedMail($agent['mail'], $agent['fullname'], 'reply_agent', $mailVars, '', $lang, $replyAttachments);
                            } catch (\Throwable $mailEx) {
                                @file_put_contents(
                                    __DIR__ . '/../logs/mail_errors.log',
                                    date('Y-m-d H:i:s') . " Reply Agent Mail Error [{$agent['mail']}]: " . $mailEx->getMessage() . "\n",
                                    FILE_APPEND
                                );
                            }
                        }
                    }
                }
            } 
            // 2) Sadece Durum Değiştiyse (Mesajsız Çözümleme)
            else if ($statusChanged && $isResolved) {
                if (($settings['mail_resolved_active'] ?? '1') == '1') {
                    try {
                        sendTemplatedMail($customerMail, $customerName, 'resolved', $mailVars, '', $lang);
                    } catch (\Throwable $mailEx) {
                        @file_put_contents(
                            __DIR__ . '/../logs/mail_errors.log',
                            date('Y-m-d H:i:s') . " Resolved Mail Error [{$customerMail}]: " . $mailEx->getMessage() . "\n",
                            FILE_APPEND
                        );
                    }
                }
            }

            // Bilet Çözüldü/Kapatıldı bildirimlerini gönder
            if ($statusChanged && $isResolved) {
                try {
                    $tgToken = $settings['telegram_bot_token'] ?? '';
                    $tgChatId = $settings['telegram_admin_chat_id'] ?? '';
                    $siteUrl = rtrim($settings['site_url'] ?? '', '/');
                    $ticketLink = $siteUrl . "/bilet-detay/" . $ticketId;
                    
                    $statusName = $newStatus === 'resolved' ? ($isTr ? 'Çözüldü' : 'Resolved') : ($isTr ? 'Kapandı' : 'Closed');
                    $performerName = $_SESSION['fullname'] ?? 'Destek Ekibi';
                    
                    $msgText = $isTr
                        ? "✅ <b>BİLET KAPATILDI / ÇÖZÜLDÜ</b>\n\n🔖 <b>Bilet No:</b> <code>{$ticket['ticket_no']}</code>\n📌 <b>Konu:</b> {$ticket['title']}\n✅ <b>Yeni Durum:</b> {$statusName}\n🧑‍💻 <b>İşlemi Yapan:</b> {$performerName}\n\n🔗 {$ticketLink}"
                        : "✅ <b>TICKET RESOLVED / CLOSED</b>\n\n🔖 <b>Ticket No:</b> <code>{$ticket['ticket_no']}</code>\n📌 <b>Subject:</b> {$ticket['title']}\n✅ <b>New Status:</b> {$statusName}\n🧑‍💻 <b>Closed By:</b> {$performerName}\n\n🔗 {$ticketLink}";

                    // Telegram
                    if ($tgToken && $tgChatId) {
                        $url = "https://api.telegram.org/bot{$tgToken}/sendMessage";
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $url);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                            'chat_id' => $tgChatId,
                            'text' => $msgText,
                            'parse_mode' => 'HTML'
                        ]));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        @curl_exec($ch);
                        curl_close($ch);
                    }

                    // Webhooks
                    $slackUrl = $settings['webhook_slack_url'] ?? '';
                    if ($slackUrl) {
                        $ch = curl_init($slackUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['text' => $msgText]));
                        @curl_exec($ch);
                        curl_close($ch);
                    }

                    $discordUrl = $settings['webhook_discord_url'] ?? '';
                    if ($discordUrl) {
                        $ch = curl_init($discordUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['content' => str_replace('*', '**', $msgText)]));
                        @curl_exec($ch);
                        curl_close($ch);
                    }

                    $teamsUrl = $settings['webhook_teams_url'] ?? '';
                    if ($teamsUrl) {
                        $ch = curl_init($teamsUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['text' => $msgText]));
                        @curl_exec($ch);
                        curl_close($ch);
                    }
                } catch (Exception $ex) {}
            }
        }

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'redirect' => in_array($newStatus, ['resolved', 'closed']) ? ($base_url . "anasayfa?panel=ticket") : ($base_url . "bilet-detay/$ticketId"),
                'message' => __("ticket_updated_success")
            ]);
            exit;
        }
        } // end if in_array action
    } catch (\Throwable $e) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $_SESSION['mesaj'] = "Hata: " . $e->getMessage();
    }

    // Kapatma veya cozumleme sonrasi anasayfaya don (AJAX değilse buraya düşer)
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['reply', 'update_status', 'update_priority'])) {
        $newStatus = (isset($_POST['status']) && $_POST['status'] !== '') ? $_POST['status'] : ($ticket['status'] ?? 'open');
        $pdo->prepare("UPDATE tickets SET agent_read = 0, unread_replies_count = unread_replies_count + 1 WHERE id = ?")->execute([$ticketId]);
        if (in_array($newStatus, ['resolved', 'closed'])) {
            $_SESSION['mesaj'] = __("ticket_closed_success");
        } else {
            $_SESSION['mesaj'] = __("ticket_updated_success");
        }

        if (!empty($_SESSION['send_warnings'])) {
            $warningText = implode("<br>", $_SESSION['send_warnings']);
            unset($_SESSION['send_warnings']);
            $_SESSION['mesaj'] .= "<br><br>" . $warningText;
        }

        if (in_array($newStatus, ['resolved', 'closed'])) {
            header("Location: " . $base_url . "anasayfa?panel=ticket");
        } else {
            header("Location: " . $base_url . "bilet-detay/$ticketId");
        }
        exit;
    }

    // Ayrı Atama (Assign) İşlemi
    if ($action == 'modal_assign') {
        $postedPersonnelId = intval($_POST['personnel_id'] ?? ($_POST['assigned_to'] ?? 0));
        $finalAssign = $postedPersonnelId > 0 ? $postedPersonnelId : null;

        // DEBUG: Log assignment attempt
        error_log("DEBUG: modal_assign - Ticket: $ticketId, Personnel: " . ($finalAssign ?: 'NULL') . ", Column: $personnelCol");

        $stmtUpd = $pdo->prepare("UPDATE tickets SET {$personnelCol} = ?, assigned_by = ?, locked_by = NULL, locked_at = NULL WHERE id = ?");
        $stmtUpd->execute([$finalAssign, $current_user_id, $ticketId]);

        if ($stmtUpd->rowCount() > 0) {
            error_log("DEBUG: modal_assign - SUCCESS! Row affected.");
        } else {
            error_log("DEBUG: modal_assign - No rows affected. Check if values are same or if query failed.");
        }

        if ($finalAssign) {
            $stmtUser = $pdo->prepare("SELECT fullname, mail FROM users WHERE id = ?");
            $stmtUser->execute([$finalAssign]);
            $assignedUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if ($assignedUser) {
                // Log Atama
                $agentName = $_SESSION['fullname'] ?? 'Sistem';
                $logMsg = "ID: {$ticketId} - Bilet {$assignedUser['fullname']} temsilcisine atandı. İşlemi Yapan: {$agentName}";
                $pdo->prepare("INSERT INTO system_logs (action, details) VALUES (?, ?)")->execute(['Ticket Assigned', $logMsg]);

                ticketLogAl($pdo, $current_user_id, 'ATANDI', $ticket['ticket_no'], "Kime: {$assignedUser['fullname']}");

                // UI'da görünecek sistem mesajı
                $histMsg = str_replace(':user', '<b>'.$assignedUser['fullname'].'</b>', __("history_assigned"));
                $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, reply_type) VALUES (?, ?, ?, 'system')")
                    ->execute([$ticketId, $current_user_id, $histMsg]);

                // Bildirim E-postası (Sadece temsilciye)
                if ($assignedUser['mail']) {
                    $mailHelper = __DIR__ . '/../../app/includes/mailer.php';
                    if (file_exists($mailHelper)) require_once $mailHelper;
                    
                    $mailVarsAssign = [
                        'ticket_no'     => $ticket['ticket_no'],
                        'subject'       => $ticket['title'],
                        'agent_name'    => $assignedUser['fullname'],
                        'customer_name' => $ticket['customer_name'] ?: $ticket['creator_name'],
                        'message'       => $ticket['description'],
                        'link'          => $base_url . "bilet-detay/" . $ticketId
                    ];
                    try {
                        sendTemplatedMail($assignedUser['mail'], $assignedUser['fullname'], 'new_ticket_agent', $mailVarsAssign, '', $lang);
                    } catch (\Throwable $mailEx) {
                        @file_put_contents(
                            __DIR__ . '/../logs/mail_errors.log',
                            date('Y-m-d H:i:s') . " Assign Mail (System) Error [{$assignedUser['mail']}]: " . $mailEx->getMessage() . "\n",
                            FILE_APPEND
                        );
                    }
                }
            }
        } else {
            // Unassign
            $histMsg = __("history_unassigned");
            $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, reply_type) VALUES (?, ?, ?, 'system')")
                ->execute([$ticketId, $current_user_id, $histMsg]);
        }

        $_SESSION['mesaj'] = __("assign_success");
        header("Location: " . $base_url . "bilet-detay/$ticketId");
        exit;
    }

    // Transfer (Queue Change) İşlemi
    if ($action == 'modal_transfer' && in_array($current_user_role, [1, 2, 3]) && hasPermission('biletler_transfer')) {
        $newQueueId = intval($_POST['queue_id'] ?? 0);
        if ($newQueueId > 0 && $newQueueId != $ticket['queue_id']) {
            // Get queue names for logging
            $stmtQ = $pdo->prepare("SELECT name FROM queues WHERE id = ?");
            $stmtQ->execute([$ticket['queue_id']]);
            $oldQName = $stmtQ->fetchColumn() ?: 'Unknown';
            
            $stmtQ->execute([$newQueueId]);
            $newQName = $stmtQ->fetchColumn() ?: 'Unknown';

            $pdo->prepare("UPDATE tickets SET queue_id = ? WHERE id = ?")->execute([$newQueueId, $ticketId]);

            // Log System Reply
            $histMsg = str_replace([':old_queue', ':new_queue'], ["<b>$oldQName</b>", "<b>$newQName</b>"], __("history_transferred"));
            $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, reply_type) VALUES (?, ?, ?, 'system')")
                ->execute([$ticketId, $current_user_id, $histMsg]);

            ticketLogAl($pdo, $current_user_id, 'TRANSFER', $ticket['ticket_no'], "Kuyruk Değişti: $oldQName -> $newQName");
            
            $_SESSION['mesaj'] = $isTr ? "Bilet başarıyla transfer edildi." : "Ticket transferred successfully.";
        }
        header("Location: " . $base_url . "bilet-detay/$ticketId");
        exit;
    }

    if ($action == 'claim_ticket') {
        // Zaten birine atanmışsa sahiplenemesin (isteğe bağlı, ama genelde boş olanlar sahiplenilir)
        if ($ticketPersonnelId > 0 && $current_user_role != 1) {
            $_SESSION['mesaj'] = __("ticket_already_assigned_error");
        } else {
            $pdo->prepare("UPDATE tickets SET {$personnelCol} = ? WHERE id = ?")->execute([$current_user_id, $ticketId]);

            // Log claim
            $agentName = $_SESSION['fullname'] ?? 'Sistem';
            $logMsg = "ID: {$ticketId} - Bilet {$agentName} tarafından sahiplenildi.";
            $pdo->prepare("INSERT INTO system_logs (action, details) VALUES (?, ?)")->execute(['Ticket Claimed', $logMsg]);

            ticketLogAl($pdo, $current_user_id, 'SAHIPLENILDI', $ticket['ticket_no'], "Temsilci kendi uzerine aldi.");

            $_SESSION['mesaj'] = "✅ Bilet başarıyla üzerinize alındı.";
        }
        header("Location: " . $base_url . "bilet-detay/$ticketId");
        exit;
    }

    if ($action == 'reopen_ticket') {
        $pdo->prepare("UPDATE tickets SET status = 'open', closed_date = NULL, closed_by = NULL, resolved_date = NULL, locked_by = NULL, locked_at = NULL WHERE id = ?")->execute([$ticketId]);
        ticketLogAl($pdo, $current_user_id, 'YENIDEN ACILDI', $ticket['ticket_no'], __("ticket_reopened_log_desc"));
        $_SESSION['mesaj'] = __("ticket_reopened_success");
        header("Location: " . $base_url . "bilet-detay/$ticketId");
        exit;
    }

    if ($action == 'delete_ticket' && $current_user_role == 1) {
        try {
            // Record deleted ticket number to prevent email replies from recreating it
            try {
                $pdo->prepare("INSERT IGNORE INTO deleted_tickets (ticket_no, deleted_at) VALUES (?, NOW())")
                    ->execute([$ticket['ticket_no']]);
            } catch (Throwable $e) {}

            $pdo->prepare("DELETE FROM ticket_replies WHERE ticket_id = ?")->execute([$ticketId]);
            $pdo->prepare("DELETE FROM ticket_attachments WHERE ticket_id = ?")->execute([$ticketId]);
            $pdo->prepare("DELETE FROM ticket_tag_maps WHERE ticket_id = ?")->execute([$ticketId]);
            $pdo->prepare("DELETE FROM system_logs WHERE action LIKE 'Ticket%' AND details LIKE ?")->execute(['%ID: ' . $ticketId . '%']);
            ticketLogAl($pdo, $current_user_id, 'SİLİNDİ', $ticket['ticket_no'], __("ticket_deleted_log_desc"));
            $pdo->prepare("DELETE FROM tickets WHERE id = ?")->execute([$ticketId]);
            $_SESSION['mesaj'] = __("ticket_deleted_success_msg");
            header("Location: " . $base_url . "anasayfa?panel=ticket");
            exit;
        } catch (PDOException $e) {
            $_SESSION['mesaj'] = "Hata: Bilet silinemedi. " . str_replace("'", "\'", $e->getMessage());
            header("Location: " . $base_url . "bilet-detay/$ticketId");
            exit;
        }
    }

    // Mark ticket read/unread
    $pdo->prepare("UPDATE tickets SET agent_read = 0, unread_replies_count = unread_replies_count + 1 WHERE id = ?")->execute([$ticketId]);

    $_SESSION['mesaj'] = __("action_success");
    header("Location: " . $base_url . "bilet-detay/$ticketId");
    exit;
}

// Ticket'ı yenile (güncel durum için)
$stmtT->execute([$ticketId]);
$ticket = $stmtT->fetch(PDO::FETCH_ASSOC);
$ticketPersonnelId = (int) ($ticket[$personnelCol] ?? 0);

// Yanıtlar
$stmtR = $pdo->prepare("
    SELECT r.*, u.fullname, u.mail, u.is_online, u.role
    FROM ticket_replies r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.ticket_id = ?
    ORDER BY r.created_at ASC
");
$stmtR->execute([$ticketId]);
$replies = $stmtR->fetchAll(PDO::FETCH_ASSOC);

// Ekler (İlk Mesaj ve Yanıtlar Ayrıştırılmış)
$stmtInitAtt = $pdo->prepare("SELECT * FROM ticket_attachments WHERE ticket_id = ? AND (reply_id IS NULL OR reply_id = 0)");
$stmtInitAtt->execute([$ticketId]);
$attachments = $stmtInitAtt->fetchAll(PDO::FETCH_ASSOC);

$stmtReplyAtt = $pdo->prepare("SELECT * FROM ticket_attachments WHERE ticket_id = ? AND reply_id > 0 AND reply_id IS NOT NULL");
$stmtReplyAtt->execute([$ticketId]);
$replyAttachmentsMap = [];
foreach ($stmtReplyAtt->fetchAll(PDO::FETCH_ASSOC) as $rAtt) {
    $replyAttachmentsMap[$rAtt['reply_id']][] = $rAtt;
}

// Alt Görevler
$stmtSt = $pdo->prepare("SELECT * FROM ticket_subtasks WHERE ticket_id = ? ORDER BY id ASC");
$stmtSt->execute([$ticketId]);
$subtasks = $stmtSt->fetchAll(PDO::FETCH_ASSOC);

// Efor Takibi
$stmtTl = $pdo->prepare("SELECT tl.*, u.fullname FROM ticket_time_logs tl JOIN users u ON tl.user_id = u.id WHERE tl.ticket_id = ? ORDER BY tl.logged_at ASC");
$stmtTl->execute([$ticketId]);
$timeLogs = $stmtTl->fetchAll(PDO::FETCH_ASSOC);

$totalMinutes = array_sum(array_column($timeLogs, 'time_spent_minutes'));
$totalHours = floor($totalMinutes / 60);
$remainingMins = $totalMinutes % 60;

// Atama için uygun temsilcileri çek
$teamMembers = [];
if (in_array($current_user_role, [1, 2, 3])) {
    $ticketPersonnelId = (int) ($ticket[$personnelCol] ?? 0);
    
    if ($ticket['team_id']) {
        $stmtTM = $pdo->prepare("SELECT DISTINCT u.id, u.fullname FROM users u JOIN teams_users tu ON u.id = tu.user_id WHERE tu.team_id = ? AND u.status = 1 AND u.id != ? AND u.id != ? ORDER BY u.fullname ASC");
        $stmtTM->execute([$ticket['team_id'], $current_user_id, $ticketPersonnelId]);
        $teamMembers = $stmtTM->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Yanıt bölümü ataması için tüm aktif temsilcileri çek (kendisi ve mevcut atanan dahil)
$allStaffMembers = [];
if (in_array($current_user_role, [1, 2, 3])) {
    $ticketPersonnelId = (int) ($ticket[$personnelCol] ?? 0);
    if ($ticket['team_id']) {
        $stmtAllStaff = $pdo->prepare("
            SELECT DISTINCT u.id, u.fullname 
            FROM users u 
            JOIN teams_users tu ON u.id = tu.user_id 
            WHERE tu.team_id = ? AND u.status = 1
            UNION
            SELECT id, fullname 
            FROM users 
            WHERE id = ? AND status = 1
            ORDER BY fullname ASC
        ");
        $stmtAllStaff->execute([$ticket['team_id'], $ticketPersonnelId]);
        $allStaffMembers = $stmtAllStaff->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Transfer için tüm kuyrukları çek
$allQueues = [];
if (in_array($current_user_role, [1, 3])) {
    $allQueues = $pdo->query("SELECT q.id, q.name, t.name as team_name FROM queues q LEFT JOIN teams t ON q.team_id = t.id WHERE q.status = 1 ORDER BY q.name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Hazır Yanıt Şablonları (Kullanıcıya özel, takıma özel ve genel kayıtlar)
$cannedResponses = [];
if (in_array($current_user_role, [1, 2, 3])) {
    try {
        $myTeams = [];
        if ($current_user_id) {
            $stmtT = $pdo->prepare("SELECT team_id FROM teams_users WHERE user_id = ?");
            $stmtT->execute([$current_user_id]);
            $myTeams = $stmtT->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $teamClause = "";
        if (!empty($myTeams)) {
            $inClause = implode(',', array_map('intval', $myTeams));
            $teamClause = " OR (sharing_type = 'team' AND team_id IN ($inClause))";
        }

        $stmtC = $pdo->prepare("SELECT * FROM canned_responses WHERE (user_id = ? AND sharing_type = 'personal') OR (sharing_type = 'team' AND team_id IN (" . (!empty($myTeams) ? implode(',', array_map('intval', $myTeams)) : '0') . ")) OR sharing_type = 'global' ORDER BY category ASC, title ASC");
        $stmtC->execute([$current_user_id]);
        $cannedResponses = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        @file_put_contents(__DIR__ . '/canned_debug.txt', date('Y-m-d H:i:s') . " | UserID: " . $current_user_id . " | Role: " . $current_user_role . " | Rows: " . json_encode($cannedResponses) . "\n", FILE_APPEND);
        // Let's also dump all rows in canned_responses table to see what is stored in the database
        $allDbRows = $pdo->query("SELECT id, title, user_id, sharing_type, is_global FROM canned_responses")->fetchAll(PDO::FETCH_ASSOC);
        @file_put_contents(__DIR__ . '/canned_debug.txt', "ALL ROWS IN DB: " . json_encode($allDbRows) . "\n\n", FILE_APPEND);
    } catch (Exception $e) {}
}

// ---

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

$slaPercent = null;
$slaDisplayDate = $ticket['sla_due_date'];
if (!$slaDisplayDate && $ticket['create_date']) {
    $slaDisplayDate = date('Y-m-d H:i:s', strtotime($ticket['create_date'] . ' + 24 hours'));
}

if ($slaDisplayDate && $ticket['create_date']) {
    $total = strtotime($slaDisplayDate) - strtotime($ticket['create_date']);
    if (in_array($ticket['status'], ['resolved', 'closed'])) {
        $end_date_str = !empty($ticket['closed_date']) ? $ticket['closed_date'] : (!empty($ticket['resolved_date']) ? $ticket['resolved_date'] : $ticket['update_date']);
        $end_time = $end_date_str ? strtotime($end_date_str) : time();
        $elapsed = $end_time - strtotime($ticket['create_date']);
    } else {
        $elapsed = time() - strtotime($ticket['create_date']);
    }
    $slaPercent = $total > 0 ? min(100, max(0, ($elapsed / $total) * 100)) : 0;
}
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    /* === MODERN CORPORATE TICKET DETAIL PAGE === */
    body {
        background: #f5f6fa;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
    }

    /* TICKET DETAIL LAYOUT GRID - FLUID RESPONSIVE FOR MONITORS & TV DISPLAY */
    .ticket-view-main {
        display: grid;
        grid-template-columns: 1fr 360px;
        gap: 24px;
        padding: 24px;
        align-items: flex-start;
        max-width: 100%;
        margin: 0 auto;
    }

    @media (min-width: 1920px) {
        .ticket-view-main {
            grid-template-columns: 1fr 420px;
            gap: 32px;
            padding: 32px;
        }
        .chat-box {
            max-height: 600px !important;
        }
    }

    @media (min-width: 2560px) {
        .ticket-view-main {
            grid-template-columns: 1fr 480px;
            gap: 40px;
            padding: 40px;
        }
        .chat-box {
            max-height: 750px !important;
        }
    }

    .view-col-main {
        min-width: 0;
    }

    .view-col-sidebar {
        min-width: 340px;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    @media (max-width: 991px) {
        .ticket-view-main {
            grid-template-columns: 1fr;
        }

        .view-col-sidebar {
            min-width: 0;
            order: -1;
        }
    }

    .ticket-header {
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        border-radius: 12px;
        padding: 18px 24px;
        color: white;
        margin-bottom: 20px;
        box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2);
    }

    .ticket-header h1 {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 12px;
    }

    .ticket-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        align-items: center;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        padding-top: 12px;
    }

    .meta-item {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        opacity: 0.9;
    }

    .card {
        border-radius: 12px;
        border: none;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .card-header {
        background: #fff !important;
        color: #1e293b;
        font-weight: 700;
        border-bottom: 1px solid #f1f5f9;
        padding: 12px 16px;
    }

    .info-label {
        width: 100px;
        color: #64748b;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 4px 0 !important;
    }

    .info-value {
        color: #1e293b;
        font-size: 13px;
        font-weight: 500;
        padding: 4px 0 !important;
    }

    body.dark-mode {
        background: #0f172a;
        color: #f1f5f9;
    }

    body.dark-mode .card {
        background: #1e293b !important;
        color: #f1f5f9;
    }

    body.dark-mode .card-header {
        background: #334155 !important;
        color: #f1f5f9;
        border-bottom: 1px solid #475569;
    }

    body.dark-mode .info-label {
        color: #94a3b8;
    }

    body.dark-mode .info-value {
        color: #f1f5f9;
    }

    .priority-badge {
        padding: 4px 12px;
        border-radius: 16px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    /* MAIN CONTENT */
    .main-content {
        display: block;
        padding: 0;
    }

    /* CHAT CONTAINER */
    .chat-container {
        background: white;
        border-radius: 10px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .chat-header {
        background: #f9fafb;
        padding: 14px 18px;
        border-bottom: 1px solid #e5e7eb;
        font-weight: 600;
        color: #374151;
        font-size: 14px;
    }

    .chat-box {
        padding: 12px;
        max-height: 400px;
        overflow-y: auto;
        background: #fafbfc;
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .chat-box::-webkit-scrollbar {
        width: 6px;
    }

    .chat-box::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 3px;
    }

    /* MESSAGE BUBBLES */
    .msg-bubble {
        display: flex;
        gap: 10px;
        margin-bottom: 8px;
        align-items: flex-start;
        align-self: flex-start;
        max-width: 85%;
    }

    .msg-bubble.me {
        flex-direction: row-reverse;
        align-self: flex-end;
    }

    .msg-bubble .avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        flex-shrink: 0;
        color: #fff;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .msg-content {
        min-width: 0; /* Flexbox taşmasını önler */
    }

    .msg-content .bubble {
        padding: 8px 12px;
        border-radius: 10px;
        font-size: 13px;
        line-height: 1.45;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        word-wrap: break-word;
        max-width: 100%;
        overflow-x: auto;
    }

    .msg-content .bubble table {
        display: block;
        max-width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .msg-bubble:not(.me) .bubble {
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        color: #1f2937;
    }

    .msg-bubble.me .bubble {
        background: #eff6ff;
        color: #1e40af;
        border: 1px solid #bfdbfe;
    }

    .msg-bubble.private .bubble {
        background: #fef3c7;
        border: 1px solid #fcd34d;
        color: #92400e;
    }

    .msg-meta {
        font-size: 11px;
        color: #9ca3af;
        margin-top: 4px;
    }

    .msg-bubble.me .msg-meta {
        text-align: right;
    }

    /* REPLY BOX */
    .reply-box {
        background: #fff;
        border-radius: 10px;
        padding: 12px;
        border: 1px solid #e5e7eb;
        margin-top: 12px;
    }

    .note-editor {
        background: #fff;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        font-size: 13px;
    }

    .reply-box .ql-toolbar {
        border-color: #e5e7eb;
        background: #fafbfc;
        border-radius: 8px 8px 0 0;
    }

    .reply-box .ql-container {
        border-color: #e5e7eb;
        border-radius: 0 0 8px 8px;
    }

    /* PASTED TABLES IN QUILL EDITOR */
    .ql-editor table.ql-table-embed {
        width: 100% !important;
        border-collapse: collapse !important;
        margin: 15px 0 !important;
    }
    .ql-editor table.ql-table-embed td, 
    .ql-editor table.ql-table-embed th {
        border: 1px solid #cbd5e1 !important;
        padding: 8px 12px !important;
        text-align: left;
    }
    .ql-editor table.ql-table-embed th {
        background-color: #f1f5f9 !important;
        font-weight: 600;
    }

    /* SIDEBAR: Sticky */
    .msg-bubble.me .bubble {
        background: #eef2ff;
        color: #1e40af;
        border: 1px solid #bfdbfe;
    }
    
    .msg-bubble.private .bubble {
        background: #fef3c7;
        border: 1px solid #fcd34d;
        color: #92400e;
    }
    
    .detail-sidebar {
        position: sticky;
        top: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .detail-sidebar .card {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .detail-sidebar .card-header {
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
        padding: 12px 14px;
        font-size: 13px;
        font-weight: 600;
        color: #374151;
        border-radius: 10px 10px 0 0;
    }

    .detail-sidebar .card-body {
        padding: 12px;
    }

    .info-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        color: #9ca3af;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }

    .info-value {
        font-size: 13px;
        color: #1f2937;
        font-weight: 500;
    }

    .detail-sidebar .table {
        font-size: 12px;
        margin-bottom: 0;
    }

    .detail-sidebar .table td {
        padding: 8px 0;
        border: none;
    }

    .detail-sidebar .table tr:not(:last-child) {
        border-bottom: 1px solid #f3f4f6;
    }

    /* BUTTONS */
    .detail-sidebar .btn {
        font-size: 12px;
        padding: 8px 12px;
        border-radius: 8px;
        font-weight: 500;
        border: 1px solid #e5e7eb;
        transition: all 0.2s ease;
    }

    .detail-sidebar .btn-success {
        background: #10b981;
        border-color: #10b981;
        color: white;
    }

    .detail-sidebar .btn-success:hover {
        background: #059669;
    }

    .detail-sidebar .btn-outline-secondary {
        color: #6b7280;
        border-color: #d1d5db;
    }

    .detail-sidebar .btn-outline-secondary:hover {
        background: #f3f4f6;
        color: #374151;
    }

    .detail-sidebar .btn-outline-danger {
        color: #dc2626;
        border-color: #fecaca;
    }

    .detail-sidebar .btn-outline-danger:hover {
        background: #fee2e2;
        color: #991b1b;
    }

    #sendBtn {
        background: #10b981;
        border-color: #10b981;
        font-weight: 600;
        font-size: 13px;
        padding: 8px 20px;
        border-radius: 8px;
        transition: all 0.2s ease;
        color: white;
    }

    #sendBtn:hover {
        background: #059669;
    }

    #sendBtn:disabled {
        background: #d1d5db;
        border-color: #d1d5db;
        cursor: not-allowed;
    }

    /* 2026 MODERN RESOLVED CARD */
    .resolved-card {
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 36px 24px;
        text-align: center;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.04), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
        transition: all 0.3s ease;
    }

    .resolved-icon-badge {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin-bottom: 16px;
        box-shadow: 0 4px 14px rgba(16, 185, 129, 0.12);
    }

    .resolved-card h5 {
        color: #0f172a;
        font-weight: 700;
        font-size: 1.15rem;
        margin-bottom: 6px;
        letter-spacing: -0.01em;
    }

    .resolved-card p {
        color: #64748b;
        font-size: 0.9rem;
        margin-bottom: 20px;
        max-width: 420px;
        margin-left: auto;
        margin-right: auto;
    }

    .btn-reopen-ticket {
        background: #ffffff;
        color: #2563eb;
        border: 1px solid #cbd5e1;
        font-weight: 600;
        font-size: 0.875rem;
        padding: 9px 24px;
        border-radius: 50px;
        transition: all 0.2s ease;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
    }

    .btn-reopen-ticket:hover {
        background: #2563eb;
        color: #ffffff;
        border-color: #2563eb;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }

    /* DARK MODE OVERRIDES */
    body.dark-mode .resolved-card {
        background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%) !important;
        border-color: rgba(255, 255, 255, 0.08) !important;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25) !important;
    }
    body.dark-mode .resolved-icon-badge {
        background: rgba(16, 185, 129, 0.15) !important;
        color: #34d399 !important;
    }
    body.dark-mode .resolved-card h5 {
        color: #f8fafc !important;
    }
    body.dark-mode .resolved-card p {
        color: #94a3b8 !important;
    }
    body.dark-mode .btn-reopen-ticket {
        background: rgba(255, 255, 255, 0.05);
        color: #60a5fa;
        border-color: rgba(255, 255, 255, 0.15);
    }
    body.dark-mode .btn-reopen-ticket:hover {
        background: #2563eb;
        color: #ffffff;
        border-color: #2563eb;
    }

    /* DARK MODE */
    body.dark-mode {
        background: #1f2937;
        color: #f3f4f6;
    }

    body.dark-mode .chat-box {
        background: #374151 !important;
    }

    body.dark-mode .msg-bubble:not(.me) .bubble {
        background: #4b5563 !important;
        border-color: #555 !important;
        color: #f3f4f6 !important;
    }

    body.dark-mode .msg-bubble.me .bubble {
        background: #2563eb !important;
        border-color: #1e40af !important;
        color: #fff !important;
    }

    body.dark-mode .msg-bubble.private .bubble {
        background: #92400e !important;
        border-color: #b45309 !important;
        color: #fef3c7 !important;
    }

    body.dark-mode .chat-container,
    body.dark-mode .detail-sidebar .card {
        background: #374151 !important;
        border-color: #555 !important;
    }

    body.dark-mode .detail-sidebar .card-header {
        background: #4b5563 !important;
        border-color: #555 !important;
        color: #f3f4f6 !important;
    }

    body.dark-mode .reply-box {
        background: #374151 !important;
        border-color: #555 !important;
    }

    body.dark-mode .note-editor {
        background: #4b5563 !important;
        border-color: #555 !important;
        color: #f3f4f6 !important;
    }
    body.dark-mode .note-editing-area { background: #4b5563 !important; color: #f3f4f6 !important; }
    body.dark-mode .note-toolbar { background: #374151 !important; border-bottom: 1px solid #555 !important; }
    body.dark-mode .note-btn { color: #f3f4f6 !important; background: #4b5563 !important; border-color: #6b7280 !important; }
    body.dark-mode .note-btn:hover { background: #6b7280 !important; }
    }

    body.dark-mode .ql-editor table.ql-table-embed td, 
    body.dark-mode .ql-editor table.ql-table-embed th {
        border-color: #4b5563 !important;
        color: #f3f4f6 !important;
    }
    body.dark-mode .ql-editor table.ql-table-embed th {
        background-color: #374151 !important;
    }

    body.dark-mode .chat-header {
        background: #1e293b !important;
        border-bottom: 1px solid #475569 !important;
        color: #f1f5f9 !important;
    }

    body.dark-mode .text-muted {
        color: #94a3b8 !important;
    }

    body.dark-mode .badge-light {
        background: #334155 !important;
        color: #f3f4f6 !important;
    }

    /* Modern Dark Mode Utility Classes & Overrides */
    .assigned-agent-name {
        color: #1e293b;
    }
    body.dark-mode .assigned-agent-name {
        color: #f1f5f9 !important;
    }

    .info-org-link {
        color: #3b82f6;
        transition: color 0.15s ease-in-out;
    }
    body.dark-mode .info-org-link {
        color: #60a5fa !important;
    }

    .info-contact-link {
        color: #059669;
        transition: color 0.15s ease-in-out;
    }
    body.dark-mode .info-contact-link {
        color: #34d399 !important;
    }

    body.dark-mode .info-value {
        color: #e2e8f0 !important;
    }

    .lock-status-locked {
        background: #fee2e2;
        color: #dc2626;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
    }
    body.dark-mode .lock-status-locked {
        background: rgba(220, 38, 38, 0.2) !important;
        color: #fca5a5 !important;
        border: 1px solid rgba(220, 38, 38, 0.3) !important;
    }

    .lock-status-unlocked {
        background: #d1fae5;
        color: #059669;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
    }
    body.dark-mode .lock-status-unlocked {
        background: rgba(5, 150, 105, 0.2) !important;
        color: #34d399 !important;
        border: 1px solid rgba(5, 150, 105, 0.3) !important;
    }

    body.dark-mode .progress {
        background: #334155 !important;
    }

    .view-col-sidebar .card-header {
        background: #fff;
        border-bottom: 1px solid #e2e8f0;
        color: #1e293b;
    }
    body.dark-mode .view-col-sidebar .card-header {
        background: #1f2937 !important;
        border-bottom: 1px solid #374151 !important;
        color: #f1f5f9 !important;
    }
</style>

<div class="ticket-view-main">
    <!-- ANA SÜTUN: Başlık + Timeline -->
    <div class="view-col-main">
        <!-- Modern Minimal Header -->
        <div class="ticket-header shadow-sm">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent p-0 mb-2">
                        <li class="breadcrumb-item"><a href="biletler" class="text-white opacity-75"
                                style="text-decoration:none; font-size: 12px;"><?= __("tickets") ?></a></li>
                        <li class="breadcrumb-item active text-white" aria-current="page" style="font-size: 12px;">
                            <?= $ticket['ticket_no'] ?>
                        </li>
                    </ol>
                </nav>
                <h1 class="m-0"><?= htmlspecialchars($ticket['title']) ?></h1>
                <div class="ticket-meta mt-3">
                    <div class="meta-item">
                        <i class="far fa-user text-white-50"></i>
                        <?php
                        $custId = (int)($ticket['customer_id'] ?: ($ticket['linked_customer_id'] ?? 0));
                        $orgId = (int)($ticket['organization_id'] ?? 0);
                        $orgName = $ticket['organization_name'] ?? '';
                        $custName = !empty($ticket['customer_name']) ? $ticket['customer_name'] : ($ticket['creator_name'] ?: '—');
                        ?>
                        
                        <?php if ($orgId > 0): ?>
                            <a href="organizasyonlar?q=<?= urlencode($orgName) ?>" class="text-white font-weight-bold" style="text-decoration:none;">
                                <i class="fas fa-building mr-1 opacity-75"></i><?= htmlspecialchars($orgName) ?>
                            </a>
                            <span class="mx-1 opacity-50">/</span>
                        <?php endif; ?>

                        <?php if ($custId > 0): ?>
                            <a href="musteri-detay/<?= $custId ?>" class="text-white <?= $orgId > 0 ? '' : 'font-weight-bold' ?>" style="text-decoration:underline;">
                                <?= htmlspecialchars($custName) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-white"><?= htmlspecialchars($custName) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="meta-item">
                        <i class="far fa-calendar-alt text-white-50"></i>
                        <span><?= date('d.m.Y H:i', strtotime($ticket['create_date'])) ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-layer-group text-white-50"></i>
                        <span><?= htmlspecialchars(!$isTr && ($ticket['queue_name'] ?? '') === 'Genel Kuyruk' ? 'General Queue' : ($ticket['queue_name'] ?? '-')) ?></span>
                    </div>
                    <div class="meta-item ml-auto">
                        <span class="badge badge-pill"
                            style="background: <?= $priorityColors[$ticket['priority']] ?? '#999' ?>; color: #fff; padding: 5px 12px; font-size: 10px;">
                            <?= $priorityLabels[$ticket['priority']] ?? $ticket['priority'] ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="main-content">
            <?php if (!empty($ticket['is_forwarded'])): ?>
                <div class="forwarded-email-notice shadow-sm">
                    <style>
                        .forwarded-email-notice {
                            padding: 16px 20px;
                            background: #f8fafc;
                            border-left: 4px solid #3b82f6;
                            border-radius: 12px;
                            margin-bottom: 20px;
                            font-family: inherit;
                            border: 1px solid #e2e8f0;
                            border-left-width: 4px;
                            transition: all 0.3s ease;
                        }
                        .forwarded-email-notice .notice-title {
                            display: flex;
                            align-items: center;
                            margin-bottom: 8px;
                            color: #1e3a8a;
                            font-weight: 600;
                            font-size: 14px;
                        }
                        .forwarded-email-notice .notice-body {
                            font-size: 13px;
                            color: #475569;
                            line-height: 1.6;
                        }
                        .forwarded-email-notice .notice-inner {
                            padding: 8px 12px;
                            background: rgba(255, 255, 255, 0.7);
                            border-radius: 8px;
                            border: 1px dashed #cbd5e1;
                            color: #1e293b;
                            margin-top: 8px;
                            font-weight: 500;
                        }
                        body.dark-mode .forwarded-email-notice {
                            background: #1e293b !important;
                            border-color: #334155 !important;
                        }
                        body.dark-mode .forwarded-email-notice .notice-title {
                            color: #38bdf8 !important;
                        }
                        body.dark-mode .forwarded-email-notice .notice-body {
                            color: #94a3b8 !important;
                        }
                        body.dark-mode .forwarded-email-notice .notice-inner {
                            background: rgba(15, 23, 42, 0.6) !important;
                            border-color: #475569 !important;
                            color: #f1f5f9 !important;
                        }
                    </style>
                    <div class="notice-title">
                        <span style="font-size: 18px; margin-right: 8px;">✉️</span>
                        <?= $isTr ? 'Yönlendirilmiş Talep Bilgisi' : 'Forwarded Request Information' ?>
                    </div>
                    <div class="notice-body">
                        <div style="margin-bottom: 4px;"><?= $isTr ? 'Bu destek talebi, <strong>'.htmlspecialchars($ticket['creator_name']).'</strong> tarafından yönlendirilen bir e-postadan otomatik olarak oluşturulmuştur.' : 'This support request was automatically created from an email forwarded by <strong>'.htmlspecialchars($ticket['creator_name']).'</strong>.' ?></div>
                        <div class="notice-inner">
                            <strong><?= $isTr ? 'Orijinal Gönderen' : 'Original Sender' ?>:</strong> <?= htmlspecialchars($ticket['forwarder_name'] ?: $ticket['forwarder_email']) ?> (<?= htmlspecialchars($ticket['forwarder_email']) ?>)
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Conversation Timeline -->
            <div class="chat-container" style="margin-bottom: 12px;">
                <div class="chat-header d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-3">
                        <span><i class="fas fa-comments mr-2 text-primary"></i>
                            <?= __("message_box") ?></span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span id="msgCountBadge" class="badge badge-secondary badge-pill"
                            style="font-size: 11px; opacity: 0.8;"><?= count($replies) + 1 ?>
                            <?= __("messages") ?></span>
                        <?php if (in_array($current_user_role, [1, 3])): ?>
                            <button id="claimBtn" class="btn btn-sm btn-primary" style="font-size:13px; padding:6px 12px; border-radius: 8px; font-weight: 600;">
                                <i class="fas fa-reply mr-1"></i> <?= __("reply") ?? 'Yanıtla' ?></button>
                        <?php endif; ?>
                    </div>
                </div>


                <!-- ------- SOHBET ALANI ------- -->
                <div class="chat-box" id="chatBox">
                    <!-- Sohbet Akışı -->

                    <!-- İlk Mesaj (Bilet Açıklaması ve Ekler) -->
                    <?php
                    $creatorInitial = strtoupper(mb_substr($ticket['creator_name'], 0, 1, 'UTF-8'));
                    $color = sprintf('#%06X', mt_rand(0, 0xFFFFFF));
                    $isDescMe = true; // Personnel/Customer initial request is always on the right
                    ?>
                    <div class="msg-bubble <?php echo $isDescMe ? 'me' : ''; ?>" style="max-width: 85%;">
                        <div class="avatar" style="background: <?php echo $isDescMe ? 'transparent' : $color; ?>; <?php echo $isDescMe ? 'border: 2px solid #667eea; color: #667eea;' : ''; ?> font-size:16px;">
                            <?php echo $creatorInitial; ?>
                        </div>
                        <div class="msg-content w-100">
                            <div class="bubble p-3 shadow-sm">
                                <?php echo fixImagePaths($ticket['description'], $base_url); ?>
                            </div>
                            
                            <?php if (!empty($attachments)): ?>
                                <div class="mt-2 p-2 bg-light rounded border border-light shadow-sm" style="width: fit-content;">
                                    <p class="text-sm text-muted mb-2 font-weight-bold"><i
                                            class="fas fa-paperclip mr-1 text-primary"></i><?= __("attachments") ?>:</p>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($attachments as $att): ?>
                                            <?php
                                            // Handle path consistency (some start with public/, some don't)
                                            $fpath = $att['file_path'];
                                            if (strpos($fpath, 'public/') === 0) {
                                                $fpath = substr($fpath, 7);
                                            }
                                            ?>
                                            <a href="<?= $base_url ?><?php echo $fpath; ?>" target="_blank"
                                                class="btn btn-sm btn-outline-secondary bg-white">
                                                <i class="fas fa-download mr-1 text-primary"></i>
                                                <?php echo htmlspecialchars($att['file_name']); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="msg-meta">
                                <strong><?php echo htmlspecialchars($ticket['creator_name']); ?></strong>
                                &bull; <?php echo date('d.m.Y H:i', strtotime($ticket['create_date'])); ?>
                                <?php
                                $diff = time() - strtotime($ticket['create_date']);
                                if ($diff < 60)
                                    $age = $diff . ' ' . __("seconds_ago");
                                elseif ($diff < 3600)
                                    $age = floor($diff / 60) . ' ' . __("minutes_ago");
                                elseif ($diff < 86400)
                                    $age = floor($diff / 3600) . ' ' . __("hours_ago");
                                else
                                    $age = floor($diff / 86400) . ' ' . __("days_ago");
                                ?>
                                <span class="ml-1 text-muted" style="font-size:10px;"><i class="far fa-clock"></i>
                                    <?php echo $age; ?></span>
                                &bull; (<?= __("initial_request") ?>)
                            </div>
                        </div>
                    </div>
                    <?php foreach ($replies as $r):
                        $isCustomerReply = ($r['customer_id'] > 0 || (int)($r['role'] ?? 0) === 2 || empty($r['role']));
                        // Personnel (Customer) replies are always on the right ('me'), Admins/Agents are always on the left.
                        $isMe = $isCustomerReply;
                        $initials = strtoupper(mb_substr($r['fullname'] ?? 'U', 0, 2));
                        $colors = ['#667eea', '#f5576c', '#43e97b', '#fa709a', '#4facfe', '#f093fb'];
                        $color = $colors[crc32($r['fullname'] ?? '') % count($colors)];
                        $isPrivate = $r['is_private'] ?? 0;
                        $isSystem = ($r['reply_type'] ?? 'user') == 'system';
                        if ($isPrivate && $current_user_role == 2 && !$isMe)
                            continue; // Normal user iç notu göremez
                        ?>
                        <?php if ($isSystem): ?>
                            <div class="system-msg text-center my-4">
                                <span class="bg-light px-3 py-2 border rounded-pill text-muted shadow-sm" style="font-size: 11px; font-weight: 600; letter-spacing: 0.3px;">
                                    <i class="fas fa-info-circle mr-1 text-primary"></i> <?php
                                    $sysMsg = trim($r['message']);
                                    if ($_SESSION['lang'] === 'en') {
                                        if (preg_match('/^Bilet\s+(?:<b>)?(.*?)(?:<\/b>)?\s+(?:personeline|temsilcisine)\s+atandı\.?$/ui', $sysMsg, $m)) {
                                            $sysMsg = "Ticket assigned to <b>" . $m[1] . "</b>.";
                                        } else if (preg_match('/^Biletin\s+ataması\s+kaldırıldı\.?$/ui', $sysMsg)) {
                                            $sysMsg = "Ticket unassigned.";
                                        } else if (preg_match('/^Bilet\s+(?:<b>)?(.*?)(?:<\/b>)?\s+kuyruğından\s+(?:<b>)?(.*?)(?:<\/b>)?\s+kuyruğuna\s+transfer\s+edildi\.?$/ui', $sysMsg, $m)) {
                                            $sysMsg = "Ticket transferred from <b>" . $m[1] . "</b> to <b>" . $m[2] . "</b>.";
                                        } else if (preg_match('/^Bilet\s+(?:<b>)?(.*?)(?:<\/b>)?\s+tarafından\s+yanıtlanmak\s+üzere\s+alındı\s+\(Kilitlendi\)\.?$/ui', $sysMsg, $m)) {
                                            $sysMsg = "Ticket claimed by <b>" . $m[1] . "</b> (Locked).";
                                        } else if (preg_match('/^Bilet\s+yeniden\s+açıldı\.?$/ui', $sysMsg)) {
                                            $sysMsg = "Ticket reopened.";
                                        }
                                    } else if ($_SESSION['lang'] === 'tr') {
                                        if (preg_match('/^Ticket\s+assigned\s+to\s+(?:<b>)?(.*?)(?:<\/b>)?\.?$/ui', $sysMsg, $m)) {
                                            $sysMsg = "Bilet <b>" . $m[1] . "</b> personeline atandı.";
                                        } else if (preg_match('/^Ticket\s+unassigned\.?$/ui', $sysMsg)) {
                                            $sysMsg = "Biletin ataması kaldırıldı.";
                                        } else if (preg_match('/^Ticket\s+transferred\s+from\s+(?:<b>)?(.*?)(?:<\/b>)?\s+to\s+(?:<b>)?(.*?)(?:<\/b>)?\.?$/ui', $sysMsg, $m)) {
                                            $sysMsg = "Bilet <b>" . $m[1] . "</b> kuyruğundan <b>" . $m[2] . "</b> kuyruğuna transfer edildi.";
                                        } else if (preg_match('/^Ticket\s+claimed\s+by\s+(?:<b>)?(.*?)(?:<\/b>)?\s+\(Locked\)\.?$/ui', $sysMsg, $m)) {
                                            $sysMsg = "Bilet <b>" . $m[1] . "</b> tarafından yanıtlanmak üzere alındı (Kilitlendi).";
                                        } else if (preg_match('/^Ticket\s+reopened\.?$/ui', $sysMsg)) {
                                            $sysMsg = "Bilet yeniden açıldı.";
                                        }
                                    }
                                    echo $sysMsg;
                                    ?> &bull; <?= date('H:i', strtotime($r['created_at'])) ?>
                                </span>
                            </div>
                        <?php else: ?>
                            <div class="msg-bubble <?php echo $isMe ? 'me' : ''; ?> <?php echo $isPrivate ? 'private' : ''; ?>">
                                <div class="avatar"
                                    style="background: <?php echo $isMe ? 'transparent' : $color; ?>; <?php echo $isMe ? 'border: 2px solid #667eea; color: #667eea;' : ''; ?>">
                                    <?php echo $initials; ?>
                                </div>
                                <div class="msg-content">
                                <?php if ($isPrivate): ?>
                                    <div class="mb-1"><span class="badge badge-warning" style="font-size:10px"><i
                                                class="fas fa-lock mr-1"></i><?= __("internal_note") ?></span></div>
                                <?php endif; ?>
                                <div class="bubble p-3 shadow-sm">
                                    <div style="word-wrap: break-word;">
                                        <?php
                                        $msg = $r['message'];
                                        $allowedTags = '<p><br><div><span><strong><b><em><i><u><h1><h2><h3><h4><h5><h6><blockquote><ol><ul><li><img><a><table><tr><td><th><tbody><thead>';
                                        // Strip dangerous tags but keep common formatting and images
                                        $msgHtml = sanitizeHtml(strip_tags($msg, $allowedTags));

                                        // Eğer imap_listener '[resim-ek]' placeholder koyduysa, yerine reply'e ait attachment'ı yerleştir
                                        if (strpos($msgHtml, '[resim-ek]') !== false && !empty($r['id'])) {
                                            try {
                                                $attStmt = $pdo->prepare("SELECT file_path, file_name FROM ticket_attachments WHERE reply_id = ? ORDER BY id ASC");
                                                $attStmt->execute([$r['id']]);
                                                $attList = $attStmt->fetchAll(PDO::FETCH_ASSOC);
                                                foreach ($attList as $att) {
                                                    if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $att['file_name'])) {
                                                        $imgUrl = rtrim($base_url, '/') . '/' . ltrim($att['file_path'], '/');
                                                        $imgTag = '<img src="' . htmlspecialchars($imgUrl) . '" style="max-width:400px; height:auto; display:block; margin:8px 0;" />';
                                                        $msgHtml = preg_replace('/\[resim-ek\]/', $imgTag, $msgHtml, 1);
                                                    }
                                                }
                                            } catch (Exception $ex) {
                                                // attachment lookup failed - silently ignore
                                            }
                                        }

                                        echo fixImagePaths($msgHtml, $base_url);
                                        ?>
                                    </div>
                                </div>
                                <?php if (!empty($replyAttachmentsMap[$r['id']])): ?>
                                    <div class="mt-2 p-2 bg-light rounded border border-light shadow-sm" style="width: fit-content;">
                                        <p class="text-sm text-muted mb-1 font-weight-bold" style="font-size:12px;">
                                            <i class="fas fa-paperclip mr-1 text-primary"></i><?= __("attachments") ?>:
                                        </p>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($replyAttachmentsMap[$r['id']] as $att): ?>
                                                <?php
                                                $fpath = $att['file_path'];
                                                if (strpos($fpath, 'public/') === 0) {
                                                    $fpath = substr($fpath, 7);
                                                }
                                                ?>
                                                <a href="<?= $base_url ?><?php echo htmlspecialchars($fpath); ?>" target="_blank"
                                                    class="btn btn-sm btn-outline-secondary bg-white" style="font-size:12px;">
                                                    <i class="fas fa-download mr-1 text-primary"></i>
                                                    <?php echo htmlspecialchars($att['file_name']); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="msg-meta">
                                    <strong>
                                        <?php echo htmlspecialchars($r['fullname']); ?>
                                    </strong>
                                    &bull;
                                    <?php echo date('d.m.Y H:i', strtotime($r['created_at'])); ?>
                                    <?php
                                    $diff = time() - strtotime($r['created_at']);
                                    if ($diff < 60)
                                        $age = $diff . ' ' . __("seconds_ago");
                                    elseif ($diff < 3600)
                                        $age = floor($diff / 60) . ' ' . __("minutes_ago");
                                    elseif ($diff < 86400)
                                        $age = floor($diff / 3600) . ' ' . __("hours_ago");
                                    else
                                        $age = floor($diff / 86400) . ' ' . __("days_ago");
                                    ?>
                                    <span class="ml-1 text-muted" style="font-size:10px;"><i class="far fa-clock"></i>
                                        <?php echo $age; ?></span>
                                    <?php if (!empty($r['time_spent_minutes']) && $r['time_spent_minutes'] > 0): ?>
                                        &bull; <i class="fas fa-stopwatch mr-1"></i><?php echo $r['time_spent_minutes']; ?>
                                        <?= __("min_short") ?>
                                        <?= __("time_spent") ?>
                                    <?php endif; ?>
                                </div> <!-- end msg-meta -->
                            </div> <!-- end msg-content -->
                        </div> <!-- end msg-bubble -->
                        <?php endif; // isSystem ?>
                    <?php endforeach; ?>

                    <?php if (empty($replies)): ?>
                        <div class="text-center text-muted py-3 mt-2" style="font-size: 13px;">
                            <i class="fas fa-info-circle mr-1"></i> <?= __("no_replies_yet_msg") ?>
                        </div>
                    <?php endif; ?>
                </div>

                 <!-- Reply Box (Hidden until "Reply" is clicked) -->
                <?php if (in_array($current_user_role, [1, 3, 2])): ?>
                <?php
                $isTicketLocked = !empty($ticket['locked_by']) && (int) $ticket['locked_by'] > 0 && (time() - strtotime($ticket['locked_at'])) < 28800;
                $isLockedByMe = $isTicketLocked && (int) $ticket['locked_by'] === (int) $current_user_id;
                ?>
                <?php
                // Reply editor should be visible ONLY when the current user holds the lock (for Agents)
                // or directly visible if they are a regular Personnel (Role 2) and the ticket is open.
                $isClosedOrResolved = in_array($ticket['status'], ['closed', 'resolved']);
                $isAssignedToMe = (int)$ticketPersonnelId === (int)$current_user_id;
                if ($current_user_role == 2) {
                    $showReply = !$isClosedOrResolved;
                } else {
                    $showReply = !$isClosedOrResolved && ($isLockedByMe || $isAssignedToMe);
                }
                ?>
                <?php if ($isClosedOrResolved): ?>
                    <?php if (!empty($ticketRating)): ?>
                        <!-- Rating Display (For Everyone) -->
                        <div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: 12px; background: #f8fafc; border-left: 5px solid #28a745;">
                            <h6 class="font-weight-bold text-success mb-2"><i class="fas fa-check-circle mr-2"></i><?= $isTr ? 'Bilet Değerlendirilmiştir' : 'Ticket Rated' ?></h6>
                            <div class="d-flex align-items-center mb-2">
                                <span class="mr-3 font-weight-bold"><?= $isTr ? 'Verilen Puan:' : 'Rating Given:' ?></span>
                                <span class="text-warning font-weight-bold" style="font-size: 1.25rem;">
                                    <?= str_repeat('★', $ticketRating['rating']) ?><?= str_repeat('☆', 5 - $ticketRating['rating']) ?>
                                </span>
                            </div>
                            <?php if (!empty($ticketRating['comment'])): ?>
                                <p class="mb-0 text-muted small"><strong><?= $isTr ? 'Müşteri Yorumu:' : 'Customer Comment:' ?></strong> "<?= htmlspecialchars($ticketRating['comment']) ?>"</p>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($current_user_role == 2 && ((int)$ticket['creator_id'] === (int)$current_user_id || (int)$ticket['customer_id'] === (int)$current_user_id)): ?>
                        <!-- Rating Form (Only for Creator/Customer) -->
                        <div class="card border-0 shadow-sm p-4 mb-4" style="border-radius: 12px; background: #fffdf5; border-left: 5px solid #ffc107;">
                            <h6 class="font-weight-bold text-dark mb-2"><i class="fas fa-star text-warning mr-2"></i><?= $isTr ? 'Bu Bilet Çözümlenmiştir. Lütfen Değerlendirin' : 'This Ticket is Resolved. Please Rate Our Service' ?></h6>
                            <form method="POST" action="">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="rate_ticket">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold text-muted small d-block"><?= $isTr ? 'Hizmet Kalitesi' : 'Service Quality' ?></label>
                                    <div class="rating-stars-wrapper" style="font-size: 1.5rem; cursor: pointer; color: #cbd5e1;">
                                        <span class="star-rating-item text-secondary mr-1" data-value="1">★</span>
                                        <span class="star-rating-item text-secondary mr-1" data-value="2">★</span>
                                        <span class="star-rating-item text-secondary mr-1" data-value="3">★</span>
                                        <span class="star-rating-item text-secondary mr-1" data-value="4">★</span>
                                        <span class="star-rating-item text-secondary mr-1" data-value="5">★</span>
                                    </div>
                                    <input type="hidden" name="rating" id="ratingValueInput" value="5" required>
                                </div>
                                <div class="form-group mb-3 mt-2">
                                    <label class="font-weight-bold text-muted small" for="ratingComment"><?= $isTr ? 'Görüş ve Önerileriniz' : 'Your Comments & Suggestions' ?></label>
                                    <textarea class="form-control" name="comment" id="ratingComment" rows="2" placeholder="<?= $isTr ? 'Destek ekibimiz hakkında görüşlerinizi yazın...' : 'Write your feedback...' ?>"></textarea>
                                </div>
                                <button type="submit" class="btn btn-warning btn-sm font-weight-bold text-dark px-4 shadow-sm" style="border-radius: 8px;">
                                    <i class="fas fa-paper-plane mr-1"></i><?= $isTr ? 'Değerlendirmeyi Gönder' : 'Submit Feedback' ?>
                                </button>
                            </form>
                        </div>
                        <style>
                            .star-rating-item {
                                transition: color 0.15s, transform 0.15s;
                                display: inline-block;
                            }
                            .star-rating-item:hover {
                                transform: scale(1.2);
                            }
                            .star-rating-item.active {
                                color: #ffc107 !important;
                            }
                        </style>
                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                const stars = document.querySelectorAll('.star-rating-item');
                                const input = document.getElementById('ratingValueInput');
                                
                                function highlightStars(val) {
                                    stars.forEach(s => {
                                        if (parseInt(s.getAttribute('data-value')) <= val) {
                                            s.classList.add('active');
                                            s.classList.remove('text-secondary');
                                        } else {
                                            s.classList.remove('active');
                                            s.classList.add('text-secondary');
                                        }
                                    });
                                }
                                
                                // Default highlight 5 stars
                                highlightStars(5);
                                
                                stars.forEach(s => {
                                    s.addEventListener('mouseover', function() {
                                        highlightStars(parseInt(this.getAttribute('data-value')));
                                    });
                                    s.addEventListener('mouseout', function() {
                                        highlightStars(parseInt(input.value));
                                    });
                                    s.addEventListener('click', function() {
                                        input.value = this.getAttribute('data-value');
                                        highlightStars(parseInt(input.value));
                                    });
                                });
                            });
                        </script>
                    <?php else: ?>
                        <!-- Unrated alert for Agents/Admins -->
                        <div class="alert alert-light border shadow-sm p-3 mb-4" style="border-radius: 10px; font-size:13.5px; border-left: 4px solid #6c757d;">
                            <i class="fas fa-info-circle text-secondary mr-2"></i><?= $isTr ? 'Bu bilet çözümlenmiştir. Müşteri henüz değerlendirme yapmamıştır.' : 'This ticket is resolved. No customer feedback has been submitted yet.' ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="reply-box position-relative" id="replyBoxContainer"
                    style="display: <?= $showReply ? 'block' : 'none' ?>;">
                    <!-- Kilit / Çakışma Uyarısı -->
                    <div id="ticketLockWarning" class="alert alert-warning py-2 px-3 mb-2 shadow-sm d-none"
                        style="font-size:13px; border-radius:8px; border-left:4px solid #ffc107;">
                        <i class="fas fa-exclamation-triangle mr-1 text-warning"></i>
                        <strong id="lockUserName"></strong> <?= __("others_typing_msg") ?>
                    </div>

                    <form method="POST" id="replyForm" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" id="actionInput" value="reply">

                        <!-- Live Typing Indicator -->
                        <div id="live-typing-indicator" class="alert alert-info py-2 px-3 mb-2 shadow-sm d-none" style="font-size:12.5px; border-radius:8px;">
                            <i class="fas fa-pencil-alt fa-spin mr-1"></i><span></span>
                        </div>

                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center flex-wrap" style="background-color: #f8fafc;">
                            <div>
                                <h6 class="m-0 font-weight-bold" style="color: #334155;"><i class="fas fa-reply mr-2 text-primary"></i><?= __("create_reply") ?></h6>
                            </div>
                            <div class="d-flex gap-2 mt-2 mt-md-0 align-items-center">
                                <!-- Hazır Yanıt Şablonu Seçimi (Tüm Roller İçin) -->
                                <?php if (!empty($cannedResponses)): ?>
                                <div class="d-flex align-items-center shadow-sm bg-white border rounded px-3 py-1 mr-2" style="border-color: #cbd5e1;">
                                    <i class="fas fa-bolt text-warning mr-2"></i>
                                    <select id="cannedResponseSelect" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0" style="min-width:150px; cursor: pointer; font-weight: 500;" onchange="insertCannedResponse(this.value)">
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

                                <?php if (in_array($current_user_role, [1, 3])): ?>
                                <div class="d-flex align-items-center shadow-sm bg-white border rounded px-3 py-1" style="border-color: #cbd5e1;">
                                    <span class="text-muted mr-2" style="font-size:13px; font-weight:600;"><?= __("final_status") ?>:</span>
                                    <select name="status" id="statusSelect" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0" style="min-width:100px; cursor: pointer; font-weight: 500;">
                                        <option value="" disabled><?= __("select_status") ?? '-- Durum Seçiniz --' ?></option>
                                        <?php foreach ($statusLabels as $sv => $sl): ?>
                                            <option value="<?php echo $sv; ?>" <?php echo ($ticket['status'] == $sv) ? 'selected' : ''; ?>><?php echo $sl; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="assigneeSelectWrapper" class="d-flex align-items-center shadow-sm bg-white border rounded px-3 py-1 ml-2" style="border-color: #cbd5e1; display: none !important; transition: all 0.3s ease;">
                                    <span class="text-muted mr-2" style="font-size:13px; font-weight:600;"><?= $isTr ? 'Atanacak Kişi' : 'Assignee' ?>:</span>
                                    <select name="assignee_id" id="assigneeSelect" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0" style="min-width:120px; cursor: pointer; font-weight: 500;">
                                        <option value="" disabled <?php echo empty($ticketPersonnelId) ? 'selected' : ''; ?>><?= $isTr ? '-- Temsilci Seçin --' : '-- Select Agent --' ?></option>
                                        <?php foreach ($allStaffMembers as $tm): ?>
                                            <option value="<?php echo $tm['id']; ?>" <?php echo ($ticketPersonnelId == $tm['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['fullname']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <input type="hidden" name="is_private" id="isPrivateInput" value="0">
                        <div class="bg-white">
                            <textarea name="message" id="summernoteReply"></textarea>
                        </div>

                        <div class="px-3 py-2 border-top bg-light" style="font-size: 13px;">
                            <label class="m-0 text-muted font-weight-bold"><i class="fas fa-paperclip mr-1"></i>
                                <?= __("attachments") ?? 'Dosya Ekleri' ?></label>
                            <div class="custom-file mt-1">
                                <input type="file" name="attachments[]" class="custom-file-input" id="replyAttachments" multiple onchange="updateFileNames()">
                                <label class="custom-file-label" for="replyAttachments" id="replyAttachmentsLabel" data-browse="<?= $isTr ? 'Dosya Seç' : 'Browse' ?>" style="font-weight: 500; cursor: pointer; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; padding-right: 80px;">
                                    <?= $isTr ? 'Dosyaları buraya bırakın veya tıklayın...' : 'Drop files here or click to choose...' ?>
                                </label>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center p-3 bg-light border-top"
                            style="background-color: #f8fafc;">
                            <div class="text-sm text-muted d-flex align-items-center gap-2">
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit"
                                    class="btn btn-success btn-lg rounded-pill px-6 shadow-lg font-weight-bold"
                                    id="sendBtn"
                                    onclick="document.getElementById('actionInput').value='reply'; return true;"
                                    style="min-width: 180px; font-size: 16px;">
                                    <i class="fas fa-paper-plane mr-2"></i> <?= __("send") ?? 'Gönder' ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                <?php if ($isClosedOrResolved): ?>
                    <div class="resolved-card mt-3" id="resolvedCardContainer"
                        style="display: <?= $showReply ? 'none' : 'block' ?>;">
                        <div class="resolved-icon-badge">
                            <i class="fas fa-check"></i>
                        </div>
                        <h5>
                            <?= __("ticket_resolved_status") ?>
                        </h5>
                        <p><?= __("ticket_resolved_desc") ?></p>
                        <form method="POST" class="mt-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reopen_ticket">
                            <button type="submit" class="btn btn-reopen-ticket">
                                <i class="fas fa-redo mr-1"></i> <?= __("reopen_ticket_btn") ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div> <!-- end chat-container -->
        </div> <!-- end main-content -->
    </div> <!-- end view-col-main -->

        <!-- SAĞ SÜTUN: Sidebar (Info, Actions, SLA) -->
        <div class="view-col-sidebar">
            <?php
            $sidebarCustId = ($ticket['customer_id'] > 0) ? $ticket['customer_id'] : ($ticket['linked_customer_id'] ?? 0);
            $orgId = ($ticket['organization_id'] > 0) ? $ticket['organization_id'] : 0;

            // 1. Zaten kapali bilet otomatik kilidir. 
            // 2. Kilidi olan ve zaman aşımına uğramamış bilet kilitlidir.
            $isLocked = ($ticket['status'] === 'closed' || $ticket['status'] === 'resolved') || 
                        (!empty($ticket['locked_by']) && (int) $ticket['locked_by'] > 0 && (time() - strtotime($ticket['locked_at'])) < 28800);

            $detayId = ($ticket['customer_id'] > 0) ? $ticket['customer_id'] : ($ticket['linked_customer_id'] ?? 0);
            
            // Authorization for unlock: Admin (1), Team Leader (3), or First Responder
            $isAuthorizedToUnlock = in_array($current_user_role, [1, 3]) || ($first_responder_id === (int) $current_user_id && $first_responder_id > 0);
            ?>

            <!-- İşlemler (EN ÜSTTE) -->
            <style>
                .ds-action-btn {
                    display: flex; align-items: center; padding: 10px 14px;
                    border-radius: 8px; font-weight: 600; font-size: 13px;
                    text-decoration: none; border: 1px solid transparent;
                    transition: all 0.2s; background: #f8fafc; color: #475569;
                }
                .ds-action-btn:hover { background: #e2e8f0; color: #1e293b; text-decoration:none; }
                .ds-action-lock.is-locked { background: #fffbeb; color: #d97706; border-color: #fde68a; }
                .ds-action-lock.is-locked:hover { background: #fef3c7; color: #b45309; }
                .ds-action-lock.is-unlocked { background: #f8fafc; color: #4b5563; border-color: transparent; }
                .ds-action-lock.is-unlocked:hover { background: #e2e8f0; color: #1f2937; }
                
                .ds-action-info { color: #0284c7; background: #e0f2fe; }
                .ds-action-info:hover { background: #bae6fd; color: #0369a1; }

                .ds-action-delete { color: #dc2626; background: #fee2e2; }
                .ds-action-delete:hover { background: #fecaca; color: #b91c1c; }

                /* Dark Mode Override */
                body.dark-mode .ds-action-btn { background: #2d3748; color: #a0aec0; border-color: transparent; }
                body.dark-mode .ds-action-btn:hover { background: #4a5568; color: #fff; }
                
                body.dark-mode .ds-action-lock.is-locked { background: #78350f; color: #fcd34d; border-color: transparent; }
                body.dark-mode .ds-action-lock.is-locked:hover { background: #92400e; color: #fde68a; }
                body.dark-mode .ds-action-lock.is-unlocked { background: #2d3748; color: #e2e8f0; }
                body.dark-mode .ds-action-lock.is-unlocked:hover { background: #4a5568; color: #fff; }

                body.dark-mode .ds-action-info { background: #0c4a6e; color: #7dd3fc; }
                body.dark-mode .ds-action-info:hover { background: #075985; color: #bae6fd; }

                body.dark-mode .ds-action-delete { background: #7f1d1d; color: #fca5a5; }
                body.dark-mode .ds-action-delete:hover { background: #991b1b; color: #fecaca; }
                body.dark-mode .card.border-0 { border: 1px solid #3a424a !important; background: #1f2937; }
            </style>
            <div class="card border-0 shadow-sm" style="border-radius:12px; margin-bottom:20px;">
                <div class="card-body p-2 d-flex flex-column gap-2">
                    <a href="biletler" class="ds-action-btn w-100 border-0 text-left">
                        <i class="fas fa-arrow-left mr-2" style="width:20px; text-align:center;"></i> <?= __("all_tickets") ?>
                    </a>
                    <?php 
                        $btnLang = function($key, $def) { $r = __($key); return ($r === $key || empty($r)) ? $def : $r; };
                    ?>
                    
                    <?php if ($isAuthorizedToUnlock): ?>
                        <button id="adminLockBtn" type="button"
                            class="ds-action-btn ds-action-lock w-100 text-left border-0 <?= $isLocked ? 'is-locked' : 'is-unlocked' ?>"
                            data-locked="<?= $isLocked ? '1' : '0' ?>">
                            <i class="fas fa-<?= $isLocked ? 'lock' : 'unlock' ?> mr-2" style="width:20px; text-align:center;"></i>
                            <?= $isLocked ? $btnLang("unlock_ticket_btn", "Kilidi Aç") : $btnLang("lock_ticket_btn", "Bileti Kilitle") ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($current_user_role == 1): ?>
                        <button type="button" class="ds-action-btn ds-action-delete w-100 text-left border-0"
                            onclick="confirmDeleteTicket()">
                            <i class="fas fa-trash-alt mr-2" style="width:20px; text-align:center;"></i> <?= $btnLang("delete_ticket_btn", "Bileti Sil") ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>


            <!-- Bilet Bilgileri -->
            <div class="card border-0 shadow-sm" style="border-radius:12px;">
                <div class="card-header py-3"><i
                        class="fas fa-info-circle mr-2 text-primary"></i> <span
                        class="font-weight-bold"><?= __("ticket_info") ?></span></div>
                <div class="card-body p-3">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="info-label"><?= __("ticket_no") ?></td>
                            <td class="info-value"><span class="badge badge-light px-2"
                                     style="font-family:monospace;"><?php echo $ticket['ticket_no']; ?></span></td>
                        </tr>
                        <tr>
                            <td class="info-label"><?= __("queue") ?></td>
                            <td class="info-value"><?php echo htmlspecialchars(!$isTr && ($ticket['queue_name'] ?? '') === 'Genel Kuyruk' ? 'General Queue' : ($ticket['queue_name'] ?? '-')); ?></td>
                        </tr>
                        <?php if ($ticket['organization_id'] > 0): ?>
                        <tr>
                            <td class="info-label"><?= __("organization") ?: 'Organizasyon' ?></td>
                            <td class="info-value">
                                <a href="organizasyonlar?q=<?= urlencode($ticket['organization_name']) ?>" class="info-org-link" style="font-weight: 700; text-decoration: none;">
                                    <i class="fas fa-building mr-1"></i><?= htmlspecialchars($ticket['organization_name']) ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="info-label"><?= __("contact") ?: 'İletişim' ?></td>
                            <td class="info-value">
                                <?php if ($sidebarCustId > 0): ?>
                                    <a href="musteri-detay/<?= $sidebarCustId ?>" class="info-contact-link" style="font-weight: 600; text-decoration: none;">
                                        <i class="fas fa-user-circle mr-1"></i><?= htmlspecialchars($ticket['customer_name'] ?: $ticket['creator_name']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted"><?= htmlspecialchars($ticket['creator_name']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="info-label"><?= __("status") ?></td>
                            <td class="info-value">
                                <span class="badge badge-pill"
                                    style="background: <?php echo $statusColors[$ticket['status']] ?? '#eee'; ?>; color: #fff; font-size: 10px; padding: 4px 10px;">
                                    <?php echo $statusLabels[$ticket['status']] ?? $ticket['status']; ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="info-label"><?= $isTr ? 'DÜZENLEME KİLİDİ' : 'EDIT LOCK' ?></td>
                            <td class="info-value">
                                <?php 
                                $showLockedBadge = $isTicketLocked || in_array($ticket['status'], ['closed', 'resolved']);
                                $lockedUserText = !empty($ticket['locked_by_name']) ? $ticket['locked_by_name'] : (!empty($ticket['agent_name']) ? $ticket['agent_name'] : ($_SESSION['fullname'] ?? '—'));
                                ?>
                                <?php if ($showLockedBadge): ?>
                                    <span id="lockStatusBadge" class="lock-status-locked">
                                        <i class="fas fa-lock mr-1"></i>
                                        <?= $isTr ? 'Kilitli' : 'Locked' ?>: <?= htmlspecialchars($lockedUserText) ?>
                                    </span>
                                <?php else: ?>
                                    <span id="lockStatusBadge" class="lock-status-unlocked">
                                        <i class="fas fa-unlock mr-1"></i> <?= $isTr ? 'Kilidi Açık' : 'Unlocked' ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!empty($ticket['is_forwarded'])): ?>
                        <tr>
                            <td class="info-label"><?= $isTr ? 'YÖNLENDİREN' : 'FORWARDED BY' ?></td>
                            <td class="info-value">
                                <span class="forwarder-badge" style="padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-block; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($ticket['forwarder_name'] ?: $ticket['forwarder_email']) ?> (<?= htmlspecialchars($ticket['forwarder_email']) ?>)">
                                    <i class="fas fa-share mr-1"></i> <?= htmlspecialchars($ticket['forwarder_name'] ?: $ticket['forwarder_email']) ?>
                                </span>
                                <style>
                                    .forwarder-badge {
                                        background: #e0f2fe;
                                        color: #0369a1;
                                    }
                                    body.dark-mode .forwarder-badge {
                                        background: #074e8c;
                                        color: #bae6fd;
                                    }
                                </style>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="info-label"><?= __("assigned_to") ?></td>
                            <td class="info-value">
                                <div class="d-flex justify-content-between align-items-center">
                                    <?php if ($ticket['agent_name'] || ($lastReply['fullname'] ?? null)): ?>
                                        <div class="d-flex flex-column">
                                            <div class="d-flex align-items-center">
                                                <span class="assigned-agent-name" style="font-weight: 700;">
                                                    <?php echo htmlspecialchars($ticket['agent_name'] ?: ($lastReply['fullname'] ?? '—')); ?>
                                                </span>
                                                <?php if ($isLocked): ?>
                                                    <i class="fas fa-lock ml-2 text-danger" title="<?= $isTr ? 'Kilitli' : 'Locked' ?>: <?= htmlspecialchars($ticket['locked_by_name'] ?? '') ?>" style="font-size:10px;"></i>
                                                <?php endif; ?>
                                            </div>
                                            <!-- Last update time -->
                                            <span class="text-muted" style="font-size:11px;">
                                                <i class="far fa-clock mr-1"></i> Son İşlem: <?= date('d.m.Y H:i', strtotime($ticket['update_date'] ?? $ticket['create_date'])) ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-weight:600;">—</span>
                                    <?php endif; ?>

                                    <div class="d-flex gap-1">

                                        
                                        <?php if (in_array($current_user_role, [1, 2, 3]) && hasPermission('biletler_transfer')): ?>
                                            <button type="button" class="btn btn-sm btn-link p-0 text-warning ml-2" data-toggle="modal"
                                                data-target="#transferModal" title="<?= __("transfer") ?>"><i class="fas fa-exchange-alt"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td class="info-label"><?= __("sla_progress") ?></td>
                            <td class="info-value">
                                <?php if ($slaPercent !== null): 
                                    // Dynamic color based on percentage
                                    $slaColor = '#10b981'; // Green (default)
                                    if ($slaPercent >= 90) $slaColor = '#ef4444'; // Red
                                    elseif ($slaPercent >= 75) $slaColor = '#f97316'; // Orange
                                    elseif ($slaPercent >= 50) $slaColor = '#f59e0b'; // Yellow
                                ?>
                                    <div class="d-flex align-items-center" style="min-width:120px;">
                                        <div class="progress flex-grow-1 mr-2" style="height: 6px; border-radius: 10px; background: #f1f5f9;">
                                            <div class="progress-bar" style="width: <?= round($slaPercent) ?>%; background: <?= $slaColor ?>; transition: width 0.6s ease, background-color 0.6s ease;"></div>
                                        </div>
                                        <span class="font-weight-bold" style="font-size:11px;"><?= round($slaPercent) ?>%</span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Alt Görevler (Subtasks) -->
            <?php if (hasPermission('biletler_add_subtask')): ?>
            <div class="card border-0 shadow-sm mt-3" style="border-radius:12px;">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-tasks mr-2 text-primary"></i>
                        <span class="font-weight-bold"><?= $isTr ? 'Alt Görevler' : 'Subtasks' ?></span>
                    </div>
                    <?php if (!in_array($ticket['status'], ['closed', 'resolved'])): ?>
                    <button type="button" class="btn btn-sm btn-link p-0" onclick="$('#subtaskInputRow').toggle()"><i class="fas fa-plus"></i></button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-3">
                    <div id="subtaskInputRow" style="display:none; margin-bottom:15px;">
                        <div class="input-group input-group-sm">
                            <input type="text" id="newSubtaskText" class="form-control" placeholder="<?= $isTr ? 'Yeni görev...' : 'New task...' ?>">
                            <div class="input-group-append">
                                <button class="btn btn-primary" type="button" onclick="addSubtask()"><?= $isTr ? 'Ekle' : 'Add' ?></button>
                            </div>
                        </div>
                    </div>
                    
                    <ul class="list-group list-group-flush" id="subtaskList">
                        <?php if (empty($subtasks)): ?>
                            <li class="list-group-item text-center text-muted px-0 py-2 border-0" id="noSubtasksLi">
                                <small><?= $isTr ? 'Henüz alt görev yok.' : 'No subtasks yet.' ?></small>
                            </li>
                        <?php else: foreach ($subtasks as $st): ?>
                            <li class="list-group-item px-0 py-2 border-0 d-flex justify-content-between align-items-start" id="st-<?= $st['id'] ?>">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="chk-<?= $st['id'] ?>" <?= $st['is_completed'] ? 'checked' : '' ?> onchange="toggleSubtask(<?= $st['id'] ?>, this.checked)" <?= in_array($ticket['status'], ['closed', 'resolved']) ? 'disabled' : '' ?>>
                                    <label class="custom-control-label" for="chk-<?= $st['id'] ?>" style="font-size:13px; line-height:1.4; <?= $st['is_completed'] ? 'text-decoration:line-through; color:#9ca3af;' : '' ?>">
                                        <?= htmlspecialchars($st['task_text']) ?>
                                    </label>
                                </div>
                                <?php if (!in_array($ticket['status'], ['closed', 'resolved'])): ?>
                                <button class="btn btn-sm btn-link text-danger p-0 ml-2" onclick="deleteSubtask(<?= $st['id'] ?>)"><i class="fas fa-times"></i></button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Efor Takibi (Time Tracking) -->
            <?php if (hasPermission('biletler_add_effort')): ?>
            <div class="card border-0 shadow-sm mt-3" style="border-radius:12px;">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-stopwatch mr-2 text-warning"></i>
                        <span class="font-weight-bold"><?= $isTr ? 'Efor Takibi' : 'Time Tracking' ?></span>
                    </div>
                    <?php $timeStrTotal = $isTr ? "{$totalHours}s {$remainingMins}d" : "{$totalHours}h {$remainingMins}m"; ?>
                    <span class="badge badge-warning text-white" style="font-size:12px;"><?= $timeStrTotal ?></span>
                </div>
                <div class="card-body p-3">
                    <?php if (!in_array($ticket['status'], ['closed', 'resolved'])): ?>
                    <button type="button" class="btn btn-sm btn-outline-warning w-100 mb-3" data-toggle="modal" data-target="#addTimeModal">
                        <i class="fas fa-plus mr-1"></i> <?= $isTr ? 'Efor Ekle' : 'Log Time' ?>
                    </button>
                    <?php endif; ?>
                    
                    <ul class="list-group list-group-flush" style="max-height: 200px; overflow-y:auto;">
                        <?php if (empty($timeLogs)): ?>
                            <li class="list-group-item text-center text-muted px-0 border-0"><small><?= $isTr ? 'Henüz efor girilmemiş.' : 'No time logged yet.' ?></small></li>
                        <?php else: foreach ($timeLogs as $tl): 
                            $h = floor($tl['time_spent_minutes'] / 60);
                            $m = $tl['time_spent_minutes'] % 60;
                            $timeStrItem = $isTr ? "{$h}s {$m}d" : "{$h}h {$m}m";
                        ?>
                            <li class="list-group-item px-0 py-2 border-bottom" style="font-size:12px;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="text-dark"><?= htmlspecialchars($tl['fullname']) ?></strong>
                                    <span class="badge badge-light border"><?= $timeStrItem ?></span>
                                </div>
                                <div class="text-muted d-flex justify-content-between">
                                    <span><?= htmlspecialchars($tl['note']) ?></span>
                                    <?php if ($tl['user_id'] == $current_user_id || $current_user_role == 1): ?>
                                    <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteTimeLog(<?= $tl['id'] ?>)"><i class="fas fa-trash"></i></button>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            </div> <!-- end view-col-sidebar -->
        </div> <!-- end ticket-view-main -->

    <!-- Assign Modal cont. -->
    <?php if (in_array($current_user_role, [1, 2, 3])): ?>
        <div class="modal fade" id="assignModal" tabindex="-1" role="dialog" aria-labelledby="assignModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <form method="POST" class="modal-content shadow-lg border-0" style="border-radius: 12px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="modal_assign">
                    <div class="modal-header border-bottom-0 pb-0">
                        <h5 class="modal-title font-weight-bold" id="assignModalLabel"><i
                                class="fas fa-user-tag text-primary mr-2"></i><?= __("assign_ticket_title") ?></h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="<?= __("close") ?>">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body py-4">
                        <p class="text-muted text-sm mb-3"><?= __("assign_modal_hint") ?></p>
                        <div class="form-group mb-0">
                                <select name="personnel_id" class="form-control form-control-lg bg-light"
                                    style="border-radius:8px;">
                                    <?php if (empty($teamMembers)): ?>
                                        <option value="" disabled selected><?= $isTr ? '-- Uygun Temsilci Bulunamadı --' : '-- No Available Representatives --' ?></option>
                                    <?php else: ?>
                                        <option value="" selected disabled><?= $isTr ? '-- Temsilci Seçiniz --' : '-- Select Representative --' ?></option>
                                        <?php foreach ($teamMembers as $tm): ?>
                                            <option value="<?php echo $tm['id']; ?>">
                                                <?php echo htmlspecialchars($tm['fullname']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="0">--- <?= __("unassign") ?> ---</option>
                                    <?php endif; ?>
                                </select>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-light" data-dismiss="modal"><?= __("cancel") ?></button>
                        <button type="submit" class="btn btn-primary px-4 shadow-sm"><i class="fas fa-save mr-1"></i>
                            <?= __("assign_and_notify") ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Transfer Modal -->
        <div class="modal fade" id="transferModal" tabindex="-1" role="dialog" aria-labelledby="transferModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <form method="POST" class="modal-content shadow-lg border-0" style="border-radius: 12px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="modal_transfer">
                    <div class="modal-header border-bottom-0 pb-0">
                        <h5 class="modal-title font-weight-bold" id="transferModalLabel"><i
                                class="fas fa-exchange-alt text-warning mr-2"></i><?= __("transfer_queue") ?></h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="<?= __("close") ?>">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body py-4">
                        <p class="text-muted text-sm mb-3"><?= __("transfer_modal_hint") ?></p>
                        <div class="form-group mb-0">
                            <label class="info-label"><?= __("select_target_queue") ?></label>
                            <select name="queue_id" class="form-control form-control-lg bg-light"
                                style="border-radius:8px;">
                                <?php foreach ($allQueues as $q): ?>
                                    <option value="<?php echo $q['id']; ?>" <?php echo $ticket['queue_id'] == $q['id'] ? 'selected' : ''; ?>>
                                        <?php 
                                            $qName = !$isTr && $q['name'] === 'Genel Kuyruk' ? 'General Queue' : $q['name'];
                                            $tName = !$isTr && $q['team_name'] === 'Genel Takım' ? 'General Team' : $q['team_name'];
                                            echo htmlspecialchars($qName) . ' (' . htmlspecialchars($tName ?: '-') . ')'; 
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-light" data-dismiss="modal"><?= __("cancel") ?></button>
                        <button type="submit" class="btn btn-warning px-4 shadow-sm font-weight-bold"><i class="fas fa-check mr-1"></i>
                            <?= __("transfer") ?: 'Transfer Et' ?></button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
    <script>
        <?php
        $parse_ini_size = function($val) {
            $val = trim($val);
            if (empty($val)) return 0;
            $last = strtolower($val[strlen($val)-1]);
            $val = (int)$val;
            switch($last) {
                case 'g': $val *= 1024;
                case 'm': $val *= 1024;
                case 'k': $val *= 1024;
            }
            return $val * 1024; // Return bytes
        };
        $js_upload_max = $parse_ini_size(ini_get('upload_max_filesize'));
        $js_post_max = $parse_ini_size(ini_get('post_max_size'));
        $upload_max_text = ini_get('upload_max_filesize');
        ?>
        const PHP_UPLOAD_MAX_SIZE = <?= (int)$js_upload_max ?>;
        const PHP_POST_MAX_SIZE = <?= (int)$js_post_max ?>;
        const PHP_UPLOAD_MAX_TEXT = <?= json_encode($upload_max_text) ?>;

        // SweetAlert2 global dark-mode support wrapper
        if (typeof Swal !== 'undefined') {
            const originalSwalFire = Swal.fire;
            Swal.fire = function(options) {
                const isDark = document.body.classList.contains('dark-mode');
                const darkDefaults = {
                    background: isDark ? '#1e293b' : '#fff',
                    color: isDark ? '#f1f5f9' : '#1e293b',
                    confirmButtonColor: '#3b82f6'
                };
                const mergedOptions = Object.assign({}, darkDefaults, options);
                return originalSwalFire.call(Swal, mergedOptions);
            };
        }

        if (window.EaprimusRealtime) {
            window.EaprimusRealtime.activeTicketId = <?= intval($ticketId) ?>;
        }



        // Global live typing listener for Summernote, native textareas, and contenteditables
        $(document).on('keyup input', '#summernoteReply, .note-editable, textarea[name="message"]', function() {
            if (window.EaprimusRealtime) {
                window.EaprimusRealtime.broadcastTyping();
                if (window.typingTimeout) clearTimeout(window.typingTimeout);
                window.typingTimeout = setTimeout(function() {
                    if (window.EaprimusRealtime) window.EaprimusRealtime.stopTyping();
                }, 800);
            }
        });

        let summernoteReply = null;
        let isRestoringDraft = false;
        if (document.getElementById('summernoteReply')) {
            $('#summernoteReply').summernote({
                placeholder: '<?= __("reply_placeholder") ?>',
                tabsize: 2,
                height: 150,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture']],
                    ['view', ['fullscreen', 'codeview']]
                ],
                callbacks: {
                    onInit: function() {
                        summernoteReply = true;
                        // Load draft
                        const draftKey = 'ticket_draft_' + <?= intval($ticketId) ?> + '_' + <?= intval($current_user_id) ?>;
                        const draft = localStorage.getItem(draftKey);
                        if (draft) {
                            isRestoringDraft = true;
                            $('#summernoteReply').summernote('code', draft);
                            isRestoringDraft = false;
                        }
                    },
                    onFocus: function() {
                        requestLock();
                    },
                    onKeyup: function() {
                        if (window.EaprimusRealtime) {
                            window.EaprimusRealtime.broadcastTyping();
                            if (window.typingTimeout) clearTimeout(window.typingTimeout);
                            window.typingTimeout = setTimeout(function() {
                                if (window.EaprimusRealtime) window.EaprimusRealtime.stopTyping();
                            }, 800);
                        }
                    },
                    onBlur: function() {
                        if (window.typingTimeout) clearTimeout(window.typingTimeout);
                        if (window.EaprimusRealtime) {
                            window.EaprimusRealtime.stopTyping();
                        }
                    },
                    onChange: function(contents, $editable) {
                        if (isRestoringDraft) return;
                        requestLock();
                        // Save draft
                        const draftKey = 'ticket_draft_' + <?= intval($ticketId) ?> + '_' + <?= intval($current_user_id) ?>;
                        const textVal = contents.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim();
                        if (textVal.length === 0 && contents.indexOf('<img') === -1 && contents.indexOf('<table') === -1) {
                            localStorage.removeItem(draftKey);
                        } else {
                            localStorage.setItem(draftKey, contents);
                        }
                    }
                }
            });
        }

        const replyAllowedExts = <?= json_encode(getAllowedUploadExtensions()) ?>;
        function updateFileNames() {
            const input = document.getElementById('replyAttachments');
            const label = document.getElementById('replyAttachmentsLabel');
            if (input && label) {
                if (input.files.length > 0) {
                    const files = Array.from(input.files).map(f => f.name).join(', ');
                    label.textContent = files;
                } else {
                    label.textContent = <?= json_encode($isTr ? 'Dosyaları buraya bırakın veya tıklayın...' : 'Drop files here or click to choose...') ?>;
                }
            }
        }

        function insertCannedResponse(content) {
            if (!content) return;
            if (typeof summernoteReply !== 'undefined' && summernoteReply) {
                $('#summernoteReply').summernote('code', content);
            } else {
                const txt = document.getElementById('summernoteReply') || document.getElementsByName('message')[0];
                if (txt) txt.value = content;
            }
            const sel = document.getElementById('cannedResponseSelect');
            if (sel) sel.value = '';
        }

        // Lock when user focuses editor or starts typing
        var _currentUserRole = <?= (int)$current_user_role ?>;

        function requestLock() {
            // Customers (role 2) cannot request a lock — skip entirely
            if (_currentUserRole === 2) return;
            fetch('<?= $base_url ?>ajax/ticket_lock.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': '<?= $_SESSION['csrf_token'] ?>'
                },
                body: 'ticket_id=<?= $ticketId ?>&action=lock&csrf_token=<?= $_SESSION['csrf_token'] ?>'
            }).then(r => {
                // If server returned 403 (customer / forbidden), do NOT touch lock UI
                if (!r.ok && r.status === 403) return null;
                return r.json();
            }).then(d => {
                if (!d) return; // 403 or no response — do nothing
                const lockWarning = document.getElementById('ticketLockWarning');
                const sendBtn = document.getElementById('sendBtn');
                if (d && d.locked && d.locked_by && parseInt(d.locked_by) !== <?= (int) $current_user_id ?>) {
                    // locked by someone else
                    if (lockWarning) {
                        const lockUserName = document.getElementById('lockUserName');
                        if (lockUserName) lockUserName.textContent = d.locked_by_name || '—';
                        lockWarning.classList.remove('d-none');
                    }
                    if (sendBtn) sendBtn.disabled = true;
                } else if (d.ok === true) {
                    // Successfully locked by ME -> hide warning
                    if (lockWarning) lockWarning.classList.add('d-none');
                    if (sendBtn) sendBtn.disabled = false;
                }
                // If d.ok===false and d.locked===undefined -> do nothing (preserve existing UI)
            }).catch(e => console.error(e));
        }

        // Clear live typing signal on beforeunload
        window.addEventListener('beforeunload', function() {
            if (window.EaprimusRealtime) window.EaprimusRealtime.stopTyping();
        });

        // Sonuçlandırılacak Durum logic for assignment dropdown
        const statusSelect = document.getElementById('statusSelect');
        const assigneeSelectWrapper = document.getElementById('assigneeSelectWrapper');
        const assigneeSelect = document.getElementById('assigneeSelect');

        if (statusSelect && assigneeSelectWrapper) {
            function toggleAssigneeWrapper() {
                if (statusSelect.value === 'assigned') {
                    assigneeSelectWrapper.style.setProperty('display', 'flex', 'important');
                } else {
                    assigneeSelectWrapper.style.setProperty('display', 'none', 'important');
                    if (assigneeSelect) {
                        assigneeSelect.value = '';
                    }
                }
            }
            statusSelect.addEventListener('change', toggleAssigneeWrapper);
            toggleAssigneeWrapper();
        }

        const replyForm = document.getElementById('replyForm');
        if (replyForm) {
            replyForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const form = this;
                const btn = document.getElementById('sendBtn');

                if (statusSelect && statusSelect.value === 'assigned') {
                    if (assigneeSelect && !assigneeSelect.value) {
                        Swal.fire({
                            icon: 'warning',
                            title: <?= json_encode($isTr ? "Uyarı" : "Warning") ?>,
                            text: <?= json_encode($isTr ? "Lütfen bu bilet için atanacak temsilciyi seçin." : "Please select a representative to assign this ticket to.") ?>,
                            background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                            color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b',
                            confirmButtonColor: '#3b82f6',
                            confirmButtonText: <?= json_encode($isTr ? "Tamam" : "OK") ?>
                        });
                        return;
                    }

                    const currentAssigneeId = <?= (int)$ticketPersonnelId ?>;
                    if (assigneeSelect && parseInt(assigneeSelect.value) === currentAssigneeId) {
                        Swal.fire({
                            icon: 'warning',
                            title: <?= json_encode($isTr ? "Uyarı" : "Warning") ?>,
                            text: <?= json_encode($isTr ? "Zaten bu kişide bilet!" : "This ticket is already with this representative!") ?>,
                            background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                            color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b',
                            confirmButtonColor: '#3b82f6',
                            confirmButtonText: <?= json_encode($isTr ? "Tamam" : "OK") ?>
                        });
                        return;
                    }
                }
                
                if (summernoteReply) {
                    const content = $('#summernoteReply').summernote('code');
                    
                    // Eğer durum "Atandı" (assigned) ise, boş yanıtla da atama yapılabilir.
                    const isAssigned = (statusSelect && statusSelect.value === 'assigned');
                    
                    if (!isAssigned && $('<div>').html(content).text().trim().length < 2 && content.indexOf('<img') === -1 && content.indexOf('<table') === -1) {
                        Swal.fire({
                            icon: 'warning',
                            title: <?= json_encode($isTr ? "Uyarı" : "Warning") ?>,
                            text: <?= json_encode($isTr ? "Lütfen bir yanıt yazın!" : "Please enter a reply!") ?>,
                            background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                            color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b',
                            confirmButtonColor: '#3b82f6',
                            confirmButtonText: <?= json_encode($isTr ? "Tamam" : "OK") ?>
                        });
                        return;
                    }
                }

                // Client-side file size verification
                const fileInput = document.getElementById('replyAttachments');
                if (fileInput && fileInput.files.length > 0) {
                    let totalSize = 0;
                    for (let i = 0; i < fileInput.files.length; i++) {
                        const file = fileInput.files[i];
                        if (file.size > PHP_UPLOAD_MAX_SIZE) {
                            Swal.fire({
                                icon: 'error',
                                title: <?= json_encode($isTr ? "Dosya Boyutu Çok Büyük" : "File Size Too Large") ?>,
                                text: (<?= json_encode($isTr ? "Yüklemek istediğiniz '" : "The file '") ?> + file.name + <?= json_encode($isTr ? "' izin verilen maksimum dosya boyutunu aşıyor. En fazla " : "' exceeds the maximum allowed file size. Max: ") ?> + PHP_UPLOAD_MAX_TEXT + <?= json_encode($isTr ? " yükleyebilirsiniz." : " is allowed.") ?>),
                                confirmButtonText: 'Tamam'
                            });
                            return;
                        }
                        totalSize += file.size;
                    }
                    if (totalSize > PHP_POST_MAX_SIZE) {
                        Swal.fire({
                            icon: 'error',
                            title: <?= json_encode($isTr ? "Toplam Boyut Çok Büyük" : "Total Size Too Large") ?>,
                            text: <?= json_encode($isTr ? "Seçtiğiniz dosyaların toplam boyutu sunucu limitini aşıyor. Lütfen daha küçük dosyalar yükleyin." : "The total size of selected files exceeds the server limit. Please upload smaller files.") ?>,
                            confirmButtonText: 'Tamam'
                        });
                        return;
                    }
                }

                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i><?= __("sending") ?>...';

                // Şık yükleme popup mesajı
                const isAssignment = (statusSelect && statusSelect.value === 'assigned');
                Swal.fire({
                    icon: isAssignment ? 'warning' : 'info',
                    title: isAssignment 
                        ? <?= json_encode($isTr ? "Bilet Atanıyor..." : "Ticket Assigning...") ?>
                        : <?= json_encode($isTr ? "İşlem Yapılıyor..." : "Processing...") ?>,
                    text: isAssignment
                        ? <?= json_encode($isTr ? "Lütfen bekleyin, bilet yeni temsilciye atanıyor..." : "Please wait, the ticket is being assigned to the new representative...") ?>
                        : <?= json_encode($isTr ? "Lütfen bekleyin, bilet güncelleniyor." : "Please wait, the ticket is being updated.") ?>,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                    color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b',
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const formData = new FormData(form);
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(async response => {
                    // HTTP 413: Payload Too Large (PHP post_max_size exceeded at server level)
                    if (response.status === 413) {
                        throw new Error(<?= json_encode($isTr
                            ? "Yüklediğiniz dosya sunucu limitini aşıyor. Maksimum dosya boyutu: " . ini_get('upload_max_filesize') . ". Lütfen daha küçük bir dosya yükleyin."
                            : "The uploaded file exceeds the server limit. Maximum file size: " . ini_get('upload_max_filesize') . ". Please upload a smaller file.") ?>);
                    }
                    const text = await response.text();
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        // Sunucu HTML döndürdü (fatal error, 403, vs.) - güvenli çıkış
                        console.error('Sunucu geçersiz JSON döndürdü:', text.substring(0, 300));
                        throw new Error(<?= $isTr ? '"Sunucu beklenmedik bir yanıt döndürdü."' : '"Server returned an unexpected response."' ?>);
                    }
                })
                .then(data => {
                    Swal.close();
                    if (data.ok) {
                        const draftKey = 'ticket_draft_' + <?= intval($ticketId) ?> + '_' + <?= intval($current_user_id) ?>;
                        localStorage.removeItem(draftKey);
                        // Suppress lock release on navigation — user just replied, page will reload
                        _lockReleaseEnabled = false;
                        // Fast UI closure
                        const replyBox = document.getElementById('replyBoxContainer');
                        if (replyBox) {
                            replyBox.style.opacity = '0.5';
                            replyBox.style.pointerEvents = 'none';
                        }
                        window.location.href = data.redirect;
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: '<?= __("error") ?>',
                            text: data.error || '<?= __("operation_failed") ?>',
                            confirmButtonText: 'Tamam'
                        });
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i> <?= __("send") ?? "Gönder" ?>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: '<?= __("server_error") ?>',
                        text: error.message || '<?= $isTr ? "İşlem sırasında bir hata oluştu." : "An error occurred during the operation." ?>',
                        confirmButtonText: 'Tamam'
                    });
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i> <?= __("send") ?? "Gönder" ?>';
                });
            });
        }

        // Claim button handler (now "Yanıtla")
        const claimBtn = document.getElementById('claimBtn');
        if (claimBtn) {
            claimBtn.addEventListener('click', function (e) {
                e.preventDefault();
                console.log('Yanıtla butonuna tıklandı, işlemlere başlanıyor...');

                claimBtn.disabled = true;
                const originalContent = claimBtn.innerHTML;
                claimBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ...';

                const formData = new URLSearchParams();
                formData.append('ticket_id', '<?= $ticketId ?>');
                formData.append('action', 'claim');
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
                // Optional: if backend wants explicit personnel_id (backend can also read from session)
                formData.append('personnel_id', '<?= (int) $current_user_id ?>');

                fetch('<?= $base_url ?>ajax/ticket_lock.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': '<?= $_SESSION['csrf_token'] ?>'
                    },
                    body: formData.toString()
                }).then(async (r) => {
                    const raw = await r.text();
                    console.log('Raw Response:', raw);

                    let parsed = null;
                    try {
                        parsed = raw ? JSON.parse(raw) : null;
                    } catch (e) {
                        console.error('JSON Parse Error:', e, 'Raw text:', raw);
                        parsed = null;
                    }

                    if (!r.ok) {
                        console.error('Yanıtla isteği başarısız (HTTP)', {
                            status: r.status,
                            statusText: r.statusText,
                            body: raw,
                            json: parsed
                        });
                        const msg = (parsed && (parsed.message || parsed.msg || parsed.error)) ? (parsed.message || parsed.msg || parsed.error) : raw;
                        throw new Error(msg || ('HTTP ' + r.status));
                    }

                    return parsed;
                }).then(d => {
                    console.log('AJAX Yanıtı:', d);
                    if (!d) {
                        throw new Error('Boş cevap döndü');
                    }
                    if (d.ok) {
                        if (document.getElementById('resolvedCardContainer')) {
                            location.reload();
                            return;
                        }
                        // Update Sidebar Info (Lock & Assigned)
                        const lockBadge = document.getElementById('lockStatusBadge');
                        if (lockBadge) {
                            lockBadge.style.background = '#fee2e2';
                            lockBadge.style.color = '#dc2626';
                            lockBadge.innerHTML = '<i class="fas fa-lock mr-1"></i> <?= $isTr ? "Kilitli" : "Locked" ?>: <?= htmlspecialchars($_SESSION["fullname"] ?? "-") ?>';
                        }

                        // Update left sidebar button if exists
                        const adminLockBtnSidebar = document.getElementById('adminLockBtn');
                        if (adminLockBtnSidebar) {
                            adminLockBtnSidebar.dataset.locked = '1';
                            adminLockBtnSidebar.classList.add('is-locked');
                            adminLockBtnSidebar.classList.remove('is-unlocked');
                            adminLockBtnSidebar.innerHTML = '<i class="fas fa-lock mr-2" style="width:20px; text-align:center;"></i> <?= $btnLang("unlock_ticket_btn", "Kilidi Aç") ?>';
                        }

                        const assignedName = document.getElementById('assignedPersonnelName');
                        if (assignedName) {
                            assignedName.textContent = '<?= htmlspecialchars($_SESSION["fullname"] ?? "-") ?>';
                        }

                        // Show Editor ONLY if lock was successful
                        const replyBox = document.getElementById('replyBoxContainer');
                        if (replyBox) {
                            replyBox.style.display = 'block';
                            replyBox.style.opacity = 0;
                            let opacity = 0;
                            const timer = setInterval(function () {
                                if (opacity >= 1) {
                                    clearInterval(timer);
                                }
                                replyBox.style.opacity = opacity;
                                opacity += 0.2;
                            }, 30);

                            replyBox.scrollIntoView({ behavior: 'smooth' });
                        }

                        // Focus Editor
                        if (typeof summernoteReply !== 'undefined' && summernoteReply) {
                            $('#summernoteReply').summernote('focus');
                        } else if (typeof quillReply !== 'undefined' && quillReply) {
                            quillReply.focus();
                        }

                        if (typeof requestLock === 'function') {
                            requestLock();
                        }

                        // Update claim button / reply visibility
                        setReplyVisibilityForLockState(true, true);

                        // Restore button state
                        claimBtn.innerHTML = originalContent;
                        claimBtn.disabled = false;
                    } else {
                        claimBtn.disabled = false;
                        claimBtn.innerHTML = originalContent;
                        
                        if (d.error === 'locked') {
                            Swal.fire({
                                icon: 'warning',
                                title: '<?= __("ticket_locked") ?? "Bilet Kilitli" ?>',
                                html: `<div class="p-3">
                                    <i class="fas fa-user-lock fa-3x text-warning mb-3"></i>
                                    <p style="font-size: 16px;">${d.message}</p>
                                    <p class="text-muted small">Şu anda sadece görüntüleme yetkiniz bulunmaktadır.</p>
                                </div>`,
                                confirmButtonText: 'Anladım',
                                confirmButtonColor: '#3085d6'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: '<?= __("error") ?>',
                                text: d.message || d.error || '<?= __("operation_failed") ?>',
                                confirmButtonText: 'Tamam'
                            });
                        }
                    }
                }).catch(err => {
                    console.error('AJAX Hatası:', err);
                    claimBtn.disabled = false;
                    claimBtn.innerHTML = originalContent;
                    Swal.fire({
                        icon: 'error',
                        title: '<?= __("server_error") ?>',
                        text: err.message,
                        confirmButtonText: 'Tamam'
                    });
                });
            });
        }

        // When admin unlocks (or any unlock happens), hide reply box and show claim button
        function setReplyVisibilityForLockState(isLocked, lockedByMe, isAssignedToMe) {
            const replyBox = document.getElementById('replyBoxContainer');
            const claimBtnEl = document.getElementById('claimBtn');
            const resolvedCard = document.getElementById('resolvedCardContainer');
            const isClosedOrResolvedVar = <?= $isClosedOrResolved ? 'true' : 'false' ?>;
            const isCreator = <?= ((int)$ticket['creator_id'] === (int)$current_user_id) ? 'true' : 'false' ?>;
            const isCustomerRole = <?= ((int)$current_user_role === 2) ? 'true' : 'false' ?>;

            if (isClosedOrResolvedVar) {
                if (replyBox) replyBox.style.display = 'none';
                if (claimBtnEl) claimBtnEl.style.display = 'none';
                if (resolvedCard) resolvedCard.style.display = 'block';
                return;
            }

            if (resolvedCard) resolvedCard.style.display = 'none';
            if (!replyBox) return;
            
            if (isLocked) {
                if (lockedByMe || isAssignedToMe || isCreator || isCustomerRole) {
                    replyBox.style.display = 'block';
                    claimBtnEl.style.display = 'none';
                } else {
                    replyBox.style.display = 'none';
                    claimBtnEl.style.display = 'inline-block';
                }
            } else {
                if (isAssignedToMe || isCreator || isCustomerRole) {
                    replyBox.style.display = 'block';
                    claimBtnEl.style.display = 'none';
                } else {
                    replyBox.style.display = 'none';
                    claimBtnEl.style.display = 'inline-block';
                }
            }
        }

        // Initialize visibility on load
        setReplyVisibilityForLockState(<?= ($isTicketLocked && !$isClosedOrResolved) ? 'true' : 'false' ?>, <?= ($isLockedByMe && !$isClosedOrResolved) ? 'true' : 'false' ?>, <?= ((int)$ticketPersonnelId === (int)$current_user_id && !$isClosedOrResolved) ? 'true' : 'false' ?>);

        // Admin Lock/Unlock button handler
        const adminLockBtn = document.getElementById('adminLockBtn');
        if (adminLockBtn) {
            adminLockBtn.addEventListener('click', function (e) {
                e.preventDefault();
                // Read current locked state from data attribute (set server-side)
                const currentlyLocked = adminLockBtn.dataset.locked === '1';
                const action = currentlyLocked ? 'unlock' : 'lock';

                const formData = new URLSearchParams();
                formData.append('ticket_id', '<?= $ticketId ?>');
                formData.append('action', action);
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

                // Disable button while request runs
                adminLockBtn.disabled = true;
                const origHtml = adminLockBtn.innerHTML;
                adminLockBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>...';

                fetch('<?= $base_url ?>ajax/ticket_lock.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': '<?= $_SESSION['csrf_token'] ?>'
                    },
                    body: formData.toString()
                }).then(r => r.json()).then(d => {
                    if (d && d.ok) {
                        if (d.reopened) {
                            location.reload();
                            return;
                        }
                        const lockBadge = document.getElementById('lockStatusBadge');
                        const newLocked = action === 'lock';
                        adminLockBtn.dataset.locked = newLocked ? '1' : '0';
                        if (newLocked) {
                            adminLockBtn.classList.add('is-locked');
                            adminLockBtn.classList.remove('is-unlocked');
                            adminLockBtn.innerHTML = '<i class="fas fa-lock mr-2" style="width:20px; text-align:center;"></i> <?= $btnLang("unlock_ticket_btn", "Kilidi Aç") ?>';
                            if (lockBadge) {
                                lockBadge.style.background = '#fee2e2';
                                lockBadge.style.color = '#dc2626';
                                lockBadge.innerHTML = '<i class="fas fa-lock mr-1"></i> <?= $isTr ? "Kilitli" : "Locked" ?>: <?= htmlspecialchars($current_user_name ?? $_SESSION["fullname"]) ?>';
                            }
                            setReplyVisibilityForLockState(true, true);
                        } else {
                            adminLockBtn.classList.remove('is-locked');
                            adminLockBtn.classList.add('is-unlocked');
                            adminLockBtn.innerHTML = '<i class="fas fa-unlock-alt mr-2" style="width:20px; text-align:center;"></i> <?= $btnLang("lock_ticket_btn", "Bileti Kilitle") ?>';
                            if (lockBadge) {
                                lockBadge.style.background = '#d1fae5';
                                lockBadge.style.color = '#059669';
                                lockBadge.innerHTML = '<i class="fas fa-unlock"></i> <?= __("unlocked") ?>';
                            }
                            setReplyVisibilityForLockState(false, false);
                        }
                    } else {
                        // Yetki hatası veya başka bir hata (ör. Kilidi açmaya çalışırken yetki yoksa)
                        const errMsg = (d && d.message) ? d.message : 'Bir hata oluştu.';
                        Swal.fire({
                            icon: 'warning',
                            title: 'Uyarı',
                            text: errMsg,
                            confirmButtonText: 'Tamam'
                        }).then(() => {
                            location.reload();
                        });
                    }
                }).catch(err => {
                    console.error('AJAX Hatası:', err);
                    Swal.fire({
                        icon: 'error',
                        title: <?= json_encode(__("error") ?? "Hata") ?>,
                        text: <?= json_encode(__("server_error") ?? "Sunucu Hatası") ?> + ': ' + err.message
                    });
                }).finally(() => {
                    adminLockBtn.disabled = false;
                });
            });
        }

        function confirmDeleteTicket() {
            const isDark = document.body.classList.contains('dark-mode');
            const bg = isDark ? '#1e293b' : '#fff';
            const textColor = isDark ? '#f1f5f9' : '#111827';
            const descColor = isDark ? '#94a3b8' : '#6b7280';
            const btnBg = isDark ? '#334155' : '#fff';
            const btnBorder = isDark ? '#475569' : '#e5e7eb';
            const btnText = isDark ? '#f1f5f9' : '#374151';
            const iconBg = isDark ? '#7f1d1d' : '#fee2e2';

            // Modern custom modal logic with CSS
            const modalHtml = `
            <div id="customDeleteModal" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:9999;">
                <div style="background:${bg}; width:400px; border-radius:16px; padding:30px; text-align:center; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); color:${textColor};">
                    <div style="width:70px; height:70px; background:${iconBg}; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">
                        <i class="fas fa-trash-alt" style="color:#ef4444; font-size:30px;"></i>
                    </div>
                    <h4 style="font-weight:700; color:${textColor}; margin-bottom:10px;"><?= __("delete_ticket_btn") ?>?</h4>
                    <p style="color:${descColor}; font-size:14px; margin-bottom:25px;"><?= __("confirm_delete_msg") ?></p>
                    <div style="display:flex; gap:12px;">
                        <button onclick="document.getElementById('customDeleteModal').remove()" style="flex:1; padding:12px; border-radius:10px; border:1px solid ${btnBorder}; background:${btnBg}; color:${btnText}; font-weight:600; cursor:pointer;"><?= __("cancel") ?></button>
                        <button id="execDeleteBtn" style="flex:1; padding:12px; border-radius:10px; border:none; background:#ef4444; color:#fff; font-weight:600; cursor:pointer;"><?= __("yes_delete") ?></button>
                    </div>
                </div>
            </div>
        `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            document.getElementById('execDeleteBtn').onclick = function () {
                const f = document.createElement('form');
                f.method = 'POST';
                f.innerHTML = '<input type="hidden" name="action" value="delete_ticket"><?= csrf_field() ?>';
                document.body.appendChild(f);
                f.submit();
            };
        }

        // Sohbet kutusunu en alta kaydır (Kullanıcı isteğiyle en üstte açılması için devre dışı bırakıldı)
        const chatBox = document.getElementById('chatBox');
        if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;

        // Bilet Çakışma Önleme (Lock Polling)
        <?php if (!in_array($ticket['status'], ['closed', 'resolved'])): ?>
            function pingTicketLock() {
                const isTr = <?= json_encode(($_SESSION['lang'] ?? 'tr') === 'tr') ?>;
                fetch('<?= $base_url ?>ajax/ticket_lock.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': '<?= $_SESSION['csrf_token'] ?>'
                    },
                    body: 'ticket_id=<?= $ticketId ?>&action=ping&csrf_token=<?= $_SESSION['csrf_token'] ?>'
                })
                    .then(r => r.json())
                    .then(data => {
                        const lockWarning = document.getElementById('ticketLockWarning');
                        const lockUserName = document.getElementById('lockUserName');
                        const sendBtn = document.getElementById('sendBtn');
                        const claimBtnEl = document.getElementById('claimBtn');
                        const replyBox = document.getElementById('replyBoxContainer');

                        const lockBadge = document.getElementById('lockStatusBadge');
                        if (lockBadge && data) {
                            if (data.locked) {
                                lockBadge.className = 'lock-status-locked';
                                lockBadge.innerHTML = '<i class="fas fa-lock mr-1"></i> ' + (isTr ? 'Kilitli' : 'Locked') + ': ' + (data.locked_by_name || '—');
                            } else {
                                lockBadge.className = 'lock-status-unlocked';
                                lockBadge.innerHTML = '<i class="fas fa-unlock mr-1"></i> ' + (isTr ? 'Kilidi Açık' : 'Unlocked');
                            }
                        }

                        if (!data) {
                            lockWarning.classList.add('d-none');
                            sendBtn.disabled = false;
                            return;
                        }

                        const isClosedOrResolvedVar = <?= $isClosedOrResolved ? 'true' : 'false' ?>;
                        if (isClosedOrResolvedVar) {
                            if (replyBox) replyBox.style.display = 'none';
                            if (claimBtnEl) claimBtnEl.style.display = 'none';
                            const resolvedCard = document.getElementById('resolvedCardContainer');
                            if (resolvedCard) resolvedCard.style.display = 'block';
                            return;
                        }

                        if (data.locked) {
                            if (parseInt(data.locked_by) !== <?= (int) $current_user_id ?>) {
                                lockUserName.textContent = data.locked_by_name || data.locked_by || '—';
                                lockWarning.classList.remove('d-none');
                                const isAssignedToMeAjax = <?= ((int)$ticketPersonnelId === (int)$current_user_id && !$isClosedOrResolved) ? 'true' : 'false' ?>;
                                const isCreatorAjax = <?= ((int)$ticket['creator_id'] === (int)$current_user_id) ? 'true' : 'false' ?>;
                                const isCustomerRoleAjax = <?= ((int)$current_user_role === 2) ? 'true' : 'false' ?>;
                                const shouldShow = isAssignedToMeAjax || isCreatorAjax || isCustomerRoleAjax;
                                
                                sendBtn.disabled = !shouldShow;
                                if (replyBox) replyBox.style.display = shouldShow ? 'block' : 'none';
                                if (claimBtnEl) claimBtnEl.style.display = shouldShow ? 'none' : 'inline-block';
                            } else {
                                lockWarning.classList.add('d-none');
                                sendBtn.disabled = false;
                                if (replyBox) replyBox.style.display = 'block';
                                if (claimBtnEl) claimBtnEl.style.display = 'none';
                            }
                        } else {
                            lockWarning.classList.add('d-none');
                            sendBtn.disabled = false;
                            const isAssignedToMe = <?= ((int)$ticketPersonnelId === (int)$current_user_id && !$isClosedOrResolved) ? 'true' : 'false' ?>;
                            const isCreator = <?= ((int)$ticket['creator_id'] === (int)$current_user_id) ? 'true' : 'false' ?>;
                            const showReplyLogic = <?= (!$isClosedOrResolved) ? 'true' : 'false' ?> && (isAssignedToMe || isCreator);
                            if (showReplyLogic) {
                                if (replyBox) replyBox.style.display = 'block';
                                if (claimBtnEl && !isAssignedToMe) claimBtnEl.style.display = 'inline-block';
                                else if (claimBtnEl) claimBtnEl.style.display = 'none';
                            } else {
                                if (replyBox) replyBox.style.display = 'none';
                                if (claimBtnEl) claimBtnEl.style.display = 'inline-block';
                            }
                        }
                    })
                    .catch(err => console.error(err));
            }
            setInterval(pingTicketLock, 15000);
        <?php endif; ?>
    </script>


    <!-- Customer Info Drawer -->
    <div class="side-drawer-overlay" id="infoDrawerOverlay" onclick="closeTicketInfoDrawer()"></div>
    <div class="side-drawer-new" id="infoDrawer">
        <div class="side-drawer-header bg-info text-white d-flex justify-content-between align-items-center p-3">
            <h5 class="m-0"><i class="fas fa-address-card mr-2"></i><?= __('customer_organization_info') ?? 'Müşteri & Organizasyon Bilgileri' ?></h5>
            <button onclick="closeTicketInfoDrawer()" class="btn btn-link text-white p-0"><i class="fas fa-times fa-lg"></i></button>
        </div>
        <div class="side-drawer-body p-4" style="height: calc(100vh - 60px); overflow-y: auto;">
            <div id="infoDrawerContent">
                <div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>
            </div>
        </div>
    </div>

    <style>
        .side-drawer-new { position: fixed; top: 0; right: -450px; width: 450px; height: 100vh; background: #fff; box-shadow: -5px 0 15px rgba(0,0,0,0.1); z-index: 9999; transition: right 0.3s ease; }
        .side-drawer-new.open { right: 0; }
        #infoDrawerOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 9998; display: none; backdrop-filter: blur(2px); }
        body.dark-mode .side-drawer-new { background: #1e293b; color: #f1f5f9; }
    </style>

    <script>
    function openTicketInfoDrawer() {
        $('#infoDrawer').addClass('open');
        $('#infoDrawerOverlay').fadeIn();
        $('#infoDrawerContent').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>');
        
        const cid = <?= (int)$sidebarCustId ?>;
        const oid = <?= (int)$orgId ?>;
        
        $.get('<?= $base_url ?>ajax/get_ticket_info.php', { customer_id: cid, organization_id: oid }, function(res) {
            $('#infoDrawerContent').html(res);
        });
    }
    function closeTicketInfoDrawer() {
        $('#infoDrawer').removeClass('open');
        $('#infoDrawerOverlay').fadeOut();
    }
    
    // --- Subtasks & Time Tracking JS ---
    const isTicketClosed = <?= in_array($ticket['status'], ['closed', 'resolved']) ? 'true' : 'false' ?>;

    function addSubtask() {
        if (isTicketClosed) {
            Swal.fire({
                icon: 'warning',
                title: <?= json_encode($isTr ? "Bilet Kapalı" : "Ticket Closed") ?>,
                text: <?= json_encode($isTr ? "Bu bilet kapatılmış olduğu için alt görev eklenemez." : "Cannot add subtask because the ticket is closed.") ?>,
                background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
            });
            return;
        }
        const text = $('#newSubtaskText').val().trim();
        if(!text) return;
        $.post('<?= $base_url ?>ajax/ajax_subtasks.php', {
            action: 'add',
            ticket_id: <?= $ticketId ?>,
            task_text: text
        }, function(res) {
            if(res.success) {
                location.reload();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: <?= json_encode($isTr ? "Hata" : "Error") ?>,
                    text: res.error || 'Error',
                    background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                    color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
                });
            }
        });
    }

    function toggleSubtask(id, isChecked) {
        if (isTicketClosed) {
            $('#chk-' + id).prop('checked', !isChecked);
            Swal.fire({
                icon: 'warning',
                title: <?= json_encode($isTr ? "Bilet Kapalı" : "Ticket Closed") ?>,
                text: <?= json_encode($isTr ? "Bu bilet kapatılmış olduğu için alt görev durumu değiştirilemez." : "Cannot toggle subtask because the ticket is closed.") ?>,
                background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
            });
            return;
        }
        $.post('<?= $base_url ?>ajax/ajax_subtasks.php', {
            action: 'toggle',
            ticket_id: <?= $ticketId ?>,
            task_id: id,
            is_checked: isChecked ? 1 : 0
        }, function(res) {
            if(res.success) {
                const lbl = $('#st-'+id+' label');
                if(isChecked) {
                    lbl.css({'text-decoration': 'line-through', 'color': '#9ca3af'});
                } else {
                    lbl.css({'text-decoration': 'none', 'color': 'inherit'});
                }
            } else {
                $('#chk-' + id).prop('checked', !isChecked);
                Swal.fire({
                    icon: 'error',
                    title: <?= json_encode($isTr ? "Hata" : "Error") ?>,
                    text: res.error || 'Error',
                    background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                    color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
                });
            }
        });
    }

    function deleteSubtask(id) {
        if (isTicketClosed) {
            Swal.fire({
                icon: 'warning',
                title: <?= json_encode($isTr ? "Bilet Kapalı" : "Ticket Closed") ?>,
                text: <?= json_encode($isTr ? "Bu bilet kapatılmış olduğu için alt görev silinemez." : "Cannot delete subtask because the ticket is closed.") ?>,
                background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
            });
            return;
        }
        Swal.fire({
            title: <?= json_encode($isTr ? "Silmek istediğinize emin misiniz?" : "Are you sure you want to delete this?") ?>,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: <?= json_encode($isTr ? "Evet, sil" : "Yes, delete") ?>,
            cancelButtonText: <?= json_encode($isTr ? "İptal" : "Cancel") ?>,
            background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
            color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b',
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('<?= $base_url ?>ajax/ajax_subtasks.php', {
                    action: 'delete',
                    ticket_id: <?= $ticketId ?>,
                    task_id: id
                }, function(res) {
                    if(res.success) {
                        $('#st-'+id).remove();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: <?= json_encode($isTr ? "Hata" : "Error") ?>,
                            text: res.error || 'Error',
                            background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                            color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
                        });
                    }
                });
            }
        });
    }

    function deleteTimeLog(id) {
        if (isTicketClosed) {
            Swal.fire({
                icon: 'warning',
                title: <?= json_encode($isTr ? "Bilet Kapalı" : "Ticket Closed") ?>,
                text: <?= json_encode($isTr ? "Bu bilet kapatılmış olduğu için efor silinemez." : "Cannot delete effort because the ticket is closed.") ?>,
                background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
            });
            return;
        }
        Swal.fire({
            title: <?= json_encode($isTr ? "Silmek istediğinize emin misiniz?" : "Are you sure you want to delete this?") ?>,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: <?= json_encode($isTr ? "Evet, sil" : "Yes, delete") ?>,
            cancelButtonText: <?= json_encode($isTr ? "İptal" : "Cancel") ?>,
            background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
            color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b',
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('<?= $base_url ?>ajax/ajax_timelogs.php', {
                    action: 'delete',
                    ticket_id: <?= $ticketId ?>,
                    log_id: id
                }, function(res) {
                    if(res.success) {
                        location.reload();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: <?= json_encode($isTr ? "Hata" : "Error") ?>,
                            text: res.error || 'Error',
                            background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                            color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
                        });
                    }
                });
            }
        });
    }

    function submitTimeLog() {
        if (isTicketClosed) {
            Swal.fire({
                icon: 'warning',
                title: <?= json_encode($isTr ? "Bilet Kapalı" : "Ticket Closed") ?>,
                text: <?= json_encode($isTr ? "Bu bilet kapatılmış olduğu için efor eklenemez." : "Cannot add effort because the ticket is closed.") ?>,
                background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
            });
            return;
        }
        const h = $('#timeHours').val();
        const m = $('#timeMins').val();
        const n = $('#timeNote').val();
        $.post('<?= $base_url ?>ajax/ajax_timelogs.php', {
            action: 'add',
            ticket_id: <?= $ticketId ?>,
            hours: h,
            minutes: m,
            note: n
        }, function(res) {
            if(res.success) {
                location.reload();
            } else {
                let errorMsg = res.error || 'Error';
                if (errorMsg === 'Invalid time') {
                    errorMsg = <?= json_encode($isTr ? "Lütfen geçerli bir süre giriniz (en az 1 dakika)." : "Please enter a valid time (at least 1 minute).") ?>;
                }
                Swal.fire({
                    icon: 'warning',
                    title: <?= json_encode($isTr ? "Geçersiz Süre" : "Invalid Time") ?>,
                    text: errorMsg,
                    background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                    color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
                });
            }
        });
    }

    window.addEventListener('load', function() {
        setTimeout(function() {
            var lastMsg = document.querySelector('#chatBox .msg-bubble:last-of-type') || document.getElementById('replyBoxContainer');
            if (lastMsg) {
                lastMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, 100);
    });
    </script>
    
    <!-- Time Tracking Modal -->
    <div class="modal fade" id="addTimeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= $isTr ? 'Efor Ekle' : 'Log Time' ?></h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label><?= $isTr ? 'Saat' : 'Hours' ?></label>
                            <input type="number" id="timeHours" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-md-6 form-group">
                            <label><?= $isTr ? 'Dakika' : 'Minutes' ?></label>
                            <input type="number" id="timeMins" class="form-control" value="0" min="0" max="59">
                        </div>
                    </div>
                    <div class="form-group">
                        <label><?= $isTr ? 'Not (İsteğe bağlı)' : 'Note (Optional)' ?></label>
                        <input type="text" id="timeNote" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __("cancel") ?></button>
                    <button type="button" class="btn btn-warning text-white" onclick="submitTimeLog()"><?= __("save") ?></button>
                </div>
            </div>
        </div>
    </div>
