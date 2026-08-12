<?php
// public/api/v1/live_stream.php
// Real-Time Server-Sent Events (SSE Stream) for Eaprimus Live Ticket & Notification Engine

@ini_set('display_errors', 0);
@error_reporting(0);

if (function_exists('opcache_reset')) { @opcache_reset(); }
ignore_user_abort(true);
set_time_limit(30);

require_once __DIR__ . '/../../../app/includes/session.php';
require_once __DIR__ . '/../../../app/config/db.php';

header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Connection: keep-alive");
header("X-Accel-Buffering: no");

$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id <= 0) {
    echo "event: error\ndata: {\"message\": \"Unauthorized\"}\n\n";
    ob_flush(); flush();
    exit;
}
session_write_close();

$pdo = db();
if (!$pdo) {
    echo "event: error\ndata: {\"message\": \"Database connection error\"}\n\n";
    ob_flush(); flush();
    exit;
}

$ticket_id = intval($_GET['ticket_id'] ?? 0);
$last_reply_id = intval($_GET['last_reply_id'] ?? 0);
$last_ticket_id = intval($_GET['last_ticket_id'] ?? 0);
$last_global_reply_id = intval($_GET['last_global_reply_id'] ?? 0);
$last_rating_id = intval($_GET['last_rating_id'] ?? 0);

// Typing heartbeat handler with microtime TTL & explicit stop
if (isset($_GET['action']) && $_GET['action'] === 'type') {
    if ($ticket_id > 0) {
        $logsDir = __DIR__ . '/../../../app/logs/';
        if (!is_dir($logsDir)) @mkdir($logsDir, 0755, true);
        // Per-user typing file so multiple typists don't overwrite each other
        $typingFile = $logsDir . 'typing_ticket_' . $ticket_id . '_user_' . $user_id . '.json';
        
        if (isset($_GET['stop']) && $_GET['stop'] === '1') {
            @unlink($typingFile);
        } else {
            @file_put_contents($typingFile, json_encode([
                'user_id'  => $user_id,
                'fullname' => $_SESSION['fullname'] ?? 'Kullanıcı',
                'time'     => microtime(true)
            ]));
        }
    }
    echo json_encode(['status' => 'ok']);
    exit;
}

// Single-pass check for ultra-fast, non-blocking SSE response
$response = [
    'time' => time(),
    'new_replies' => [],
    'typing_user' => null,
    'unread_tickets' => 0,
    'unread_list' => [],
    'latest_ticket_id' => 0,
    'new_ticket_created' => null,
    'latest_global_reply_id' => 0,
    'new_global_reply' => null,
    'latest_rating_id' => 0,
    'new_ticket_rating' => null
];

// Safe defaults - always available even if try block below fails
$whereCountNav = "";
$tenantCond = "1=1";

try {
    // 1. Unread tickets count and list for navbar matching exact navbar.php logic
    $c_unread = 0;
    $unread_list = [];
    try {
        $user_role = $_SESSION['role'] ?? 2;
        $tenantCond = "1=1";
        if (function_exists('tenantWhere')) { $tenantCond = tenantWhere(); }
        static $hasClosedByStream = null;
        if ($hasClosedByStream === null) {
            try {
                $chkCS = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'closed_by'");
                $hasClosedByStream = ($chkCS && $chkCS->fetch()) ? true : false;
            } catch (Throwable $e) { $hasClosedByStream = false; }
        }
        $closedByColStream = $hasClosedByStream ? "t.closed_by" : "NULL";
        $statusCond = "(
            t.status NOT IN ('resolved','closed')
            OR (t.status IN ('resolved','closed') AND (t.unread_replies_count > 0 OR EXISTS (SELECT 1 FROM ticket_ratings tr WHERE tr.ticket_id = t.id)))
        )";

        $whereCountNav = "";
        if ($user_role == 2) {
            // Customer: only own tickets
            $whereCountNav = "AND (t.creator_id = " . (int)$user_id . " OR t.customer_id = " . (int)$user_id . ")";
        } elseif ($user_role == 1) {
            // Admin: sees ALL tickets - no filter needed
            $whereCountNav = "";
        } else {
            // Personnel (role 3): sees assigned + queue tickets + own
            static $col_nav = null;
            if ($col_nav === null) {
                $stmtCol = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'personnel_id'");
                $col_nav = $stmtCol->fetch() ? 'personnel_id' : 'assigned_to';
            }
            $whereCountNav = "AND (
                t.$col_nav = " . (int)$user_id . "
                OR t.creator_id = " . (int)$user_id . "
                OR ( (t.$col_nav = 0 OR t.$col_nav IS NULL OR t.$col_nav = '') AND t.queue_id IN (SELECT q.id FROM queues q JOIN teams_users tu ON q.team_id = tu.team_id WHERE tu.user_id = " . (int)$user_id . ") )
            )";
        }

        $all_candidate_tickets = $pdo->query("SELECT t.id, t.ticket_no, t.title, t.status, t.agent_read, t.unread_replies_count, (SELECT MAX(r.id) FROM ticket_replies r WHERE r.ticket_id = t.id AND r.reply_type != 'system') as max_reply_id, (SELECT COUNT(r.id) FROM ticket_replies r WHERE r.ticket_id = t.id AND r.reply_type != 'system') as reply_count FROM tickets t WHERE $statusCond AND $tenantCond $whereCountNav ORDER BY t.update_date DESC, t.id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all_candidate_tickets as $nt) {
            $ratedVal = null;
            try {
                $stR = $pdo->prepare("SELECT rating FROM ticket_ratings WHERE ticket_id = ?");
                $stR->execute([$nt['id']]);
                $ratedVal = $stR->fetchColumn();
            } catch (Exception $e) {}

            $token = ($nt['max_reply_id'] ? (int)$nt['max_reply_id'] : 'new') . '_' . ($nt['status'] ?? 'open') . '_' . ($ratedVal ?: '0');
            if ($ticket_id > 0 && (int)$nt['id'] === (int)$ticket_id) {
                $_SESSION['read_ticket_replies'][$nt['id']] = $token;
                $isRead = true;
            } else {
                $hasReadInDB = ((int)($nt['agent_read'] ?? 0) === 1 && (int)($nt['unread_replies_count'] ?? 0) === 0);
                $hasReadInSessionCookie = (
                    (isset($_SESSION['read_ticket_replies'][$nt['id']]) && $_SESSION['read_ticket_replies'][$nt['id']] === $token)
                    || (isset($_COOKIE['read_ticket_reply_' . $user_id . '_' . $nt['id']]) && $_COOKIE['read_ticket_reply_' . $user_id . '_' . $nt['id']] === $token)
                );
                $isRead = ($hasReadInDB && $hasReadInSessionCookie && empty($ratedVal));
            }
            if (!$isRead) {
                $c_unread++;
                if (count($unread_list) < 5) {
                    $isTrStream = (($_SESSION['lang'] ?? 'tr') === 'tr');
                    $status_text = $isTrStream ? 'Yeni Bilet' : 'New Ticket';
                    $status_class = 'badge-success';
                    $unread = (int)($nt['unread_replies_count'] ?? 0);
                    if ($ratedVal) {
                        $status_text = $isTrStream ? "Puanlandı ({$ratedVal} ★)" : "Rated ({$ratedVal} ★)";
                        $status_class = 'badge-warning text-dark';
                    } else if ($nt['status'] === 'closed') {
                        $status_text = $isTrStream ? 'Bilet Kapatıldı' : 'Ticket Closed'; $status_class = 'badge-danger';
                    } else if ($nt['status'] === 'resolved') {
                        $status_text = $isTrStream ? 'Bilet Çözüldü' : 'Ticket Resolved'; $status_class = 'badge-info';
                    } else if ($nt['status'] === 'open' || $nt['status'] === 'assigned') {
                        if (intval($nt['reply_count'] ?? 0) == 0) {
                            $status_text = $isTrStream ? 'Yeni Bilet' : 'New Ticket'; $status_class = 'badge-success';
                        } else {
                            if ($isTrStream) {
                                $status_text = ($unread > 0) ? "Cevap Geldi ({$unread})" : 'Cevap Geldi';
                            } else {
                                $status_text = ($unread > 0) ? "New Reply ({$unread})" : 'New Reply';
                            }
                            $status_class = 'badge-warning text-dark';
                        }
                    } else if ($nt['status'] === 'waiting_customer') {
                        $status_text = $isTrStream ? 'Cevap Geldi' : 'New Reply'; $status_class = 'badge-warning text-dark';
                    }
                    $target_url = ($ratedVal && $user_role != 2)
                        ? 'raporlar?view=csat'
                        : ('bilet-detay/' . $nt['id']);

                    $unread_list[] = [
                        'id' => $nt['id'],
                        'ticket_no' => !empty($nt['ticket_no']) ? $nt['ticket_no'] : ('EA-' . $nt['id']),
                        'title' => $nt['title'],
                        'status_text' => $status_text,
                        'status_class' => $status_class,
                        'url' => $target_url
                    ];
                }
            }
        }
    } catch (Throwable $e) {}

    $response['unread_tickets'] = $c_unread;
    $response['unread_list'] = $unread_list;

    // Calculate total open tickets for the sidebar badge
    try {
        $openCond = "t.status IN ('open', 'assigned', 'waiting_customer', 'pending')";
        $c_total_open = $pdo->query("SELECT COUNT(t.id) FROM tickets t WHERE $openCond AND $tenantCond $whereCountNav")->fetchColumn();
        $response['total_open_tickets'] = (int)$c_total_open;
    } catch (Throwable $e) {}

    // 2. Global latest ticket check - use MAX(id) with NO user filter
    // Ensures new tickets are always detected regardless of role/filter
    try {
        $maxTicketRow = $pdo->query("SELECT MAX(id) as maxid FROM tickets")->fetch(PDO::FETCH_ASSOC);
        $maxTicketId = intval($maxTicketRow['maxid'] ?? 0);
        $response['latest_ticket_id'] = $maxTicketId;
        if ($last_ticket_id > 0 && $maxTicketId > $last_ticket_id) {
            $stmtNew = $pdo->prepare("SELECT id, ticket_no, title, create_date FROM tickets WHERE id = ?");
            $stmtNew->execute([$maxTicketId]);
            $newTicketData = $stmtNew->fetch(PDO::FETCH_ASSOC);
            if ($newTicketData) {
                $response['new_ticket_created'] = $newTicketData;
            }
        }
    } catch (Throwable $dbErr) {}

    // 3. Global latest reply check
    $stmtMaxR = $pdo->prepare("
        SELECT r.id, r.ticket_id, r.user_id, r.message, u.fullname as author_name, t.ticket_no, t.title as ticket_title
        FROM ticket_replies r 
        JOIN tickets t ON r.ticket_id = t.id
        LEFT JOIN users u ON r.user_id = u.id 
        WHERE r.reply_type != 'system' AND $tenantCond $whereCountNav
        ORDER BY r.id DESC 
        LIMIT 1
    ");
    $stmtMaxR->execute();
    $latestReply = $stmtMaxR->fetch(PDO::FETCH_ASSOC);

    if ($latestReply) {
        $response['latest_global_reply_id'] = intval($latestReply['id']);
        if ($last_global_reply_id > 0 && intval($latestReply['id']) > $last_global_reply_id && intval($latestReply['user_id']) !== $user_id) {
            $response['new_global_reply'] = $latestReply;
        }
    }

    // 3.5 Global latest ticket rating check
    try {
        $stmtMaxRating = $pdo->prepare("
            SELECT tr.id, tr.ticket_id, tr.user_id, tr.rating, tr.comment, u.fullname as customer_name, t.ticket_no, t.title as ticket_title
            FROM ticket_ratings tr
            JOIN tickets t ON tr.ticket_id = t.id
            LEFT JOIN users u ON tr.user_id = u.id
            ORDER BY tr.id DESC
            LIMIT 1
        ");
        $stmtMaxRating->execute();
        $latestRating = $stmtMaxRating->fetch(PDO::FETCH_ASSOC);
        if ($latestRating) {
            $response['latest_rating_id'] = intval($latestRating['id']);
            if ($last_rating_id > 0 && intval($latestRating['id']) > $last_rating_id && intval($latestRating['user_id']) !== $user_id) {
                $response['new_ticket_rating'] = $latestRating;
            }
        }
    } catch (Throwable $e) {}

    // 4. Ticket live replies & typing indicator if inside ticket detail
    if ($ticket_id > 0) {
        $stmtR = $pdo->prepare("
            SELECT r.*, u.fullname as author_name, u.profil_fotosu as avatar 
            FROM ticket_replies r 
            LEFT JOIN users u ON r.user_id = u.id 
            WHERE r.ticket_id = ? AND r.id > ? 
            ORDER BY r.id ASC
        ");
        $stmtR->execute([$ticket_id, $last_reply_id]);
        $newReplies = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        foreach ($newReplies as &$nr) {
            $nr['created_at_formatted'] = date('d.m.Y H:i', strtotime($nr['created_at']));
            $nr['is_me'] = (intval($nr['user_id']) === $user_id);
        }
        $response['new_replies'] = $newReplies;

        // Check typing state: scan all per-user typing files for this ticket (1.2s TTL)
        $logsDir = __DIR__ . '/../../../app/logs/';
        $typingUsers = [];
        $pattern = $logsDir . 'typing_ticket_' . $ticket_id . '_user_*.json';
        foreach (glob($pattern) as $typingFile) {
            $typingData = json_decode(@file_get_contents($typingFile), true);
            if (!$typingData) { @unlink($typingFile); continue; }
            $typingTime = floatval($typingData['time'] ?? 0);
            $typingUserId = intval($typingData['user_id'] ?? 0);
            if ((microtime(true) - $typingTime) > 1.2) {
                @unlink($typingFile); // expired
            } elseif ($typingUserId !== $user_id) {
                $typingUsers[] = $typingData['fullname'];
            }
        }
        if (!empty($typingUsers)) {
            $response['typing_user'] = implode(', ', $typingUsers);
        }
    }
} catch (Throwable $e) {}

echo "event: ping\n";
echo "data: " . json_encode($response) . "\n\n";
ob_flush();
flush();
exit;
