<?php
/**
 * Eaprimus Mail Gateway (Module for Worker)
 * -----------------------------------------------------------------------
 * Handles IMAP -> Ticket / Reply conversion using UID tracking.
 */

if (php_sapi_name() !== 'cli' && !defined('FROM_WORKER')) {
    // Master worker define'ı yoksa ve CLI değilse engelle
    die("Error: This module must be called via the Master Worker.");
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/notification_helper.php';

$processedUidsFile = __DIR__ . '/processed_uids.txt';
$logFile = __DIR__ . '/../logs/imap_listener.log';

function sanitize_utf8($string) {
    if ($string === null || $string === '') return '';
    if (!mb_check_encoding($string, 'UTF-8')) {
        $string = @mb_convert_encoding($string, 'UTF-8', 'Windows-1254');
    }
    return mb_convert_encoding($string, 'UTF-8', 'UTF-8');
}

function logMsg(string $msg, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [GATEWAY] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

function getProcessedUids(string $file): array {
    if (!file_exists($file)) return [];
    $uids = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return $uids ? array_flip($uids) : [];
}

function addProcessedUid(string $file, string $uid): void {
    file_put_contents($file, $uid . PHP_EOL, FILE_APPEND);
}

function decodeMailContent($inbox, int $num): array {
    $struct = imap_fetchstructure($inbox, $num);
    $html = ''; $plain = '';
    $attachments = [];
    $cidMap = [];

    $uploadDir = __DIR__ . '/../../public/uploads/tickets';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }

    $parse = function ($parts, string $prefix = '') use (&$parse, $inbox, $num, &$html, &$plain, &$attachments, &$cidMap, $uploadDir): void {
        foreach ($parts as $i => $part) {
            $partNum = $prefix . ($i + 1);

            $isAttachment = false;
            $filename = '';
            $cid = '';

            if ($part->ifdparameters) {
                foreach ($part->dparameters as $dp) {
                    if (strtolower($dp->attribute) === 'filename') {
                        $filename = $dp->value;
                        $isAttachment = true;
                    }
                }
            }
            if ($part->ifparameters) {
                foreach ($part->parameters as $p) {
                    if (strtolower($p->attribute) === 'name') {
                        $filename = $p->value;
                        $isAttachment = true;
                    }
                }
            }
            if ($part->ifid) {
                $cid = trim($part->id, '<>');
                $isAttachment = true;
            }

            if ($part->type == 0 && !$isAttachment) {
                $raw = imap_fetchbody($inbox, $num, $partNum);
                if ($part->encoding == 3) $raw = imap_base64($raw);
                elseif ($part->encoding == 4) $raw = imap_qprint($raw);

                $charset = 'UTF-8';
                if ($part->ifparameters) {
                    foreach ($part->parameters as $p) {
                        if (strtolower($p->attribute) === 'charset') {
                            $charset = $p->value;
                            break;
                        }
                    }
                }
                if (strtoupper($charset) !== 'UTF-8') {
                    $raw = @mb_convert_encoding($raw, 'UTF-8', $charset);
                } else {
                    $raw = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
                }

                if (strtoupper($part->subtype) === 'HTML') $html .= $raw;
                elseif (strtoupper($part->subtype) === 'PLAIN') $plain .= $raw;
            }
            elseif ($isAttachment) {
                $raw = imap_fetchbody($inbox, $num, $partNum);
                if ($part->encoding == 3) $raw = imap_base64($raw);
                elseif ($part->encoding == 4) $raw = imap_qprint($raw);

                if (empty($filename)) {
                    $ext = strtolower($part->subtype ?? 'bin');
                    $filename = 'file_' . uniqid() . '.' . $ext;
                }

                $safeFilename = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', $filename);
                $uniqueName = time() . '_' . uniqid() . '_' . $safeFilename;
                $savePath = $uploadDir . '/' . $uniqueName;

                file_put_contents($savePath, $raw);

                $fileSize = strlen($raw);
                $fileType = (isset($part->type) && isset($part->subtype)) ? strtolower($part->type . '/' . $part->subtype) : 'application/octet-stream';

                $attData = [
                    'name' => $safeFilename,
                    'path' => 'public/uploads/tickets/' . $uniqueName,
                    'type' => $fileType,
                    'size' => $fileSize
                ];

                // Exclude small inline images, social media icons, and signature graphics from the ticket attachments list.
                $isImage = ($part->type == 5 || strpos($fileType, 'image/') === 0);
                $isSignatureClutter = $isImage && (
                    $fileSize < 51200 || 
                    (!empty($cid) && $fileSize < 102400) || 
                    preg_match('/^(image\d+|img_[a-f0-9]+|logo|signature|facebook|twitter|linkedin|instagram|youtube|social)/i', $safeFilename)
                );

                if (!$isSignatureClutter) {
                    $attachments[] = $attData;
                }

                if (!empty($cid)) {
                    $cidMap[$cid] = '/' . $attData['path'];
                }
            }

            if ($part->type == 1 && isset($part->parts)) {
                $parse($part->parts, $partNum . '.');
            }
        }
    };

    if (isset($struct->parts)) {
        $parse($struct->parts);
    } else {
        $raw = imap_fetchbody($inbox, $num, 1);
        if ($struct->encoding == 3) $raw = imap_base64($raw);
        elseif ($struct->encoding == 4) $raw = imap_qprint($raw);

        $charset = 'UTF-8';
        if ($struct->ifparameters) {
            foreach ($struct->parameters as $p) {
                if (strtolower($p->attribute) === 'charset') {
                    $charset = $p->value;
                    break;
                }
            }
        }
        if (strtoupper($charset) !== 'UTF-8') {
            $raw = @mb_convert_encoding($raw, 'UTF-8', $charset);
        } else {
            $raw = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        }

        if (isset($struct->subtype) && strtoupper($struct->subtype) === 'HTML') $html = $raw;
        else $plain = $raw;
    }

    $body = !empty($html) ? $html : nl2br(htmlspecialchars((string) $plain));

    if (!empty($cidMap)) {
        foreach ($cidMap as $cid => $url) {
            $body = preg_replace('/src=["\']cid:' . preg_quote($cid, '/') . '["\']/i', 'src="' . $url . '"', $body);
        }
    }

    return [
        'body' => trim($body),
        'attachments' => $attachments
    ];
}

function cleanEmailReply(string $body): string {
    if (empty($body)) return '';

    // Remove common HTML quote containers and everything after them
    $body = preg_replace('/<div class=["\']gmail_quote["\'].*$/is', '', $body);
    $body = preg_replace('/<div class=["\']gmail_extra["\'].*$/is', '', $body);
    $body = preg_replace('/<div id=["\']divRplyFwdMsg["\'].*$/is', '', $body);
    $body = preg_replace('/<div id=["\']appendonsend["\'].*$/is', '', $body);
    $body = preg_replace('/<div class=["\']outlook_reply["\'].*$/is', '', $body);
    $body = preg_replace('/<div id=["\']divRpFwdMsg["\'].*$/is', '', $body);
    $body = preg_replace('/<blockquote.*$/is', '', $body);

    // Remove text-based separators
    // Matches "7 Tem 2026 Sal 13:14 tarihinde Eaprimus A.Ş. <ticket@gursoylar.com> şunu yazdı:"
    $body = preg_replace('/\d{1,2}\s+(?:Oca|Şub|Mar|Nis|May|Haz|Tem|Ağu|Eyl|Eki|Kas|Ara|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[^\n]*?tarihinde[^\n]*?şunu yazdı:.*$/is', '', $body);
    // Matches "On Tue, Jul 7, 2026 at 1:14 PM, Eaprimus A.Ş. <ticket@gursoylar.com> wrote:"
    $body = preg_replace('/On\s+[^\n]+?\s+at\s+[^\n]+?\s+wrote:.*$/is', '', $body);
    $body = preg_replace('/On\s+[^\n]+?\s+wrote:.*$/is', '', $body);
    // Outlook style
    $body = preg_replace('/-----Original Message-----.*$/is', '', $body);
    $body = preg_replace('/-----Özgün İleti-----.*$/is', '', $body);
    $body = preg_replace('/----- Özgün İleti -----.*$/is', '', $body);
    $body = preg_replace('/Kimden\s*:\s*.*$/iu', '', $body);
    $body = preg_replace('/From\s*:\s*.*$/iu', '', $body);

    // Use DOMDocument to safely clean and close any open HTML tags we might have cut
    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // Load HTML with XML encoding header to preserve UTF-8 characters
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $cleanHtml = $dom->saveHTML();
        $cleanHtml = str_replace('<?xml encoding="utf-8" ?>', '', $cleanHtml);
        return trim($cleanHtml);
    }

    return trim($body);
}

function decodeSubject(string $subject): string {
    if (empty($subject)) return '(Konusuz)';
    $decoded = @iconv_mime_decode($subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if ($decoded) return $decoded;
    return @imap_utf8($subject);
}

function isSpamCheck($fromEmail, $subject, $body, $pdo, $settings) {
    // Basic internal filters
    if (stripos($fromEmail, 'mailer-daemon') !== false || stripos($fromEmail, 'no-reply') !== false) return "System Mail Filter";

    // Self-mail loop prevention
    $mailUser = $settings['mail_username'] ?? '';
    if (!empty($mailUser) && strtolower($fromEmail) === strtolower($mailUser)) return "Self-Loop Prevention";

    // Loop protection for automated responses
    $sPrefix = $settings['ticket_prefix'] ?? 'TCK';
    $hasTicketNo = preg_match('/' . preg_quote($sPrefix) . '-?(\d+)/i', $subject);
    if (
        (stripos($subject, 'Destek Talebiniz Alındı') !== false || stripos($subject, 'Ticket Received') !== false)
        && !$hasTicketNo
    ) {
        return "Auto-Response Filter";
    }

    // Allowed Domains
    $allowedStr = trim($settings['mail_allowed_domains'] ?? '');
    if (!empty($allowedStr)) {
        $allowed = array_filter(array_map('trim', preg_split('/[,;\n\r]+/', strtolower($allowedStr))));
        $domainMatch = false;
        foreach ($allowed as $domain) {
            if (empty($domain)) continue;
            if (strpos($domain, '@') === false) $domain = '@' . $domain;
            if (stripos($fromEmail, $domain) !== false) {
                $domainMatch = true;
                break;
            }
        }
        if (!$domainMatch) return "Domain Restricted";
    }

    // Block List
    $blockStr = trim($settings['mail_block_list'] ?? '');
    if (!empty($blockStr)) {
        $blocked = array_filter(array_map('trim', preg_split('/[,;\n\r]+/', strtolower($blockStr))));
        foreach ($blocked as $item) {
            if (empty($item)) continue;
            if (stripos($fromEmail, $item) !== false) return "Blocklist Match";
        }
    }

    // Spam Keywords
    $keywordStr = trim($settings['mail_spam_keywords'] ?? '');
    if (!empty($keywordStr)) {
        $keywords = array_filter(array_map('trim', preg_split('/[,;\n\r]+/', strtolower($keywordStr))));
        foreach ($keywords as $kw) {
            if (empty($kw)) continue;
            if (stripos($subject, $kw) !== false || stripos($body, $kw) !== false) return "Spam Keyword Match";
        }
    }

    // Check hourly limit per user/email
    $maxPerUserHour = intval($settings['mail_max_tickets_per_user_hour'] ?? 0);
    if ($maxPerUserHour > 0) {
        $stmtFindUser = $pdo->prepare("SELECT id FROM users WHERE mail = ?");
        $stmtFindUser->execute([$fromEmail]);
        $uId = (int)$stmtFindUser->fetchColumn();

        $stmtFindCustomer = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
        $stmtFindCustomer->execute([$fromEmail]);
        $cId = (int)$stmtFindCustomer->fetchColumn();

        $sqlCount = "SELECT COUNT(*) FROM tickets WHERE (";
        $conds = [];
        if ($uId > 0) $conds[] = "creator_id = $uId";
        if ($cId > 0) $conds[] = "customer_id = $cId";
        $conds[] = "forwarder_email = " . $pdo->quote($fromEmail);
        $sqlCount .= implode(" OR ", $conds);
        $sqlCount .= ") AND create_date >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";

        $ticketsSent = (int)$pdo->query($sqlCount)->fetchColumn();
        if ($ticketsSent >= $maxPerUserHour) {
            return "User Hourly Limit Exceeded";
        }
    }

    // Check hourly limit total system wide
    $maxTotalHour = intval($settings['mail_max_tickets_total_hour'] ?? 0);
    if ($maxTotalHour > 0) {
        $totalTickets = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE create_date >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
        if ($totalTickets >= $maxTotalHour) {
            return "System Hourly Limit Exceeded";
        }
    }

    return false;
}

function logSpam($pdo, $email, $subject, $body, $reason) {
    try {
        $snippet = mb_substr(strip_tags($body), 0, 500);
        $stmt = $pdo->prepare("INSERT INTO mail_spam_logs (from_email, subject, reason, body_snippet, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$email, $subject, $reason, $snippet]);
    } catch (Exception $e) {
        error_log("Spam logging failed: " . $e->getMessage());
    }
}

function findUserIdByEmail(PDO $pdo, string $email) {
    $s = $pdo->prepare("SELECT id FROM users WHERE mail=?");
    $s->execute([$email]);
    return $s->fetchColumn();
}

function getOrCreateCustomer(PDO $pdo, string $email, string $name) {
    $s = $pdo->prepare("SELECT id, organization_id FROM customers WHERE email=?");
    $s->execute([$email]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    $pdo->prepare("INSERT INTO customers (name, email, source, created_at) VALUES (?,?,?,NOW())")->execute([$name, $email, 'email']);
    return ['id' => $pdo->lastInsertId(), 'organization_id' => null];
}

function extractForwardedHeaders($body) {
    $details = [
        'from_name' => '',
        'from_email' => '',
        'date' => '',
        'subject' => ''
    ];

    $normalized = preg_replace('/<br\s*\/?>/i', "\n", $body);
    $normalized = preg_replace('/<\/?(?:div|p)[^>]*>/i', "\n", $normalized);
    $lines = explode("\n", $normalized);

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $plain = trim(strip_tags(html_entity_decode($line, ENT_QUOTES, 'UTF-8')));

        if (preg_match('/^(?:Gönderen|From|Kimden|Sender)\s*:/iu', $plain)) {
            if (preg_match('/\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b/i', $line, $mailMatches)) {
                $details['from_email'] = $mailMatches[0];

                $namePart = preg_replace('/^(?:Gönderen|From|Kimden|Sender)\s*:/iu', '', $line);
                $namePart = preg_replace('/' . preg_quote($details['from_email'], '/') . '.*/i', '', $namePart);
                $namePart = strip_tags(html_entity_decode($namePart, ENT_QUOTES, 'UTF-8'));
                $namePart = preg_replace('/[<>&\'"\(\)\[\]\s*:-]+$/u', '', $namePart);
                $namePart = preg_replace('/^[<>&\'"\(\)\[\]\s*:-]+/u', '', $namePart);

                $details['from_name'] = trim($namePart);
            }
        } elseif (preg_match('/^(?:Date|Tarih|Gönderildi)\s*:\s*(.*)/iu', $plain, $matches)) {
            $details['date'] = trim($matches[1]);
        } elseif (preg_match('/^(?:Subject|Konu)\s*:\s*(.*)/iu', $plain, $matches)) {
            $details['subject'] = trim($matches[1]);
        }
    }

    return $details;
}

function cleanForwardedBody($body) {
    $normalized = preg_replace('/<br\s*\/?>/i', "\n", $body);
    $lines = explode("\n", $normalized);
    $cleanLines = [];

    $inHeaderBlock = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        $plain = trim(strip_tags(html_entity_decode($trimmed, ENT_QUOTES, 'UTF-8')));

        // Detect divider
        if (preg_match('/-+\s*(?:Forwarded\s*message|Original\s*Message|Özgün\s*İleti|Yönlendirilen\s*İleti)\s*-+/i', $plain) ||
            preg_match('/^(?:Begin\s*forwarded\s*message:|Yönlendirilen\s*İleti:)$/i', $plain)) {
            $inHeaderBlock = true;
            continue;
        }

        // Detect header lines
        if (preg_match('/^(?:Gönderen|From|Kimden|Sender)\s*:/iu', $plain) ||
            preg_match('/^(?:Date|Tarih|Gönderildi)\s*:/iu', $plain) ||
            preg_match('/^(?:Subject|Konu)\s*:/iu', $plain) ||
            preg_match('/^(?:To|Kime)\s*:/iu', $plain)) {
            $inHeaderBlock = true;
            continue;
        }

        if ($inHeaderBlock) {
            if (empty($plain)) {
                continue;
            } else {
                $inHeaderBlock = false;
            }
        }

        $cleanLines[] = $line;
    }

    return trim(implode("<br>", $cleanLines));
}

// MAIN START
try {
    $pdo = db();
    $settingsArr = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    $mailHost = $settingsArr['mail_host'] ?? '';
    $mailUser = $settingsArr['mail_username'] ?? '';
    $mailPass = $settingsArr['mail_password'] ?? '';

    $langQuery = $settingsArr['mail_default_lang'] ?? 'tr';
    $defaultLang = in_array(trim($langQuery), ['tr', 'en']) ? trim($langQuery) : 'tr';
    $isTr = false;

    if (empty($mailHost) || empty($mailUser)) {
        logMsg($isTr ? "HATA: E-posta ayarları (host/username) eksik." : "ERROR: Email settings (host/username) missing.", $logFile);
        return;
    }

    // Set 15-second timeouts to prevent cron workers from hanging indefinitely and crashing PHP-FPM
    @imap_timeout(IMAP_OPENTIMEOUT, 15);
    @imap_timeout(IMAP_READTIMEOUT, 15);
    @imap_timeout(IMAP_WRITETIMEOUT, 15);

    $imapStr = '{' . $mailHost . ':143/imap/notls}INBOX';
    $inbox = @imap_open($imapStr, $mailUser, $mailPass);

    if (!$inbox) {
        logMsg($isTr ? "HATA: IMAP bağlantısı kurulamadı: " . imap_last_error() : "ERROR: IMAP connection failed: " . imap_last_error(), $logFile);
    } else {
        logMsg($isTr ? "IMAP Bağlandı: $mailHost" : "IMAP Connected: $mailHost", $logFile);

        $totalMsgs = @imap_num_msg($inbox);
        logMsg($isTr ? "Gelen kutusundaki toplam e-posta sayısı: $totalMsgs" : "Total emails in INBOX: $totalMsgs", $logFile);

        $processedUids = getProcessedUids($processedUidsFile);
        $emails = imap_search($inbox, 'UNSEEN', SE_UID);
        $unseenCount = $emails ? count($emails) : 0;
        logMsg($isTr ? "Okunmamış (UNSEEN) e-posta sayısı: $unseenCount" : "Unseen (UNSEEN) email count: $unseenCount", $logFile);

        // Fallback: If no unseen emails exist, check ALL emails from the last 2 days (both read and unread)
        // to catch emails already marked as read by other devices/clients but not yet processed.
        if (!$emails || count($emails) === 0) {
            $sinceDate = date('j-M-Y', strtotime('-2 days'));
            $emails = imap_search($inbox, 'SINCE "' . $sinceDate . '"', SE_UID);
            $fallbackCount = $emails ? count($emails) : 0;
            if ($fallbackCount > 0) {
                logMsg($isTr ? "Okunmuş e-postalar için son 2 gün kontrol ediliyor. Bulunan: $fallbackCount" : "Checking last 2 days for fallback emails. Found: $fallbackCount", $logFile);
            }
        }

        if ($emails) {
            foreach ($emails as $uid) {
                if (isset($processedUids[$uid])) continue;

                $msgNum = imap_msgno($inbox, $uid);
                if ($msgNum > 0) {
                    try {
                        $overview = imap_fetch_overview($inbox, $msgNum, 0);

                        // Limit fallback emails to the last 6 hours to avoid importing old history, and ignore emails sent before installation
                        $mailDateStr = $overview[0]->date ?? '';
                        if (!empty($mailDateStr)) {
                            $mailTimestamp = strtotime($mailDateStr);
                            
                            static $installTime = null;
                            if ($installTime === null) {
                                try {
                                    $minCreated = $pdo->query("SELECT MIN(created_at) FROM users")->fetchColumn();
                                    $installTime = $minCreated ? strtotime($minCreated) : time();
                                } catch (Throwable $e) {
                                    $installTime = time();
                                }
                            }

                            if ($mailTimestamp && ($mailTimestamp < $installTime || $mailTimestamp < (time() - 21600))) {
                                addProcessedUid($processedUidsFile, (string)$uid);
                                continue;
                            }
                        }

                        $headerInfo = imap_headerinfo($inbox, $msgNum);
                        $mailContent = decodeMailContent($inbox, $msgNum);
                        $body = sanitize_utf8(cleanEmailReply($mailContent['body']));
                        $attachments = $mailContent['attachments'];
                        $subjectToken = sanitize_utf8(decodeSubject($overview[0]->subject ?? ''));
                        $fromEmail = sanitize_utf8(($headerInfo->from[0]->mailbox ?? 'unknown') . '@' . ($headerInfo->from[0]->host ?? 'unknown'));
                        $fromName = sanitize_utf8(imap_utf8($headerInfo->from[0]->personal ?? $fromEmail));

                        $reason = isSpamCheck($fromEmail, $subjectToken, $body, $pdo, $settingsArr);
                        if ($reason) {
                            logSpam($pdo, $fromEmail, $subjectToken, $body, $reason);
                            addProcessedUid($processedUidsFile, (string)$uid);
                            continue;
                        }

                        $sPrefix = trim($settingsArr['ticket_prefix'] ?? 'TCK');

                        // REPLY CHECK
                        $foundTicketId = null;
                        $parsedTicketNoRaw = null;

                        // 1. Try matching the ticket prefix and number in the subject (case-insensitive and robust whitespace/separator support)
                        if (preg_match('/' . preg_quote($sPrefix) . '\s*[-#]?\s*(\d+)/i', $subjectToken, $matches)) {
                            $ticketIdRaw = $matches[1];
                            $parsedTicketNoRaw = $ticketIdRaw;
                            $ticketNo = $sPrefix . '-' . $ticketIdRaw;
                            $stmtT = $pdo->prepare("SELECT id FROM tickets WHERE ticket_no = ?");
                            $stmtT->execute([$ticketNo]);
                            $foundTicketId = $stmtT->fetchColumn();
                        }

                        // 1.5. Foolproof Fallback: Search subject for ANY 12-16 digit number (the timestamp part)
                        if (!$foundTicketId && preg_match('/(\d{12,16})/', $subjectToken, $matches)) {
                            $ticketIdRaw = $matches[1];
                            $parsedTicketNoRaw = $ticketIdRaw;
                            $stmtT = $pdo->prepare("SELECT id FROM tickets WHERE ticket_no LIKE ?");
                            $stmtT->execute(['%-' . $ticketIdRaw]);
                            $foundTicketId = $stmtT->fetchColumn();
                        }

                        // 2. Fallback: Search the uncleaned body for the bilet-detay URL
                        if (!$foundTicketId && !empty($mailContent['body'])) {
                            $decodedBody = html_entity_decode($mailContent['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            if (preg_match('/bilet-detay\/(\d+)/i', $decodedBody, $bodyUrlMatches)) {
                                $ticketIdFromUrl = (int)$bodyUrlMatches[1];
                                $stmtT = $pdo->prepare("SELECT id FROM tickets WHERE id = ?");
                                $stmtT->execute([$ticketIdFromUrl]);
                                $foundTicketId = $stmtT->fetchColumn();
                            }
                        }

                        // 3. Fallback: Search the uncleaned body for the ticket number format or any 12-16 digit number
                        if (!$foundTicketId && !empty($mailContent['body'])) {
                            $decodedBody = html_entity_decode($mailContent['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            if (preg_match('/' . preg_quote($sPrefix) . '\s*[-#]?\s*(\d{8,16})/i', $decodedBody, $bodyNoMatches)) {
                                $ticketIdRaw = $bodyNoMatches[1];
                                $parsedTicketNoRaw = $ticketIdRaw;
                                $stmtT = $pdo->prepare("SELECT id FROM tickets WHERE ticket_no LIKE ?");
                                $stmtT->execute(['%-' . $ticketIdRaw]);
                                $foundTicketId = $stmtT->fetchColumn();
                            } elseif (preg_match('/(\d{12,16})/', $decodedBody, $bodyNoMatches)) {
                                $ticketIdRaw = $bodyNoMatches[1];
                                $parsedTicketNoRaw = $ticketIdRaw;
                                $stmtT = $pdo->prepare("SELECT id FROM tickets WHERE ticket_no LIKE ?");
                                $stmtT->execute(['%-' . $ticketIdRaw]);
                                $foundTicketId = $stmtT->fetchColumn();
                            }
                        }

                        // Check if the ticket parsed in the subject/body was deleted
                        $isDeleted = false;
                        if (!$foundTicketId && $parsedTicketNoRaw) {
                            try {
                                $stmtDel = $pdo->prepare("SELECT COUNT(*) FROM deleted_tickets WHERE ticket_no = ? OR ticket_no LIKE ?");
                                $stmtDel->execute([$sPrefix . '-' . $parsedTicketNoRaw, '%-' . $parsedTicketNoRaw]);
                                if ($stmtDel->fetchColumn() > 0) {
                                    $isDeleted = true;
                                }
                            } catch (Throwable $e) {}
                        }

                        if ($isDeleted) {
                            logMsg($isTr ? "E-posta silinmiş bilet (#$parsedTicketNoRaw) için. Yeni bilet oluşturma atlandı." : "Email is for deleted ticket (#$parsedTicketNoRaw). Skipping new ticket creation.", $logFile);
                            addProcessedUid($processedUidsFile, (string)$uid);
                            continue;
                        }

                        if ($foundTicketId) {
                                // Fetch creator_id and customer_id from the ticket
                                $stmtTData = $pdo->prepare("SELECT creator_id, customer_id FROM tickets WHERE id = ?");
                                $stmtTData->execute([$foundTicketId]);
                                $tData = $stmtTData->fetch(PDO::FETCH_ASSOC);
                                $ticketCreatorId = $tData['creator_id'] ?? 1;
                                $ticketCustomerId = $tData['customer_id'] ?? $ticketCreatorId;

                                $agentId = findUserIdByEmail($pdo, $fromEmail);

                                if ($agentId) {
                                    $userId = $agentId;
                                    $replyCustomerId = null;
                                } else {
                                    // Foreign key constraint requires a valid user_id in ticket_replies.
                                    // Create or fetch a dummy system user for email customers to satisfy the constraint.
                                    $dummyEmail = 'system_customer_gateway@eaprimus.local';
                                    $stmtFind = $pdo->prepare("SELECT id FROM users WHERE mail = ?");
                                    $stmtFind->execute([$dummyEmail]);
                                    $userId = $stmtFind->fetchColumn();

                                    if (!$userId) {
                                        $dummyPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                                        $pdo->prepare("INSERT INTO users (username, fullname, mail, role, password, status, tc_no, created_at) VALUES ('customer_gateway', 'E-posta Yanıtı', ?, 2, ?, 1, '', NOW())")->execute([$dummyEmail, $dummyPassword]);
                                        $userId = $pdo->lastInsertId();
                                    }

                                    $replyCustomerId = $ticketCustomerId;
                                }

                                // Check for duplicate reply in the last minute (prevents duplicate insertion under concurrency)
                                $stmtCheckReply = $pdo->prepare("SELECT id FROM ticket_replies WHERE ticket_id = ? AND user_id = ? AND message = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
                                $stmtCheckReply->execute([$foundTicketId, $userId, $body]);
                                if ($stmtCheckReply->fetchColumn()) {
                                    logMsg($isTr ? "Mükerrer yanıt atlandı." : "Duplicate reply skipped.", $logFile);
                                    addProcessedUid($processedUidsFile, (string)$uid);
                                    continue;
                                }

                                $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, customer_id, message, created_at) VALUES (?,?,?,?,NOW())")->execute([$foundTicketId, $userId, $replyCustomerId, $body]);
                                $replyId = $pdo->lastInsertId();
                                $pdo->prepare("UPDATE tickets SET status = 'open', agent_read = 0, unread_replies_count = unread_replies_count + 1, update_date = NOW() WHERE id = ?")->execute([$foundTicketId]);
                                logMsg($isTr ? "Yanıt bilet #$foundTicketId içine eklendi." : "Reply added to ticket #$foundTicketId.", $logFile);

                                // Trigger notifications (Telegram, Slack, Webhooks, etc.)
                                if (function_exists('sendReplyNotifications')) {
                                    sendReplyNotifications($foundTicketId, $replyId, $pdo);
                                }

                                if (!empty($attachments)) {
                                    foreach ($attachments as $att) {
                                        $pdo->prepare("INSERT INTO ticket_attachments (ticket_id, reply_id, uploader_id, file_name, file_path, file_type, file_size, upload_date) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                                            ->execute([$foundTicketId, $replyId, $userId, $att['name'], $att['path'], $att['type'], $att['size']]);
                                    }
                                }
                            } else {
                                // NEW TICKET
                                $newNo = $sPrefix . '-' . date('YmdHis');
                                $cleanTitle = mb_substr($subjectToken, 0, 250);
                                $defaultQueueId = $pdo->query("SELECT id FROM queues LIMIT 1")->fetchColumn();

                                // Check for duplicate ticket in the last minute
                                $defaultUserIdForCheck = $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn() ?: 1;
                                $creatorIdForCheck = findUserIdByEmail($pdo, $fromEmail) ?: $defaultUserIdForCheck;
                                $stmtCheckTicket = $pdo->prepare("SELECT id FROM tickets WHERE title = ? AND creator_id = ? AND description = ? AND create_date >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
                                $stmtCheckTicket->execute([$cleanTitle, $creatorIdForCheck, $body]);
                                if ($stmtCheckTicket->fetchColumn()) {
                                    logMsg($isTr ? "Mükerrer bilet atlandı." : "Duplicate ticket skipped.", $logFile);
                                    addProcessedUid($processedUidsFile, (string)$uid);
                                    continue;
                                }

                                if (!$defaultQueueId) {
                                    // Already defined above but we fallback for safety
                                    $defaultLang = $defaultLang ?? 'tr';

                                    if ($defaultLang === 'en') {
                                        $tName = 'General Team';
                                        $qName = 'General Queue';
                                        $desc  = 'Created automatically by the system. If no queue is set, incoming emails will fall here.';
                                        $logT  = "WARNING: No queue was found in the system, so '$qName' was automatically created to prevent emails from being lost.";
                                    } else {
                                        $tName = 'Genel Takım';
                                        $qName = 'Genel Kuyruk';
                                        $desc  = 'Sistem tarafından otomatik oluşturuldu. Varsayılan olarak kuyruk yoksa gelen e-postalar buraya düşer.';
                                        $logT  = "UYARI: Sistemde hiç kuyruk (queue) bulunmadığı için e-postalar kaybolmasın diye '$qName' otomatik olarak oluşturuldu.";
                                    }

                                    // Eğer hiç kuyruk yoksa otomatik olarak "Genel Takım" ve "Genel Kuyruk" oluştur.
                                    $teamId = $pdo->query("SELECT id FROM teams LIMIT 1")->fetchColumn();
                                    if (!$teamId) {
                                        $pdo->prepare("INSERT INTO teams (name, description, status) VALUES (?, ?, 1)")->execute([$tName, $desc]);
                                        $teamId = $pdo->lastInsertId();
                                    }

                                    $pdo->prepare("INSERT INTO queues (team_id, name, description, status, auto_assign) VALUES (?, ?, ?, 1, 0)")
                                        ->execute([$teamId, $qName, $desc]);
                                    $defaultQueueId = $pdo->lastInsertId();

                                    logMsg($logT, $logFile);
                                }

                                $forwardedHeaders = extractForwardedHeaders($body);
                                $originalEmail = $forwardedHeaders['from_email'];
                                $originalName = $forwardedHeaders['from_name'];

                                $isForwarded = !empty($originalEmail) && filter_var($originalEmail, FILTER_VALIDATE_EMAIL);

                                if ($isForwarded) {
                                    // Translate and clean Fwd prefix to the configured default language
                                    // Already defined above but we fallback for safety
                                    $defaultLang = $defaultLang ?? 'tr';
                                    $prefixWord = ($defaultLang === 'en') ? 'Forwarded: ' : 'Yönlendirildi: ';

                                    $cleanTitle = preg_replace('/^(?:fwd|fw|yönl|yönlendirildi)\s*:\s*/ui', '', $cleanTitle);
                                    $cleanTitle = $prefixWord . $cleanTitle;
                                    $cleanTitle = mb_substr($cleanTitle, 0, 250);

                                    $cData = getOrCreateCustomer($pdo, $originalEmail, $originalName);
                                    $customerId = $cData['id'];
                                    $defaultUserId = $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn() ?: 1;
                                    $creatorId = findUserIdByEmail($pdo, $fromEmail) ?: $defaultUserId;
                                    $cleanBody = $body; // Do not strip the original headers

                                    $pdo->prepare("INSERT INTO tickets (ticket_no, title, description, status, queue_id, creator_id, customer_id, is_forwarded, forwarder_name, forwarder_email, sla_due_date, create_date, agent_read, unread_replies_count) VALUES (?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 24 HOUR),NOW(),0,0)")
                                        ->execute([$newNo, $cleanTitle, $cleanBody, 'open', $defaultQueueId, $creatorId, $customerId, 1, $fromName, $fromEmail]);
                                    $ticketId = $pdo->lastInsertId();
                                } else {
                                    $creatorId = findUserIdByEmail($pdo, $fromEmail);
                                    $customerId = null;
                                    if (!$creatorId) {
                                        $cData = getOrCreateCustomer($pdo, $fromEmail, $fromName);
                                        $customerId = $cData['id'];
                                        $creatorId = $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn() ?: 1; // General creator fallback
                                    }

                                    $pdo->prepare("INSERT INTO tickets (ticket_no, title, description, status, queue_id, creator_id, customer_id, sla_due_date, create_date, agent_read, unread_replies_count) VALUES (?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 24 HOUR),NOW(),0,0)")
                                        ->execute([$newNo, $cleanTitle, $body, 'open', $defaultQueueId, $creatorId, $customerId]);
                                    $ticketId = $pdo->lastInsertId();
                                }

                            logMsg($isTr ? "Yeni bilet oluşturuldu: $newNo (ID: $ticketId)" : "New ticket created: $newNo (ID: $ticketId)", $logFile);

                            if (!empty($attachments)) {
                                foreach ($attachments as $att) {
                                    $pdo->prepare("INSERT INTO ticket_attachments (ticket_id, uploader_id, file_name, file_path, file_type, file_size, upload_date) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                                        ->execute([$ticketId, $creatorId, $att['name'], $att['path'], $att['type'], $att['size']]);
                                }
                            }

                            // Send Notifications
                            try {
                                require_once __DIR__ . '/../includes/rule_engine.php';
                                runTicketRules($pdo, $ticketId);

                                sendNewTicketNotifications($ticketId, $pdo);
                                logMsg($isTr ? "Bildirimler gönderildi." : "Notifications sent.", $logFile);
                            } catch (Exception $e) {
                                logMsg(($isTr ? "Bildirim hatası: " : "Notification error: ") . $e->getMessage(), $logFile);
                            }
                        }

                        addProcessedUid($processedUidsFile, (string)$uid);
                    } catch (Exception $e) {
                        logMsg(($isTr ? "HATA (UID $uid): " : "ERROR (UID $uid): ") . $e->getMessage(), $logFile);
                        // İşlem başarısız olduğunda e-postayı tekrar UNSEEN (okunmamış) yap, böylece bir sonraki turda tekrar denensin
                        @imap_clearflag_full($inbox, $uid, "\\Seen", ST_UID);
                    }
                }
            }
        }
        imap_close($inbox);
    }
} catch (Throwable $t) {
    logMsg((isset($isTr) && !$isTr ? "CRITICAL ERROR: " : "KRİTİK HATA: ") . $t->getMessage(), $logFile ?? __DIR__ . '/../logs/imap_listener.log');
}
?>
