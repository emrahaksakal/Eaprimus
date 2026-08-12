<?php
require_once __DIR__ . '/../includes/session.php';
requireLogin();
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

// Only Master Admin
if ((int) $_SESSION['role'] !== 1) {
    include __DIR__ . "/403.php";
    return;
}

$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

// Actions
if (isset($_POST['action'])) {
    require_csrf_token();
    if ($_POST['action'] === 'delete_log' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("DELETE FROM mail_spam_logs WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $_SESSION['mesaj'] = $isTr ? "Kayıt silindi." : "Log deleted.";
    } elseif ($_POST['action'] === 'clear_all') {
        $pdo->exec("DELETE FROM mail_spam_logs");
        $_SESSION['mesaj'] = __("all_logs_cleared");
    } elseif ($_POST['action'] === 'release_to_ticket' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM mail_spam_logs WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $log = $stmt->fetch();
        if ($log) {
            // Check if user requested to remove from blocklist
            if (isset($_POST['remove_blocklist']) && $_POST['remove_blocklist'] == '1') {
                $blockStr = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'mail_block_list'")->fetchColumn() ?: '';
                $blocked = array_filter(array_map('trim', preg_split('/[,;\n\r]+/', strtolower($blockStr))));
                $fromEmail = strtolower($log['from_email']);
                $domain = strpos($fromEmail, '@') !== false ? substr($fromEmail, strpos($fromEmail, '@')) : ''; // e.g. @gmail.com
                
                $newBlocked = [];
                foreach ($blocked as $item) {
                    if ($item === $fromEmail || (!empty($domain) && ($item === $domain || $item === ltrim($domain, '@')))) {
                        continue; // remove
                    }
                    $newBlocked[] = $item;
                }
                
                $newBlockStr = implode(', ', $newBlocked);
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'mail_block_list'")->execute([$newBlockStr]);
            }

            // Create ticket
            $stmtU = $pdo->prepare("SELECT id FROM users WHERE mail = ?");
            $stmtU->execute([$log['from_email']]);
            $creatorId = $stmtU->fetchColumn();

            $customerId = null;
            if (!$creatorId) {
                $stmtC = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
                $stmtC->execute([$log['from_email']]);
                $customerId = $stmtC->fetchColumn();
                if (!$customerId) {
                    $namePart = explode('@', $log['from_email'])[0];
                    $pdo->prepare("INSERT INTO customers (name, email, source, created_at) VALUES (?, ?, 'email', NOW())")
                        ->execute([$namePart, $log['from_email']]);
                    $customerId = $pdo->lastInsertId();
                }
                $creatorId = $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn() ?: 1;
            }

            $defaultQueueId = $pdo->query("SELECT id FROM queues LIMIT 1")->fetchColumn() ?: 1;
            $ticketPrefix = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ticket_prefix'")->fetchColumn() ?: 'TCK';
            $newNo = $ticketPrefix . '-' . date('YmdHis');
            $cleanTitle = mb_substr($log['subject'], 0, 250);

            // REPLY CHECK: Check if this blocked email is actually a reply to an existing ticket
            $foundTicketId = null;
            
            // 1. Try matching prefix and number in the subject
            if (preg_match('/' . preg_quote($ticketPrefix) . '\s*[-#]?\s*(\d+)/i', $log['subject'], $matches)) {
                $ticketIdRaw = $matches[1];
                $ticketNo = $ticketPrefix . '-' . $ticketIdRaw;
                $stmtT = $pdo->prepare("SELECT id FROM tickets WHERE ticket_no = ?");
                $stmtT->execute([$ticketNo]);
                $foundTicketId = $stmtT->fetchColumn();
            }

            // 1.5. Fallback: Search subject for ANY 12-16 digit number (the timestamp part)
            if (!$foundTicketId && preg_match('/(\d{12,16})/', $log['subject'], $matches)) {
                $ticketIdRaw = $matches[1];
                $stmtT = $pdo->prepare("SELECT id FROM tickets WHERE ticket_no LIKE ?");
                $stmtT->execute(['%-' . $ticketIdRaw]);
                $foundTicketId = $stmtT->fetchColumn();
            }

            // 2. Fallback: Search body for the bilet-detay URL
            if (!$foundTicketId && !empty($log['body_snippet'])) {
                if (preg_match('/bilet-detay\/(\d+)/i', $log['body_snippet'], $bodyUrlMatches)) {
                    $ticketIdFromUrl = (int)$bodyUrlMatches[1];
                    $stmtT = $pdo->prepare("SELECT id FROM tickets WHERE id = ?");
                    $stmtT->execute([$ticketIdFromUrl]);
                    $foundTicketId = $stmtT->fetchColumn();
                }
            }

            // 3. Fallback: Search body for ticket number
            if (!$foundTicketId && !empty($log['body_snippet'])) {
                if (preg_match('/' . preg_quote($ticketPrefix) . '\s*[-#]?\s*(\d{8,16})/i', $log['body_snippet'], $bodyNoMatches)) {
                    $ticketIdRaw = $bodyNoMatches[1];
                    $stmtT = $pdo->prepare("SELECT id FROM tickets WHERE ticket_no LIKE ?");
                    $stmtT->execute(['%-' . $ticketIdRaw]);
                    $foundTicketId = $stmtT->fetchColumn();
                } elseif (preg_match('/(\d{12,16})/', $log['body_snippet'], $bodyNoMatches)) {
                    $ticketIdRaw = $bodyNoMatches[1];
                    $stmtT = $pdo->prepare("SELECT id FROM tickets WHERE ticket_no LIKE ?");
                    $stmtT->execute(['%-' . $ticketIdRaw]);
                    $foundTicketId = $stmtT->fetchColumn();
                }
            }

            if ($foundTicketId) {
                // Insert as reply to the existing ticket
                $stmtInsertReply = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, reply_type, created_at) VALUES (?, ?, ?, 'customer', NOW())");
                $stmtInsertReply->execute([$foundTicketId, $creatorId, $log['body_snippet']]);
                
                // Update ticket status to open, agent_read = 0, unread_replies_count = unread_replies_count + 1
                $pdo->prepare("UPDATE tickets SET status = 'open', agent_read = 0, unread_replies_count = unread_replies_count + 1, update_date = NOW() WHERE id = ?")
                    ->execute([$foundTicketId]);
                
                // Trigger notification for the new reply
                try {
                    if (file_exists(__DIR__ . '/../includes/notification_helper.php')) {
                        require_once __DIR__ . '/../includes/notification_helper.php';
                        if (function_exists('sendNewReplyNotification')) {
                            $stmtTInfo = $pdo->prepare("SELECT ticket_no FROM tickets WHERE id = ?");
                            $stmtTInfo->execute([$foundTicketId]);
                            $tNo = $stmtTInfo->fetchColumn();
                            sendNewReplyNotification($pdo, $foundTicketId, $tNo, $log['body_snippet']);
                        }
                    }
                } catch (Exception $e) {}

                // Delete spam log
                $pdo->prepare("DELETE FROM mail_spam_logs WHERE id = ?")->execute([$log['id']]);

                $_SESSION['mesaj'] = $isTr 
                    ? "E-posta yanıtı başarıyla serbest bırakıldı ve biletin içerisine eklendi." 
                    : "Email reply successfully released and appended to the ticket.";
            } else {
                // If it is a new email, create a new ticket
                $pdo->prepare("INSERT INTO tickets (ticket_no, title, description, status, queue_id, creator_id, customer_id, api_key_used, is_forwarded, forwarder_name, forwarder_email, assigned_to, priority, category_id, sla_due_date, create_date, update_date, closed_by, closed_date, resolved_date, agent_read, unread_replies_count) VALUES (?,?,?,?,?,?,?,NULL,0,NULL,NULL,NULL,'medium',NULL,DATE_ADD(NOW(), INTERVAL 24 HOUR),NOW(),NOW(),NULL,NULL,NULL,0,0)")
                    ->execute([$newNo, $cleanTitle, $log['body_snippet'], 'open', $defaultQueueId, $creatorId, $customerId]);
                $ticketId = $pdo->lastInsertId();

                // Run Ticket Rules and Notifications if function exists
                try {
                    if (file_exists(__DIR__ . '/../includes/rule_engine.php')) {
                        require_once __DIR__ . '/../includes/rule_engine.php';
                        if (function_exists('runTicketRules')) {
                            runTicketRules($pdo, $ticketId);
                        }
                    }
                    if (file_exists(__DIR__ . '/../includes/notification_helper.php')) {
                        require_once __DIR__ . '/../includes/notification_helper.php';
                        if (function_exists('sendNewTicketNotification')) {
                            sendNewTicketNotification($pdo, $ticketId);
                        }
                    }
                } catch (Exception $e) {}

                // Delete spam log
                $pdo->prepare("DELETE FROM mail_spam_logs WHERE id = ?")->execute([$log['id']]);

                $_SESSION['mesaj'] = $isTr 
                    ? "E-posta başarıyla serbest bırakıldı ve bilet oluşturuldu (#$newNo)." 
                    : "Email successfully released and ticket created (#$newNo).";
            }
        }
    }
    header("Location: anasayfa?route=mail-spam-logs");
    exit;
}

// Ensure table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS mail_spam_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_email VARCHAR(255),
    subject VARCHAR(255),
    reason VARCHAR(255),
    body_snippet TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$logs = $pdo->query("SELECT * FROM mail_spam_logs ORDER BY created_at DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

$mesaj = $_SESSION['mesaj'] ?? '';
unset($_SESSION['mesaj']);
?>

<div class="content-header">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center bg-white p-4 shadow-sm" style="border-radius:15px;">
            <div>
                <h1 class="m-0 font-weight-bold text-dark" style="font-size:22px;">
                    <i class="fas fa-shield-alt mr-2 text-danger"></i> <?= __("blocked_mail_logs") ?>
                </h1>
                <p class="text-muted mb-0 small"><?= __("spam_logs_desc") ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="anasayfa?route=sistem-ayarlari&tab=mail&scroll=spam_protection" class="btn btn-outline-primary btn-sm px-3" style="border-radius:10px;">
                    <i class="fas fa-cogs mr-1"></i><?= $isTr ? 'Spam Ayarları' : 'Spam Settings' ?>
                </a>
                <button type="button" class="btn btn-danger btn-sm px-3" style="border-radius:10px;" onclick="clearAllLogs()">
                    <i class="fas fa-trash-alt mr-1"></i> <?= __("clear_all") ?>
                </button>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">
        <?php if ($mesaj): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm">
                <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($mesaj) ?>
                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0" style="border-radius:15px; overflow:hidden;">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light text-muted small text-uppercase">
                            <tr>
                                <th style="width:180px;"><?= __("date") ?></th>
                                <th style="width:250px;"><?= __("from_label") ?></th>
                                <th><?= $isTr ? 'Konu / Sebep' : 'Subject / Reason' ?></th>
                                <th style="width:120px;" class="text-center"><?= __("action") ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-5 text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3 opacity-20"></i>
                                        <p class="mb-0"><?= __("no_spam_logs") ?></p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="align-middle small">
                                            <div class="font-weight-bold text-dark"><?= date('d.m.Y', strtotime($log['created_at'])) ?></div>
                                            <div class="text-muted"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
                                        </td>
                                        <td class="align-middle">
                                            <div class="text-dark font-weight-bold" style="font-size:14px;"><?= htmlspecialchars($log['from_email']) ?></div>
                                        </td>
                                        <td class="align-middle">
                                            <div class="d-flex align-items-center mb-1">
                                                <span class="badge badge-danger mr-2 px-2 py-1" style="border-radius:6px;"><?= htmlspecialchars($log['reason']) ?></span>
                                                <div class="text-dark font-weight-bold text-truncate" style="max-width:500px;"><?= htmlspecialchars($log['subject'] ?: '(No Subject)') ?></div>
                                            </div>
                                            <div class="text-muted small text-truncate" style="max-width:600px;">
                                                <?= htmlspecialchars(mb_substr(strip_tags($log['body_snippet']), 0, 150)) ?>...
                                            </div>
                                        </td>
                                        <td class="align-middle text-center text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary border-0 mr-1" onclick="viewLogDetails(<?= htmlspecialchars(json_encode($log)) ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="deleteLog(<?= $log['id'] ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
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
</section>

<!-- Modal: Log Details -->
<div class="modal fade" id="logDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius:15px; border:none;">
            <div class="modal-header bg-light">
                <h5 class="modal-title font-weight-bold text-dark mb-0">
                    <i class="fas fa-envelope-open-text mr-2 text-primary"></i> <?= $isTr ? 'Engellenen E-posta Detayı' : 'Blocked Email Details' ?>
                </h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm small mb-3">
                    <tr>
                        <th style="width:150px;" class="bg-light"><?= $isTr ? 'Gönderen' : 'From' ?></th>
                        <td id="detFrom" class="font-weight-bold"></td>
                    </tr>
                    <tr>
                        <th class="bg-light"><?= $isTr ? 'Tarih' : 'Date' ?></th>
                        <td id="detDate"></td>
                    </tr>
                    <tr>
                        <th class="bg-light"><?= $isTr ? 'Konu' : 'Subject' ?></th>
                        <td id="detSubject" class="font-weight-bold text-dark"></td>
                    </tr>
                    <tr>
                        <th class="bg-light"><?= $isTr ? 'Engellenme Sebebi' : 'Block Reason' ?></th>
                        <td>
                            <span id="detReasonBadge" class="badge badge-danger px-2 py-1"></span>
                            <small id="detReasonDesc" class="text-muted d-block mt-1"></small>
                        </td>
                    </tr>
                </table>
                <label class="font-weight-bold text-dark text-sm"><?= $isTr ? 'E-posta İçeriği' : 'Email Content' ?></label>
                <div id="detBody" class="p-3 border rounded bg-light" style="max-height: 250px; overflow-y: auto; font-family: monospace; white-space: pre-wrap; font-size:12px;"></div>
            </div>
            <div class="modal-footer bg-light d-flex justify-content-between">
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __("close") ?></button>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" id="btnReleaseBlocklist" class="btn btn-warning btn-sm" style="border-radius:10px;">
                        <i class="fas fa-user-check mr-1"></i> <?= $isTr ? 'İzin Ver ve Bilet Yap' : 'Allow & Convert' ?>
                    </button>
                    <button type="button" id="btnReleaseOnly" class="btn btn-success btn-sm" style="border-radius:10px;">
                        <i class="fas fa-ticket-alt mr-1"></i> <?= $isTr ? 'Bilete Dönüştür' : 'Convert to Ticket' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentSelectedLog = null;

function viewLogDetails(log) {
    currentSelectedLog = log;
    $('#detFrom').text(log.from_email);
    $('#detSubject').text(log.subject || '(No Subject)');
    $('#detDate').text(new Date(log.created_at).toLocaleString());
    $('#detReasonBadge').text(log.reason);
    $('#detBody').text(log.body_snippet || '');

    // Explain the reason
    let desc = '';
    if (log.reason === 'Blocklist Match') {
        desc = '<?= $isTr ? "Göndericinin e-posta adresi veya domaini Engellenen E-posta/Domain listesinde bulunuyor." : "The sender email or domain is present in the blocklist settings." ?>';
        $('#btnReleaseBlocklist').show();
    } else if (log.reason === 'Spam Keyword Match') {
        desc = '<?= $isTr ? "E-posta konusu veya gövdesinde yasaklı kelimeler (Spam Kelime Filtresi) tespit edildi." : "Blacklisted spam keywords were detected in the subject or body." ?>';
        $('#btnReleaseBlocklist').hide();
    } else if (log.reason === 'User Hourly Limit Exceeded') {
        desc = '<?= $isTr ? "Bu kullanıcının belirlenen saatlik azami bilet oluşturma limiti aşılmıştır." : "This user has exceeded the configured hourly ticket creation limit." ?>';
        $('#btnReleaseBlocklist').hide();
    } else if (log.reason === 'System Hourly Limit Exceeded') {
        desc = '<?= $isTr ? "Sistem genelindeki saatlik azami bilet oluşturma limiti aşılmıştır." : "The system-wide hourly ticket creation limit has been exceeded." ?>';
        $('#btnReleaseBlocklist').hide();
    } else {
        desc = '<?= $isTr ? "Sistem otomatik filtresine veya diğer spam kurallarına takıldı." : "Blocked by automatic loop prevention or other anti-spam rules." ?>';
        $('#btnReleaseBlocklist').hide();
    }
    $('#detReasonDesc').text(desc);
    $('#logDetailsModal').modal('show');
}

$('#btnReleaseOnly').on('click', function() {
    if (!currentSelectedLog) return;
    Swal.fire({
        title: '<?= $isTr ? "Bilete Dönüştür" : "Convert to Ticket" ?>?',
        text: '<?= $isTr ? "Bu mail spam kutusundan çıkarılıp yeni bir destek bileti olarak oluşturulacaktır." : "This email will be released from spam and converted into a new support ticket." ?>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<?= $isTr ? "Evet, Dönüştür" : "Yes, Convert" ?>',
        cancelButtonText: '<?= __("cancel") ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="release_to_ticket"><input type="hidden" name="id" value="' + currentSelectedLog.id + '">';
            document.body.appendChild(form);
            form.submit();
        }
    });
});

$('#btnReleaseBlocklist').on('click', function() {
    if (!currentSelectedLog) return;
    Swal.fire({
        title: '<?= $isTr ? "İzin Ver ve Bilet Yap" : "Allow & Convert" ?>?',
        text: '<?= $isTr ? "Gönderici adresi Engellenenler listesinden kaldırılacak ve e-posta bilet yapılacaktır." : "The sender email/domain will be removed from the blocklist and converted to a ticket." ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<?= $isTr ? "Evet, İzin Ver ve Yap" : "Yes, Allow & Convert" ?>',
        cancelButtonText: '<?= __("cancel") ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="release_to_ticket"><input type="hidden" name="id" value="' + currentSelectedLog.id + '"><input type="hidden" name="remove_blocklist" value="1">';
            document.body.appendChild(form);
            form.submit();
        }
    });
});

function deleteLog(id) {
    Swal.fire({
        title: '<?= __("are_you_sure") ?>',
        text: '<?= $isTr ? "Bu kayıt silinecektir." : "This log will be deleted." ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<?= __("yes_delete") ?>',
        cancelButtonText: '<?= __("cancel") ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete_log"><input type="hidden" name="id" value="' + id + '">';
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function clearAllLogs() {
    Swal.fire({
        title: '<?= __("clear_all") ?>?',
        text: '<?= $isTr ? "Tüm engellenmiş mail kayıtları kalıcı olarak silinecektir." : "All blocked email logs will be permanently deleted." ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<?= $isTr ? "Evet, Temizle" : "Yes, Clear All" ?>',
        cancelButtonText: '<?= __("cancel") ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="clear_all">';
            document.body.appendChild(form);
            form.submit();
        }
    });
}
</script>
