<?php
/**
 * Eaprimus Notification Helper
 * Consolidates Mail and Telegram notification logic.
 */

require_once __DIR__ . '/mailer.php';

function sendNewTicketNotifications($ticketId, $pdo) {
    // 1. Get Ticket Data
    $stmt = $pdo->prepare("
        SELECT t.*, q.name as queue_name, u.fullname as creator_name, c.name as customer_name, c.email as customer_email, ag.fullname as agent_name
        FROM tickets t
        LEFT JOIN queues q ON t.queue_id = q.id
        LEFT JOIN users u ON t.creator_id = u.id
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN users ag ON t.assigned_to = ag.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) return;

    // 2. Get Settings
    $settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    $siteUrl = rtrim($settings['site_url'] ?? '', '/');
    $siteTitle = $settings['site_title'] ?? 'Eaprimus';
    $ticketNo = $ticket['ticket_no'];
    $title = $ticket['title'];
    $description = $ticket['description'];
    $priority = $ticket['priority'];
    
    // Detect system default language
    $isCliOrWorker = (php_sapi_name() === 'cli' || defined('FROM_WORKER'));
    $lang = $isCliOrWorker ? ($settings['mail_default_lang'] ?? 'tr') : ($_SESSION['lang'] ?? $settings['mail_default_lang'] ?? 'tr');
    if ($lang !== 'en') $lang = 'tr';
    
    if ($lang === 'en') {
        $priorityLabels = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical ⚠️'];
        $prioLabel = $priorityLabels[$priority] ?? $priority;
        $agentNameVal = $ticket['agent_name'] ?? 'Unassigned';
    } else {
        $priorityLabels = ['low' => 'Düşük', 'normal' => 'Normal', 'high' => 'Yüksek', 'critical' => 'Kritik ⚠️'];
        $prioLabel = $priorityLabels[$priority] ?? $priority;
        $agentNameVal = $ticket['agent_name'] ?? 'Atanmamış';
    }

    // 3. TELEGRAM BİLDİRİMİ
    $tgToken = $settings['telegram_bot_token'] ?? '';
    $tgChatId = $settings['telegram_admin_chat_id'] ?? '';
    if (!empty($tgToken) && !empty($tgChatId)) {
        if ($lang === 'en') {
            $tgTpl = $settings['tg_new_ticket_en_tpl'] ?? "🔔 <b>NEW SUPPORT TICKET</b>\n\n📌 <b>Subject:</b> {{subject}}\n🔖 <b>Ticket No:</b> <code>{{ticket_no}}</code>\n⚡ <b>Priority:</b> {{priority}}\n📂 <b>Queue:</b> {{queue}}\n👤 <b>Requested By:</b> {{user_name}}\n🧑‍💻 <b>Assigned To:</b> {{agent_name}}\n\n📝 <b>Message:</b>\n{{message}}\n\n🔗 {{link}}";
        } else {
            $tgTpl = $settings['tg_new_ticket_tr_tpl'] ?? ($settings['tg_new_ticket_tpl'] ?? "🔔 <b>YENİ DESTEK TALEBİ</b>\n\n📌 <b>Konu:</b> {{subject}}\n🔖 <b>Bilet No:</b> <code>{{ticket_no}}</code>\n⚡ <b>Öncelik:</b> {{priority}}\n📂 <b>Kuyruk:</b> {{queue}}\n👤 <b>Talep Eden:</b> {{user_name}}\n🧑‍💻 <b>Atanan:</b> {{agent_name}}\n\n📝 <b>Mesaj:</b>\n{{message}}\n\n🔗 {{link}}");
        }
        
        $safeDesc = mb_substr(strip_tags(html_entity_decode($description, ENT_QUOTES, 'UTF-8')), 0, 400);
        if (!empty($ticket['is_forwarded'])) {
            $forwarderName = $ticket['forwarder_name'] ?: $ticket['forwarder_email'];
            if ($lang === 'en') {
                $safeDesc = "ℹ️ [Forwarded Request - by {$forwarderName}]\n\n" . $safeDesc;
            } else {
                $safeDesc = "ℹ️ [Yönlendirilmiş Talep - {$forwarderName} tarafından]\n\n" . $safeDesc;
            }
        }
        $ticketLink = $siteUrl . "/bilet-detay/" . $ticketId;
        
        $tgVars = [
            'subject' => $title,
            'ticket_no' => $ticketNo,
            'priority' => $prioLabel,
            'queue' => $ticket['queue_name'],
            'user_name' => $ticket['customer_name'] ?: $ticket['creator_name'],
            'agent_name' => $agentNameVal,
            'message' => $safeDesc,
            'link' => $ticketLink,
            'link_url' => $ticketLink,
            'TICKET_NO' => $ticketNo,
            'SUBJECT' => $title
        ];

        $tgMsg = $tgTpl;
        foreach ($tgVars as $k => $v) {
            $tgMsg = str_ireplace(['{{' . $k . '}}', '{{ ' . $k . ' }}', '[[' . $k . ']]', '[[ ' . $k . ' ]]'], (string)$v, $tgMsg);
        }

        $url = "https://api.telegram.org/bot{$tgToken}/sendMessage";
        $data = ['chat_id' => $tgChatId, 'text' => $tgMsg, 'parse_mode' => 'HTML'];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $tgResult = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            if (!isset($_SESSION['send_warnings'])) {
                $_SESSION['send_warnings'] = [];
            }
            $warnMsg = ($lang === 'en')
                ? "Telegram API error: Telegram notification could not be sent."
                : "Telegram API hatası: Telegram bildirimi gönderilemedi.";
            if (!in_array($warnMsg, $_SESSION['send_warnings'])) {
                $_SESSION['send_warnings'][] = $warnMsg;
            }
        }
    }

    // 3.5. SLACK, DISCORD & TEAMS WEBHOOK BİLDİRİMLERİ
    $webhookMsg = ($lang === 'en')
        ? "🔔 *NEW SUPPORT TICKET*\n\n*Subject:* {$title}\n*Ticket No:* `{$ticketNo}`\n*Priority:* {$prioLabel}\n*Queue:* " . ($ticket['queue_name'] ?: 'General') . "\n*Requested By:* " . ($ticket['customer_name'] ?: $ticket['creator_name']) . "\n\n*Link:* {$ticketLink}"
        : "🔔 *YENİ DESTEK TALEBİ*\n\n*Konu:* {$title}\n*Bilet No:* `{$ticketNo}`\n*Öncelik:* {$prioLabel}\n*Kuyruk:* " . ($ticket['queue_name'] ?: 'Genel') . "\n*Talep Eden:* " . ($ticket['customer_name'] ?: $ticket['creator_name']) . "\n\n*Bağlantı:* {$ticketLink}";

    // Slack
    $slackUrl = $settings['webhook_slack_url'] ?? '';
    if (!empty($slackUrl)) {
        ds_send_webhook($slackUrl, ['text' => $webhookMsg]);
    }

    // Discord
    $discordUrl = $settings['webhook_discord_url'] ?? '';
    if (!empty($discordUrl)) {
        // Convert single asterisks to double for Discord markdown headers
        $discordMsg = str_replace('*', '**', $webhookMsg);
        ds_send_webhook($discordUrl, ['content' => $discordMsg]);
    }

    // Microsoft Teams
    $teamsUrl = $settings['webhook_teams_url'] ?? '';
    if (!empty($teamsUrl)) {
        ds_send_webhook($teamsUrl, ['text' => $webhookMsg]);
    }

    // 4. MAİL BİLDİRİMLERİ
    $mailTargets = [];
    
    // Agent Notifications
    $qId = $ticket['queue_id'];
    
    // Correct logic: Get agents who are members of the team assigned to this queue
    $qStmt = $pdo->prepare("
        SELECT u.mail, u.fullname 
        FROM users u
        INNER JOIN teams_users tu ON u.id = tu.user_id
        INNER JOIN queues q ON tu.team_id = q.team_id
        WHERE q.id = ? AND u.status = 1 AND u.mail IS NOT NULL AND u.mail != ''
    ");
    $qStmt->execute([$qId]);
    foreach ($qStmt->fetchAll(PDO::FETCH_ASSOC) as $qa) {
        $mailTargets[] = ['mail' => $qa['mail'], 'name' => $qa['fullname'], 'type' => 'agent'];
    }

    // Also notify Admins (role = 1) who might not be in the team
    $adminStmt = $pdo->query("SELECT mail, fullname FROM users WHERE role = 1 AND status = 1 AND mail IS NOT NULL AND mail != ''");
    foreach ($adminStmt->fetchAll(PDO::FETCH_ASSOC) as $adm) {
        // Avoid duplicates
        $alreadyIn = false;
        foreach($mailTargets as $mt) { if($mt['mail'] === $adm['mail']) { $alreadyIn = true; break; } }
        if(!$alreadyIn) {
            $mailTargets[] = ['mail' => $adm['mail'], 'name' => $adm['fullname'], 'type' => 'agent'];
        }
    }

    // Customer Notification
    if (($settings['mail_new_ticket_cust_status'] ?? 'active') == 'active' && !empty($ticket['customer_email'])) {
        $mailTargets[] = ['mail' => $ticket['customer_email'], 'name' => $ticket['customer_name'], 'type' => 'customer'];
    }

    // Fetch Ticket Attachments
    $ticketAttachments = [];
    try {
        $attStmt = $pdo->prepare("SELECT file_name, file_path FROM ticket_attachments WHERE ticket_id = ? AND (reply_id IS NULL OR reply_id = 0)");
        $attStmt->execute([$ticketId]);
        $attList = $attStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($attList as $att) {
            $fpath = $att['file_path'];
            if (strpos($fpath, 'public/') === 0) {
                $fpath = substr($fpath, 7);
            }
            $fpath = ltrim($fpath, '/');
            
            $physicalPath = __DIR__ . '/../../public/' . $fpath;
            $downloadUrl = $siteUrl . '/' . $fpath;
            
            $ticketAttachments[] = [
                'path' => $physicalPath,
                'name' => $att['file_name'],
                'url'  => $downloadUrl
            ];
        }
    } catch (Exception $ex) {
        // ignore
    }

    foreach ($mailTargets as $target) {
        $vars = [
            'ticket_no'     => $ticketNo,
            'TICKET_NO'     => $ticketNo,
            'subject'       => $title,
            'SUBJECT'       => $title,
            'customer_name' => $ticket['customer_name'] ?: $ticket['creator_name'],
            'agent_name'    => ($target['type'] === 'agent') ? $target['name'] : '',
            'link'          => $siteUrl . "/bilet-detay/" . $ticketId,
            'site_title'    => $siteTitle,
            'message'       => $description,
            'Konu'          => $title,
            'Mesaj/Cevap İçeriği' => $description,
            'Müşteri Adı'   => $ticket['customer_name'] ?: $ticket['creator_name'],
            'Talep No'      => $ticketNo
        ];
        
        if ($target['type'] === 'agent') {
            if (($settings['mail_new_ticket_agent_status'] ?? 'active') == 'active') {
                sendTemplatedMail($target['mail'], $target['name'], 'new_ticket_agent', $vars, '', $lang, $ticketAttachments);
            }
        } else {
            sendTemplatedMail($target['mail'], $target['name'], 'new_ticket_cust', $vars, '', $lang, $ticketAttachments);
        }
    }
}

function ds_send_webhook(string $url, array $data): void
{
    if (empty($url)) return;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

function sendReplyNotifications($ticketId, $replyId, $pdo) {
    // 1. Get Ticket and Reply Data
    $stmt = $pdo->prepare("
        SELECT t.*, q.name as queue_name, u.fullname as creator_name, c.name as customer_name, c.email as customer_email, ag.fullname as agent_name, ag.mail as agent_mail
        FROM tickets t
        LEFT JOIN queues q ON t.queue_id = q.id
        LEFT JOIN users u ON t.creator_id = u.id
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN users ag ON t.assigned_to = ag.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) return;

    $stmtR = $pdo->prepare("
        SELECT r.*, u.fullname as replier_name, u.role as replier_role
        FROM ticket_replies r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.id = ?
    ");
    $stmtR->execute([$replyId]);
    $reply = $stmtR->fetch(PDO::FETCH_ASSOC);
    if (!$reply) return;

    // Do not notify if it's a private note (internal note) or system message
    if (!empty($reply['is_private']) || ($reply['reply_type'] ?? 'user') === 'system') return;

    // 2. Get Settings
    $settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    $siteUrl = rtrim($settings['site_url'] ?? '', '/');
    $siteTitle = $settings['site_title'] ?? 'Eaprimus';
    $ticketNo = $ticket['ticket_no'];
    $title = $ticket['title'];
    $replyMsg = $reply['message'];
    $replierName = $reply['replier_name'] ?: ($reply['customer_id'] ? ($ticket['customer_name'] ?: 'Müşteri') : 'Sistem');
    
    // Check if the replier is the customer/creator or staff
    $isReplierCustomer = ($reply['customer_id'] > 0 || (int)$reply['user_id'] === (int)$ticket['creator_id']);

    // Detect system default language
    $isCliOrWorker = (php_sapi_name() === 'cli' || defined('FROM_WORKER'));
    $lang = $isCliOrWorker ? ($settings['mail_default_lang'] ?? 'tr') : ($_SESSION['lang'] ?? $settings['mail_default_lang'] ?? 'tr');
    if ($lang !== 'en') $lang = 'tr';

    // 3. TELEGRAM NOTIFICATION
    $tgToken = $settings['telegram_bot_token'] ?? '';
    $tgChatId = $settings['telegram_admin_chat_id'] ?? '';
    if (!empty($tgToken) && !empty($tgChatId)) {
        if ($lang === 'en') {
            $tgTpl = "💬 <b>TICKET REPLY RECEIVED</b>\n\n📌 <b>Subject:</b> {{subject}}\n🔖 <b>Ticket No:</b> <code>{{ticket_no}}</code>\n👤 <b>Replied By:</b> {{user_name}}\n\n📝 <b>Message:</b>\n{{message}}\n\n🔗 {{link}}";
        } else {
            $tgTpl = "💬 <b>BİLETE YANIT GELDİ</b>\n\n📌 <b>Konu:</b> {{subject}}\n🔖 <b>Bilet No:</b> <code>{{ticket_no}}</code>\n👤 <b>Yanıtlayan:</b> {{user_name}}\n\n📝 <b>Mesaj:</b>\n{{message}}\n\n🔗 {{link}}";
        }
        
        $safeDesc = mb_substr(strip_tags(html_entity_decode($replyMsg, ENT_QUOTES, 'UTF-8')), 0, 400);
        $ticketLink = $siteUrl . "/bilet-detay/" . $ticketId;
        
        $tgVars = [
            'subject' => $title,
            'ticket_no' => $ticketNo,
            'user_name' => $replierName,
            'message' => $safeDesc,
            'link' => $ticketLink,
            'link_url' => $ticketLink,
            'TICKET_NO' => $ticketNo,
            'SUBJECT' => $title
        ];

        $tgMsg = $tgTpl;
        foreach ($tgVars as $k => $v) {
            $tgMsg = str_ireplace(['{{' . $k . '}}', '{{ ' . $k . ' }}', '[[' . $k . ']]', '[[ ' . $k . ' ]]'], (string)$v, $tgMsg);
        }

        $url = "https://api.telegram.org/bot{$tgToken}/sendMessage";
        $data = ['chat_id' => $tgChatId, 'text' => $tgMsg, 'parse_mode' => 'HTML'];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
    }

    // 4. SLACK, DISCORD & TEAMS WEBHOOK NOTIFICATIONS
    $ticketLink = $siteUrl . "/bilet-detay/" . $ticketId;
    $safeDesc = mb_substr(strip_tags(html_entity_decode($replyMsg, ENT_QUOTES, 'UTF-8')), 0, 400);
    $webhookMsg = ($lang === 'en')
        ? "💬 *TICKET REPLY RECEIVED*\n\n*Subject:* {$title}\n*Ticket No:* `{$ticketNo}`\n*Replied By:* {$replierName}\n\n*Message:* {$safeDesc}\n\n*Link:* {$ticketLink}"
        : "💬 *BİLETE YANIT GELDİ*\n\n*Konu:* {$title}\n*Bilet No:* `{$ticketNo}`\n*Yanıtlayan:* {$replierName}\n\n*Mesaj:* {$safeDesc}\n\n*Bağlantı:* {$ticketLink}";

    // Slack
    $slackUrl = $settings['webhook_slack_url'] ?? '';
    if (!empty($slackUrl)) {
        ds_send_webhook($slackUrl, ['text' => $webhookMsg]);
    }

    // Discord
    $discordUrl = $settings['webhook_discord_url'] ?? '';
    if (!empty($discordUrl)) {
        $discordMsg = str_replace('*', '**', $webhookMsg);
        ds_send_webhook($discordUrl, ['content' => $discordMsg]);
    }

    // Microsoft Teams
    $teamsUrl = $settings['webhook_teams_url'] ?? '';
    if (!empty($teamsUrl)) {
        ds_send_webhook($teamsUrl, ['text' => $webhookMsg]);
    }

    // 5. EMAIL NOTIFICATIONS
    $mailTargets = [];
    $mailAttachments = [];
    
    // Fetch Reply Attachments
    try {
        $attStmt = $pdo->prepare("SELECT file_name, file_path FROM ticket_attachments WHERE reply_id = ?");
        $attStmt->execute([$replyId]);
        $attList = $attStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($attList as $att) {
            $fpath = $att['file_path'];
            if (strpos($fpath, 'public/') === 0) {
                $fpath = substr($fpath, 7);
            }
            $fpath = ltrim($fpath, '/');
            $physicalPath = __DIR__ . '/../../public/' . $fpath;
            $downloadUrl = $siteUrl . '/' . $fpath;
            $mailAttachments[] = [
                'path' => $physicalPath,
                'name' => $att['file_name'],
                'url'  => $downloadUrl
            ];
        }
    } catch (Exception $ex) {}

    $vars = [
        'ticket_no'     => $ticketNo,
        'TICKET_NO'     => $ticketNo,
        'subject'       => $title,
        'SUBJECT'       => $title,
        'customer_name' => $ticket['customer_name'] ?: $ticket['creator_name'],
        'agent_name'    => $ticket['agent_name'] ?: '',
        'link'          => $siteUrl . "/bilet-detay/" . $ticketId,
        'site_title'    => $siteTitle,
        'message'       => $replyMsg,
        'Konu'          => $title,
        'Mesaj/Cevap İçeriği' => $replyMsg,
        'Müşteri Adı'   => $ticket['customer_name'] ?: $ticket['creator_name'],
        'Talep No'      => $ticketNo
    ];

    if ($isReplierCustomer) {
        // Send reply_agent mail to assigned agent
        if (!empty($ticket['agent_mail'])) {
            $vars['agent_name'] = $ticket['agent_name'];
            sendTemplatedMail($ticket['agent_mail'], $ticket['agent_name'], 'reply_agent', $vars, '', $lang, $mailAttachments);
        } else {
            // If unassigned, notify all admins/agents in the team
            $qId = $ticket['queue_id'];
            $qStmt = $pdo->prepare("
                SELECT u.mail, u.fullname 
                FROM users u
                INNER JOIN teams_users tu ON u.id = tu.user_id
                INNER JOIN queues q ON tu.team_id = q.team_id
                WHERE q.id = ? AND u.status = 1 AND u.mail IS NOT NULL AND u.mail != ''
            ");
            $qStmt->execute([$qId]);
            foreach ($qStmt->fetchAll(PDO::FETCH_ASSOC) as $qa) {
                $vars['agent_name'] = $qa['fullname'];
                sendTemplatedMail($qa['mail'], $qa['fullname'], 'reply_agent', $vars, '', $lang, $mailAttachments);
            }
        }
    } else {
        // Send reply_cust mail to customer
        if (!empty($ticket['customer_email'])) {
            sendTemplatedMail($ticket['customer_email'], $ticket['customer_name'], 'reply_cust', $vars, '', $lang, $mailAttachments);
        }
    }
}
