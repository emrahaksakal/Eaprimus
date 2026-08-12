<?php
// app/includes/mailer.php

function sendEaprimusMail($toEmail, $toName, $subject, $body, array $attachments = [])
{
  global $pdo;

  if (!isset($pdo) || !$pdo) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
  }

  $settings = [];
  try {
    if ($pdo) {
      $sRows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
      $settings = $sRows ?: [];
    }
  } catch (Exception $e) {
  }

  $isTr = (isset($_SESSION) && isset($_SESSION['lang']) && $_SESSION['lang'] === 'en') ? false : true;
  if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
      if (isset($_SESSION)) {
          if (!isset($_SESSION['send_warnings'])) {
              $_SESSION['send_warnings'] = [];
          }
          $warnMsg = $isTr 
              ? "⚠️ E-posta Gönderim Uyarısı: Geçersiz veya hatalı e-posta adresi ('" . htmlspecialchars((string)$toEmail) . "')."
              : "⚠️ Email Delivery Warning: Invalid or misspelled email address ('" . htmlspecialchars((string)$toEmail) . "').";
          if (!in_array($warnMsg, $_SESSION['send_warnings'])) {
              $_SESSION['send_warnings'][] = $warnMsg;
          }
      }
      return false;
  }

  if (empty($settings['mail_host'])) {
      if (isset($_SESSION)) {
          if (!isset($_SESSION['send_warnings'])) {
              $_SESSION['send_warnings'] = [];
          }
          $warnMsg = $isTr 
              ? "E-posta (SMTP) ayarları yapılandırılmadığı için e-posta gönderilemedi." 
              : "Email could not be sent because Email (SMTP) settings are not configured.";
          if (!in_array($warnMsg, $_SESSION['send_warnings'])) {
              $_SESSION['send_warnings'][] = $warnMsg;
          }
      }
      return false;
  }

  $phpmailerPath = __DIR__ . '/../../libs/phpmailer/';
  if (file_exists($phpmailerPath . 'PHPMailer.php')) {
    require_once $phpmailerPath . 'Exception.php';
    require_once $phpmailerPath . 'PHPMailer.php';
    require_once $phpmailerPath . 'SMTP.php';

    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
      $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
      try {
        @set_time_limit(180);
        $mail->Timeout = 5;
        $mail->SMTPConnectTimeout = 3;
        $siteUrl = rtrim($settings['site_url'] ?? 'http://localhost', '/');

        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->SMTPAuth = true;
        $mail->Host = $settings['mail_host'] ?? '';
        $mail->Port = $settings['mail_port'] ?? 587;

        $secure = $settings['mail_secure'] ?? 'tls';
        if ($secure === 'ssl') {
          $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
          $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
          $mail->SMTPAutoTLS = false;
          $mail->SMTPSecure = '';
        }

        $mail->Username = $settings['mail_username'] ?? '';
        $mail->Password = $settings['mail_password'] ?? '';

        // Gönderici Ayarları (Whitelabel)
        $brandName = !empty($settings['site_title']) ? $settings['site_title'] : 'Destek Merkezi';
        $fromEmail = !empty($settings['mail_from_address']) ? $settings['mail_from_address'] : ($settings['mail_username'] ?? '');
        $fromName = !empty($settings['mail_from_name']) ? $settings['mail_from_name'] : $brandName;

        $mail->setFrom($fromEmail, $fromName);
        
        // Ensure replies are sent to the correct monitored support mailbox
        $replyToEmail = !empty($settings['mail_username']) ? $settings['mail_username'] : '';
        if (!empty($replyToEmail)) {
            $mail->addReplyTo($replyToEmail, $fromName);
        }
        
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);

        // Logo Yerleşimi (CID Embedding - Outlook uyumluluğu için en iyisi)
        $logoPath = getMailLogoPath();
        if ($logoPath && file_exists($logoPath)) {
            $mail->addEmbeddedImage($logoPath, 'site_logo');
            $finalBody = str_replace('{{LOGO_SRC}}', 'cid:site_logo', $body);
        } else {
            $finalBody = str_replace('{{LOGO_SRC}}', '', $body);
        }

        // İmza veya içerikteki resimler için (Eğer yerelse dosyayı oku ve CID olarak ekle)
        $finalBody = preg_replace_callback('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', function($m) use ($siteUrl, $mail) {
            $src = $m[1];
            if (stripos($src, 'data:') === 0 || stripos($src, 'http://') === 0 || stripos($src, 'https://') === 0 || stripos($src, 'cid:') === 0 || stripos($src, '{{') === 0) {
                return $m[0]; // Dokunma
            }
            if (strpos($src, '/') === 0) {
                // Dosya fiziksel olarak sunucuda varsa, CID olarak mail'e göm
                $localPath = __DIR__ . '/../../' . ltrim($src, '/');
                if (file_exists($localPath)) {
                    $cid = 'img_' . md5($localPath);
                    $mail->addEmbeddedImage($localPath, $cid);
                    return str_replace($m[1], 'cid:' . $cid, $m[0]);
                }

                // Bulunamazsa Kökten URL ekle
                $parsed = parse_url($siteUrl);
                $rootDomain = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? 'localhost');
                if (isset($parsed['port'])) $rootDomain .= ':' . $parsed['port'];
                $src = rtrim($rootDomain, '/') . $src;
            } else {
                $src = rtrim($siteUrl, '/') . '/' . ltrim($src, './\\');
            }
            return str_replace($m[1], $src, $m[0]);
        }, $finalBody);

        // Base64 resimleri CID ile attach et (mobilde görünüm için)
        $finalBody = preg_replace_callback('/<img[^>]+src=[\'"](data:image\/([^;]+);base64,([^\'"]+))[\'"][^>]*>/i', function($m) use ($mail) {
            $ext = $m[2];
            $base64 = $m[3];
            $decoded = base64_decode($base64);
            if ($decoded) {
                $cid = 'img_' . md5(substr($base64, 0, 100));
                $mail->addStringEmbeddedImage($decoded, $cid, $cid.'.'.$ext, 'base64', 'image/'.$ext);
                return str_replace($m[1], 'cid:'.$cid, $m[0]);
            }
            return $m[0];
        }, $finalBody);

        if (!empty($attachments)) {
            foreach ($attachments as $att) {
                if (isset($att['path']) && file_exists($att['path'])) {
                    $mail->addAttachment($att['path'], $att['name'] ?? '');
                }
            }
        }

        $mail->Subject = $subject;
        $mail->Body = $finalBody; // Body zaten HTML, nl2br eklenmez
        $mail->send();

        // Başarılı gönderim logu (teslimat sorunlarını izlemek için)
        @file_put_contents(
            __DIR__ . '/../logs/mail_sent.log',
            date('Y-m-d H:i:s') . " SENT to=[{$toEmail}] from=[{$fromEmail}] subject=[{$subject}]\n",
            FILE_APPEND
        );

        return true;
      } catch (\Throwable $e) {
        $isTr = (isset($_SESSION) && isset($_SESSION['lang']) && $_SESSION['lang'] === 'en') ? false : true;
        if (isset($_SESSION)) {
            if (!isset($_SESSION['send_warnings'])) {
                $_SESSION['send_warnings'] = [];
            }
            $phpmailerError = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
            if (stripos($phpmailerError, '550') !== false || stripos($phpmailerError, '5.1.1') !== false || stripos($phpmailerError, 'does not exist') !== false || stripos($phpmailerError, 'invalid address') !== false || stripos($phpmailerError, 'Recipient address rejected') !== false || stripos($phpmailerError, 'NoSuchUser') !== false) {
                $warnMsg = $isTr 
                    ? "⚠️ E-posta Gönderim Uyarısı: '$toEmail' adresi bulunamadı veya alıcı hesabı mevcut değil (550 Alıcı Hatası). Lütfen e-posta adresinin doğruluğunu kontrol ediniz."
                    : "⚠️ Email Delivery Warning: The address '$toEmail' was not found or the recipient account does not exist (550 Recipient Error). Please double-check the email address.";
            } else {
                $warnMsg = $isTr 
                    ? "⚠️ E-posta Gönderim Uyarısı: '$toEmail' adresine e-posta gönderilemedi (" . htmlspecialchars($phpmailerError) . ")"
                    : "⚠️ Email Delivery Warning: Could not send email to '$toEmail' (" . htmlspecialchars($phpmailerError) . ")";
            }
            if (!in_array($warnMsg, $_SESSION['send_warnings'])) {
                $_SESSION['send_warnings'][] = $warnMsg;
            }
        }
        @file_put_contents(
          __DIR__ . '/../logs/mail_errors.log',
          date('Y-m-d H:i:s') . " SendMail Error [{$toEmail}]: " . $e->getMessage() . "\n",
          FILE_APPEND
        );
        return false;
      }
    }
  }

  // Fallback: php mail()
  if (function_exists('mail')) {
    $fromName = $settings['mail_from_name'] ?? ($settings['site_title'] ?? 'Destek');
    $fromMail = $settings['mail_from_address'] ?? 'noreply@system.local';

    $siteUrl = rtrim($settings['site_url'] ?? 'http://localhost', '/');
    $body = str_replace('{{LOGO_SRC}}', $siteUrl . '/public/assets/img/logo.png', $body);

    // Aynı dönüşümü standart mail fonksiyonu için de yap
    $body = preg_replace_callback('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', function($m) use ($siteUrl) {
        $src = $m[1];
        if (stripos($src, 'data:') === 0 || stripos($src, 'http://') === 0 || stripos($src, 'https://') === 0 || stripos($src, 'cid:') === 0 || stripos($src, '{{') === 0) {
            return $m[0];
        }
        if (strpos($src, '/') === 0) {
            $parsed = parse_url($siteUrl);
            $rootDomain = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? 'localhost');
            if (isset($parsed['port'])) $rootDomain .= ':' . $parsed['port'];
            $src = rtrim($rootDomain, '/') . $src;
        } else {
            $src = rtrim($siteUrl, '/') . '/' . ltrim($src, './\\');
        }
        return str_replace($m[1], $src, $m[0]);
    }, $body);

    $replyMail = !empty($settings['mail_username']) ? $settings['mail_username'] : $fromMail;
    $headers = "From: $fromName <$fromMail>\r\n";
    $headers .= "Reply-To: $replyMail\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    return @mail($toEmail, $subject, $body, $headers);
  }

  return false;
}

/**
 * Logo dosyasının fiziksel yolunu döndürür.
 * E-posta uyumluluğu için PNG her zaman WebP'den üstün tutulur.
 */
function getMailLogoPath(): string
{
  global $pdo;
  if (!$pdo) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
  }

  // Get active logo path from settings
  $logoPath = '';
  try {
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'logo_path'");
    $logoPath = $stmt->fetchColumn();
  } catch (Exception $e) {}

  $paths = [];
  if ($logoPath) {
    // E-posta gönderirken eğer ayarlı logo webp ise ve aynı isimde png varsa png'ye öncelik ver (Outlook desteği)
    if (strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)) === 'webp') {
       $pngPath = str_ireplace('.webp', '.png', $logoPath);
       $paths[] = __DIR__ . '/../../public/' . ltrim($pngPath, '/');
    }
    $paths[] = __DIR__ . '/../../public/' . ltrim($logoPath, '/');
  }

  // Fallback paths
  $paths = array_merge($paths, [
    __DIR__ . '/../../public/logo.png',
    __DIR__ . '/../../public/assets/img/logo.png',
    __DIR__ . '/../../public/uploads/logo/logo.png',
    __DIR__ . '/../../public/uploads/logo/logo.jpg',
    __DIR__ . '/../../public/uploads/logo/logo.webp',
  ]);

  foreach ($paths as $path) {
    if (file_exists($path)) {
      return $path;
    }
  }
  return '';
}

/**
 * Logo'yu base64 inline olarak döndürür (Gerektiğinde kullanılır).
 */
function getMailLogoBase64(): string
{
  $path = getMailLogoPath();
  if (!$path) return '';

  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $mime = match($ext) {
      'png' => 'image/png',
      'gif' => 'image/gif',
      'webp' => 'image/webp',
      default => 'image/jpeg',
  };
  $data = @file_get_contents($path);
  if ($data) {
    return 'data:' . $mime . ';base64,' . base64_encode($data);
  }
  return '';
}

/**
 * Tüm gönderimlerde kullanılan standart mail şablonu.
 * Mobil uyumlu, inline CSS, base64 logo, imza ve footer desteği.
 *
 * @param string $contentHtml   Ortadaki asıl içerik (HTML)
 * @param string $signatureHtml Temsilci imzası (opsiyonel)
 * @param string $footerNote    Alt not (opsiyonel, boş bırakılırsa varsayılan)
 */
function buildMailTemplate(string $contentHtml, string $signatureHtml = '', string $footerNote = ''): string
{
  global $pdo;
  if (!$pdo) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
  }

  // Fetch Branding Settings
  $logoPath = getSetting($pdo, 'logo_path', 'public/logo.png');
  $slogan = getSetting($pdo, 'site_slogan', 'Entegre Varlık Yönetim Sistemi');
  $pColor = getSetting($pdo, 'primary_color', '#1e3c72');
  $siteTitle = getSetting($pdo, 'site_title', 'Destek Merkezi');
  $companyName = getSetting($pdo, 'company_name', 'Destek Ekibi');
  $dbLang = getSetting($pdo, 'mail_default_lang', 'tr');
  $siteUrl = rtrim(getSetting($pdo, 'site_url', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');

  // Full HTML check
  $isFullHtml = (stripos($contentHtml, '<html') !== false || stripos($contentHtml, '<!DOCTYPE') !== false || stripos($contentHtml, '&lt;html') !== false || stripos($contentHtml, '&lt;!doctype') !== false);
  
  if ($isFullHtml) {
      // Step 1: Decode entities if it was escaped by the editor
      if (stripos($contentHtml, '&lt;') !== false) {
          $contentHtml = html_entity_decode($contentHtml, ENT_QUOTES, 'UTF-8');
      }

      // Step 2: WYSIWYG engines (like Quill) wrap lines in <p> labels. 
      // If we detect a full HTML structure inside those <p> tags, we MUST strip them to prevent layout breakage.
      // We look for tags that shouldn't be inside a <p>
      if (preg_match('/<p>\s*<!DOCTYPE|<p>\s*<html|<p>\s*<head/i', $contentHtml)) {
          // Remove ALL leading/trailing tags but leave the inner content
          $contentHtml = preg_replace('/^<p>|<\/p>$/i', '', trim($contentHtml));
          $contentHtml = preg_replace('/<\/p>\s*<p>/i', "\n", $contentHtml);
          $contentHtml = str_replace(['<p>', '</p>', '<br>', '<br />'], '', $contentHtml);
          // Redecode one more time just in case of double encoding
          $contentHtml = html_entity_decode($contentHtml, ENT_QUOTES, 'UTF-8');
      }
      
      // Finally, ensure any leftover HTML entities are cleared
      $contentHtml = html_entity_decode($contentHtml, ENT_QUOTES, 'UTF-8');

      $logoBase64 = getMailLogoBase64();
      $contentHtml = str_replace('{{LOGO_SRC}}', $logoBase64, $contentHtml);
      return $contentHtml;
  }

  $logoBase64 = getMailLogoBase64();
  $logoTag = "<img src=\"" . $logoBase64 . "\" alt=\"Logo\" width=\"auto\" height=\"50\" style=\"display:block;margin:0 auto;max-height:50px;max-width:200px;border:0;\" />";

  $sigBlock = '';
  if (!empty(trim($signatureHtml))) {
    $sigBlock = "<tr><td style=\"padding:0 36px 24px;\"><div style=\"border-top:1px solid #e9ecef;padding-top:14px;font-size:13px;color:#555;line-height:1.7;\">{$signatureHtml}</div></td></tr>";
  }

  $targetLang = $dbLang ?? 'tr';
  $footerTranslate = ($companyName) . ". " . ($targetLang == 'en' ? 'All rights reserved.' : 'Tüm hakları saklıdır.');
  $footer = $footerNote ?: "© " . date('Y') . " " . $footerTranslate;

  return "<!DOCTYPE html>
<html lang=\"{$targetLang}\">
<head>
  <meta charset=\"UTF-8\" />
  <meta name=\"viewport\" content=\"width=device-width,initial-scale=1\" />
  <title>{$siteTitle}</title>
</head>
<body style=\"margin:0;padding:0;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif;\">
  <table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background:#f0f2f5;padding:15px 0;\">
    <tr>
      <td align=\"center\" style=\"padding:0 10px;\">
        <table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"max-width:600px;width:100%;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #dde1e7;\">
          <tr><td style=\"background:linear-gradient(135deg, {$pColor} 0%, #2a5298 100%);padding:18px 25px;text-align:center;\">{$logoTag}</td></tr>
          <tr><td style=\"padding:25px 30px 15px;font-size:15px;color:#333333;line-height:1.75;\">{$contentHtml}</td></tr>
          {$sigBlock}
          <tr><td style=\"background:#f8f9fa;padding:12px 25px;font-size:12px;color:#aaaaaa;text-align:center;border-top:1px solid #eeeeee;\"><strong>{$slogan}</strong><br>{$footer}</td></tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>";
}

/**
 * Şablonlu mail gönderim yardımcısı.
 */
function sendTemplatedMail(string $toEmail, string $toName, string $templateKey, array $vars = [], string $signature = '', string $forcedLang = '', array $attachments = []): bool
{
  global $pdo;
  if (!$pdo) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
  }

  // 1. Dili Belirle
  $lang = !empty($forcedLang) ? $forcedLang : null;
  if (!$lang) {
      $langQuery = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'mail_default_lang'")->fetchColumn();
      $lang = (!empty($langQuery) && in_array(trim($langQuery), ['tr', 'en'])) ? trim($langQuery) : 'tr';
  }

  // 2. Ayarları Çek
  $keys = [
      "mail_{$templateKey}_tr_subject", 
      "mail_{$templateKey}_tr_body", 
      "mail_{$templateKey}_en_subject", 
      "mail_{$templateKey}_en_body", 
      "mail_{$templateKey}_subject", 
      "mail_{$templateKey}_body", 
      "site_title", 
      "site_url"
  ];
  $placeholders = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('" . implode("','", $keys) . "')")->fetchAll(PDO::FETCH_KEY_PAIR);

  // Önce istenen dilin doğrudan veritabanındaki değerini alalım
  $subject = !empty($placeholders["mail_{$templateKey}_{$lang}_subject"]) ? $placeholders["mail_{$templateKey}_{$lang}_subject"] : '';
  $body = !empty($placeholders["mail_{$templateKey}_{$lang}_body"]) ? $placeholders["mail_{$templateKey}_{$lang}_body"] : '';

  // Eğer istenen dil tr ise ve boşsa, eski formattaki (sonunda _tr veya _en olmayan) değeri deneyebiliriz
  if ($lang === 'tr') {
      if (empty($subject)) $subject = !empty($placeholders["mail_{$templateKey}_subject"]) ? $placeholders["mail_{$templateKey}_subject"] : '';
      if (empty($body)) $body = !empty($placeholders["mail_{$templateKey}_body"]) ? $placeholders["mail_{$templateKey}_body"] : '';
  }

  // Decode if it's escaped from Quill or DB
  if (stripos($body, '&lt;') !== false) {
      $body = html_entity_decode($body);
  }
  if (stripos($subject, '&lt;') !== false) {
      $subject = html_entity_decode($subject);
  }

  // Eğer şablon hala boşsa, EN veya TR olmasına göre defaultlara bakacağız
  $cleanBody = trim(strip_tags(str_replace('&nbsp;', '', $body)));
  if (empty(trim($subject)) || empty($cleanBody)) {
    $defaults = [
      'new_ticket_cust' => [
          'tr' => [
              'sub' => 'Destek Talebiniz Alındı - [{{ticket_no}}]', 
              'body' => "Merhaba {{customer_name}},<br><br>Destek talebiniz başarıyla alınmıştır.<br><br>Bilet No: <b>{{ticket_no}}</b><br>Konu: {{subject}}<br><br><div style='text-align:center;margin-top:20px;'><a href='{{link}}' style='display:inline-block;padding:12px 25px;background:#007bff;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;'>Talebi Görüntüle</a></div>"
          ],
          'en' => [
              'sub' => 'Your Support Ticket Has Been Received - [{{ticket_no}}]', 
              'body' => "Hello {{customer_name}},<br><br>Your support ticket has been successfully received.<br><br>Ticket No: <b>{{ticket_no}}</b><br>Subject: {{subject}}<br><br><div style='text-align:center;margin-top:20px;'><a href='{{link}}' style='display:inline-block;padding:12px 25px;background:#007bff;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;'>View Your Ticket</a></div>"
          ]
      ],
      'new_ticket_agent' => [
          'tr' => [
              'sub' => '[{{site_title}}] Yeni Bilet Atandı: {{ticket_no}}', 
              'body' => "Merhaba {{agent_name}},<br><br>Size yeni bir bilet atandı.<br><br>Müşteri: {{customer_name}}<br>No: <b>{{ticket_no}}</b><br><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#28a745;color:#fff;text-decoration:none;border-radius:5px;'>Bileti Aç</a></div>"
          ],
          'en' => [
              'sub' => '[{{site_title}}] New Ticket Assigned: {{ticket_no}}', 
              'body' => "Hello {{agent_name}},<br><br>A new ticket has been assigned to you.<br><br>Customer: {{customer_name}}<br>No: <b>{{ticket_no}}</b><br><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#28a745;color:#fff;text-decoration:none;border-radius:5px;'>Open Ticket</a></div>"
          ]
      ],
      'reply_cust' => [
          'tr' => [
              'sub' => '💬 Yanıt: {{subject}} [{{site_title}} #{{ticket_no}}]', 
              'body' => "Merhaba {{customer_name}},<br><br>Talebinize yeni bir yanıt eklendi:<br><br><div style='background:#f9f9f9;padding:15px;border-left:4px solid #007bff;margin:15px 0;'>{{message}}</div><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#007bff;color:#fff;text-decoration:none;border-radius:5px;'>Yanıtı Gör</a></div>"
          ],
          'en' => [
              'sub' => '💬 Reply: {{subject}} [{{site_title}} #{{ticket_no}}]', 
              'body' => "Hello {{customer_name}},<br><br>A new reply has been added to your ticket:<br><br><div style='background:#f9f9f9;padding:15px;border-left:4px solid #007bff;margin:15px 0;'>{{message}}</div><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#007bff;color:#fff;text-decoration:none;border-radius:5px;'>View Reply</a></div>"
          ]
      ],
      'reply_agent' => [
          'tr' => [
              'sub' => '💬 Yeni Yanıt (Personel): {{subject}} [{{site_title}} #{{ticket_no}}]', 
              'body' => "Merhaba {{agent_name}},<br><br>Takip ettiğiniz veya size atanan bilete yeni bir yanıt eklendi:<br><br><div style='background:#f9f9f9;padding:15px;border-left:4px solid #28a745;margin:15px 0;'>{{message}}</div><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#28a745;color:#fff;text-decoration:none;border-radius:5px;'>Bileti Görüntüle</a></div>"
          ],
          'en' => [
              'sub' => '💬 New Reply (Staff): {{subject}} [{{site_title}} #{{ticket_no}}]', 
              'body' => "Hello {{agent_name}},<br><br>A new reply has been added to a ticket you are assigned to:<br><br><div style='background:#f9f9f9;padding:15px;border-left:4px solid #28a745;margin:15px 0;'>{{message}}</div><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#28a745;color:#fff;text-decoration:none;border-radius:5px;'>View Ticket</a></div>"
          ]
      ],
      'ticket_assigned' => [
          'tr' => [
              'sub' => '[#{{ticket_no}}] Yeni Bilet Atandı: {{subject}}', 
              'body' => "Merhaba {{agent_name}},<br><br><b>#{{ticket_no}}</b> numaralı bilet size atandı.<br><br><b>Konu:</b> {{subject}}<br><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#6366f1;color:#fff;text-decoration:none;border-radius:5px;'>Bileti Görüntüle</a></div>"
          ],
          'en' => [
              'sub' => '[#{{ticket_no}}] New Ticket Assigned: {{subject}}', 
              'body' => "Hello {{agent_name}},<br><br>Ticket <b>#{{ticket_no}}</b> has been assigned to you.<br><br><b>Subject:</b> {{subject}}<br><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#6366f1;color:#fff;text-decoration:none;border-radius:5px;'>View Ticket</a></div>"
          ]
      ],
      'ticket_transferred' => [
          'tr' => [
              'sub' => '[#{{ticket_no}}] Bilet Size Transfer Edildi: {{subject}}', 
              'body' => "Merhaba {{agent_name}},<br><br><b>#{{ticket_no}}</b> numaralı bilet başka bir kuyruktan/takımdan size transfer edildi.<br><br><b>Konu:</b> {{subject}}<br><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#f59e0b;color:#fff;text-decoration:none;border-radius:5px;'>Bileti Görüntüle</a></div>"
          ],
          'en' => [
              'sub' => '[#{{ticket_no}}] Ticket Transferred to You: {{subject}}', 
              'body' => "Hello {{agent_name}},<br><br>Ticket <b>#{{ticket_no}}</b> has been transferred to you from another queue/team.<br><br><b>Subject:</b> {{subject}}<br><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#f59e0b;color:#fff;text-decoration:none;border-radius:5px;'>View Ticket</a></div>"
          ]
      ],
      'resolved' => [
          'tr' => [
              'sub' => '✅ Çözüldü: {{ticket_no}} [{{site_title}}]', 
              'body' => "Sayın {{customer_name}},<br><br><b>{{ticket_no}}</b> numaralı talebiniz çözüm merkezimiz tarafından sonuçlandırılmıştır.<br><br>Bize geri bildirimde bulunmak isterseniz bilete tıklayabilirsiniz.<br><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#28a745;color:#fff;text-decoration:none;border-radius:5px;'>Detayları Gör</a></div><br>Saygılarımızla,<br>{{site_title}} Ekibi"
          ],
          'en' => [
              'sub' => '✅ Resolved: {{ticket_no}} [{{site_title}}]', 
              'body' => "Dear {{customer_name}},<br><br>Your ticket <b>{{ticket_no}}</b> has been resolved by our support center.<br><br>If you want to provide feedback, you can click on the ticket.<br><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#28a745;color:#fff;text-decoration:none;border-radius:5px;'>View Details</a></div><br>Best regards,<br>{{site_title}} Team"
          ]
      ],
      'user_invitation' => [
          'tr' => [
              'sub' => '{{SITE_TITLE}} Davet Edildiniz!', 
              'body' => '<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" style="max-height:45px; width:auto; display:block;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Aramıza Hoş Geldiniz! 🚀</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Merhaba <b>{{NAME}}</b>,<br>Sizin için <b>{{SITE_TITLE}}</b> üzerinde bir hesap oluşturuldu. Sisteme giriş yapabilmek için lütfen aşağıdaki butona tıklayarak parolanızı belirleyin.</p>
                            <div style="text-align:center; margin:35px 0;">
                                <a href="{{ACTIVATION_LINK}}" style="background:#2563eb; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px; box-shadow:0 10px 15px -3px rgba(37, 99, 235, 0.4);">
                                    Hesabımı Aktifleştir
                                </a>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>'
          ],
          'en' => [
              'sub' => 'Invitation to {{SITE_TITLE}}!', 
              'body' => '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" style="max-height:45px; width:auto; display:block;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Welcome Aboard! 🚀</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Hello <b>{{NAME}}</b>,<br>An account has been created for you on <b>{{SITE_TITLE}}</b>. To log in, please click the button below to set your password.</p>
                            <div style="text-align:center; margin:35px 0;">
                                <a href="{{ACTIVATION_LINK}}" style="background:#2563eb; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px; box-shadow:0 10px 15px -3px rgba(37, 99, 235, 0.4);">
                                    Activate My Account
                                </a>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>'
          ]
      ],
      'user_registration' => [
          'tr' => [
              'sub' => '{{site_title}} Hoş Geldiniz!', 
              'body' => "<h1>Hoş Geldiniz, {{NAME}}!</h1><p>Hesabınız başarıyla oluşturuldu. Kullanıcı adınız: <strong>{{USERNAME}}</strong></p><p>Sisteme giriş yapabilmek için lütfen aşağıdaki butona tıklayarak şifrenizi belirleyiniz.</p><div style='text-align:center;margin:30px 0;'><a href='{{ACTIVATION_LINK}}' style='display:inline-block;padding:14px 30px;background:#ffc107;color:#000000;text-decoration:none;border-radius:50px;font-weight:bold;'>Hesabımı Aktifleştir & Şifre Al</a></div>"
          ],
          'en' => [
              'sub' => 'Welcome to {{site_title}}!', 
              'body' => "<h1>Welcome, {{NAME}}!</h1><p>Your account has been created. Username: <strong>{{USERNAME}}</strong></p><p>Please click the button below to set your password and activate your account.</p><div style='text-align:center;margin:30px 0;'><a href='{{ACTIVATION_LINK}}' style='display:inline-block;padding:14px 30px;background:#ffc107;color:#000000;text-decoration:none;border-radius:50px;font-weight:bold;'>Activate My Account</a></div>"
          ]
      ],
      'asset_assigned' => [
          'tr' => [
              'sub' => 'Yeni Zimmet Atandı: {{ITEM_NAME}}', 
              'body' => '<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @media screen and (max-width: 600px) {
            .container { width: 100% !important; border-radius: 0 !important; }
            .content { padding: 30px 20px !important; }
            .header { padding: 30px 20px !important; }
            .meta-box { border-radius: 8px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="140" height="auto" style="width:140px !important; height:auto !important; max-height:50px; display:block; margin:0 auto; border:0; outline:none;">
                        </td>
                    </tr>
                    <tr>
                        <td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Yeni Zimmet Atandı 🚀</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Merhaba <b>{{NAME}}</b>,<br>Üzerinize yeni bir varlık/demirbaş başarıyla zimmetlenmiştir.</p>
                            <div class="meta-box" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Zimmetlenen Varlık</span><br>
                                            <div style="font-size:16px; color:#1e293b; font-weight:600; margin-top:5px; line-height:1.4;">{{ITEM_NAME}}</div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-top:10px;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Zimmet Tarihi</span><br>
                                            <span style="font-size:15px; color:#1e293b; font-weight:600;">{{DATE_TIME}}</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <p style="margin:0 0 15px 0; font-size:15px; color:#475569;">Lütfen varlığı teslim aldığınızda kontrol ediniz. Varlık ile ilgili herhangi bir sorun yaşamanız durumunda BT departmanına bildirebilirsiniz.</p>
                            <div style="text-align:center; margin:35px 0 10px 0;">
                                <a href="{{SITE_URL}}/varliklar" style="background-color:#2563eb; color:#ffffff; padding:14px 32px; text-decoration:none; border-radius:8px; font-weight:600; font-size:15px; display:inline-block; box-shadow:0 4px 6px rgba(37, 99, 235, 0.2);">Zimmetlerimi Görüntüle</a>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 40px; background-color:#f8fafc; border-top:1px solid #edf2f7; text-align:center;">
                            <p style="margin:0 0 10px 0; font-size:13px; color:#64748b;">Bu e-posta envanter yönetim sistemi tarafından otomatik olarak gönderilmiştir.</p>
                            <p style="margin:0; font-size:14px; font-weight:600; color:#1e293b;">&copy; ' . date('Y') . ' {{BRAND}}. Tüm hakları saklıdır.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>'
          ],
          'en' => [
              'sub' => 'New Asset Assigned: {{ITEM_NAME}}', 
              'body' => '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @media screen and (max-width: 600px) {
            .container { width: 100% !important; border-radius: 0 !important; }
            .content { padding: 30px 20px !important; }
            .header { padding: 30px 20px !important; }
            .meta-box { border-radius: 8px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="140" height="auto" style="width:140px !important; height:auto !important; max-height:50px; display:block; margin:0 auto; border:0; outline:none;">
                        </td>
                    </tr>
                    <tr>
                        <td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">New Asset Assigned 🚀</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Hello <b>{{NAME}}</b>,<br>A new asset has been successfully assigned to you.</p>
                            <div class="meta-box" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Assigned Item</span><br>
                                            <div style="font-size:16px; color:#1e293b; font-weight:600; margin-top:5px; line-height:1.4;">{{ITEM_NAME}}</div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-top:10px;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Assignment Date</span><br>
                                            <span style="font-size:15px; color:#1e293b; font-weight:600;">{{DATE_TIME}}</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <p style="margin:0 0 15px 0; font-size:15px; color:#475569;">Please inspect the asset upon receiving. If you experience any issues, you can report them to the IT department.</p>
                            <div style="text-align:center; margin:35px 0 10px 0;">
                                <a href="{{SITE_URL}}/varliklar" style="background-color:#2563eb; color:#ffffff; padding:14px 32px; text-decoration:none; border-radius:8px; font-weight:600; font-size:15px; display:inline-block; box-shadow:0 4px 6px rgba(37, 99, 235, 0.2);">View My Assets</a>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 40px; background-color:#f8fafc; border-top:1px solid #edf2f7; text-align:center;">
                            <p style="margin:0 0 10px 0; font-size:13px; color:#64748b;">This email was automatically generated by the asset management system.</p>
                            <p style="margin:0; font-size:14px; font-weight:600; color:#1e293b;">&copy; ' . date('Y') . ' {{BRAND}}. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>'
          ]
      ],
      'asset_returned' => [
          'tr' => [
              'sub' => 'Zimmet Geri Alındı: {{ITEM_NAME}}', 
              'body' => '<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @media screen and (max-width: 600px) {
            .container { width: 100% !important; border-radius: 0 !important; }
            .content { padding: 30px 20px !important; }
            .header { padding: 30px 20px !important; }
            .meta-box { border-radius: 8px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="140" height="auto" style="width:140px !important; height:auto !important; max-height:50px; display:block; margin:0 auto; border:0; outline:none;">
                        </td>
                    </tr>
                    <tr>
                        <td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Zimmet Geri Alındı 📥</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Merhaba <b>{{NAME}}</b>,<br>Üzerinizde bulunan varlıklar başarıyla geri alınmış ve sisteme iadesi kaydedilmiştir.</p>
                            <div class="meta-box" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">İade Edilen Öğeler</span><br>
                                            <div style="font-size:16px; color:#1e293b; font-weight:600; margin-top:5px; line-height:1.4;">{{ITEM_NAME}}</div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-top:10px;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">İade Tarihi</span><br>
                                            <span style="font-size:15px; color:#1e293b; font-weight:600;">{{DATE_TIME}}</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <p style="margin:0 0 15px 0; font-size:15px; color:#475569;">Zimmet iade süreci tamamlanmıştır. Herhangi bir sorunuz olması durumunda Bilgi İşlem birimi ile iletişime geçebilirsiniz.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 40px; background-color:#f8fafc; border-top:1px solid #edf2f7; text-align:center;">
                            <p style="margin:0 0 10px 0; font-size:13px; color:#64748b;">Bu e-posta envanter yönetim sistemi tarafından otomatik olarak gönderilmiştir.</p>
                            <p style="margin:0; font-size:14px; font-weight:600; color:#1e293b;">&copy; ' . date('Y') . ' {{BRAND}}. Tüm hakları saklıdır.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>'
          ],
          'en' => [
              'sub' => 'Asset Returned: {{ITEM_NAME}}', 
              'body' => '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @media screen and (max-width: 600px) {
            .container { width: 100% !important; border-radius: 0 !important; }
            .content { padding: 30px 20px !important; }
            .header { padding: 30px 20px !important; }
            .meta-box { border-radius: 8px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="140" height="auto" style="width:140px !important; height:auto !important; max-height:50px; display:block; margin:0 auto; border:0; outline:none;">
                        </td>
                    </tr>
                    <tr>
                        <td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Asset Returned 📥</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Hello <b>{{NAME}}</b>,<br>The asset assigned to you has been successfully returned and recorded in the system.</p>
                            <div class="meta-box" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Returned Item</span><br>
                                            <div style="font-size:16px; color:#1e293b; font-weight:600; margin-top:5px; line-height:1.4;">{{ITEM_NAME}}</div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-top:10px;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Return Date</span><br>
                                            <span style="font-size:15px; color:#1e293b; font-weight:600;">{{DATE_TIME}}</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <p style="margin:0 0 15px 0; font-size:15px; color:#475569;">The return process is complete. If you have any questions, please contact the IT department.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 40px; background-color:#f8fafc; border-top:1px solid #edf2f7; text-align:center;">
                            <p style="margin:0 0 10px 0; font-size:13px; color:#64748b;">This email was automatically generated by the asset management system.</p>
                            <p style="margin:0; font-size:14px; font-weight:600; color:#1e293b;">&copy; ' . date('Y') . ' {{BRAND}}. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>'
          ]
      ],
      'imap_forward' => [
          'tr' => [
              'sub' => '🔔 Yönlendirildi: {{subject}} [{{ticket_no}}]',
              'body' => "Merhaba,<br><br>Bu mesaj IMAP üzerinden sisteme aktarılan yeni bir bilet bildirimidir.<br><br><b>Müşteri:</b> {{customer_name}}<br><b>Konu:</b> {{subject}}<br><b>Bilet No:</b> {{ticket_no}}<br><br><div style='background:#f9f9f9;padding:15px;border-left:4px solid #17a2b8;margin:15px 0;'>{{message}}</div><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#17a2b8;color:#fff;text-decoration:none;border-radius:5px;'>Bileti Görüntüle</a></div>"
          ],
          'en' => [
              'sub' => '🔔 Forwarded: {{subject}} [{{ticket_no}}]',
              'body' => "Hello,<br><br>This message is a notification for a new ticket received via IMAP forwarding.<br><br><b>Customer:</b> {{customer_name}}<br><b>Subject:</b> {{subject}}<br><b>Ticket No:</b> {{ticket_no}}<br><br><div style='background:#f9f9f9;padding:15px;border-left:4px solid #17a2b8;margin:15px 0;'>{{message}}</div><br><div style='text-align:center;'><a href='{{link}}' style='display:inline-block;padding:10px 20px;background:#17a2b8;color:#fff;text-decoration:none;border-radius:5px;'>View Ticket</a></div>"
          ]
      ]
    ];
    
    $d = $defaults[$templateKey][$lang] ?? ($defaults[$templateKey]['tr'] ?? null);
    if ($d) {
        $subject = $subject ?: $d['sub'];
        $body = $body ?: $d['body'];
    }
  }

  $vars['site_title'] = $placeholders['site_title'] ?? 'Destek Merkezi';
  $vars['site_url'] = rtrim($placeholders['site_url'] ?? '', '/');

  // Multi-placeholder support: map common aliases to match user's template editor ({{NAME}}, {{USERNAME}}, etc)
  $extendedVars = $vars;
  foreach($vars as $k => $v) {
      $upperK = strtoupper($k);
      if(!isset($extendedVars[$upperK])) $extendedVars[$upperK] = $v;
      
      // Special mappings for user perception
      if($k === 'customer_name' || $k === 'fullname' || $k === 'name') {
          $extendedVars['NAME'] = $v;
          $extendedVars['CUSTOMER_NAME'] = $v;
          $extendedVars['customer_name'] = $v;
      }
      if($k === 'ticket_no' || $k === 'id') {
          $extendedVars['ID'] = $v;
          $extendedVars['ticket_no'] = $v;
          $extendedVars['TICKET_NO'] = $v;
      }
      if($k === 'subject') {
          $extendedVars['SUBJECT'] = $v;
          $extendedVars['title'] = $v;
      }
      if($k === 'site_title' || $k === 'brand') {
          $extendedVars['BRAND'] = $v;
          $extendedVars['site_title'] = $v;
      }
      if($k === 'DATE' || $k === 'date' || $k === 'date_time' || $k === 'DATE_TIME') {
          $extendedVars['DATE'] = $v;
          $extendedVars['DATE_TIME'] = $v;
          $extendedVars['date_time'] = $v;
          $extendedVars['DATETIME'] = $v;
          $extendedVars['datetime'] = $v;
      }

      // Add automatic company branding
      $extendedVars['COMPANY_NAME'] = getSetting($pdo, 'company_name', 'Eaprimus');
      $extendedVars['mail_from_address'] = getSetting($pdo, 'mail_from_address', '');
  }

  // Translate ITEM_TYPE if present based on $lang
  if (isset($extendedVars['ITEM_TYPE'])) {
      $typeKey = strtolower($extendedVars['ITEM_TYPE']);
      $translatedType = '';
      if ($lang === 'tr') {
          $translatedType = match ($typeKey) {
              'assets' => 'varlık/demirbaş',
              'licenses' => 'lisans',
              'accessories' => 'aksesuar',
              'consumables' => 'sarf malzemesi',
              'components' => 'bileşen',
              default => 'varlık/demirbaş'
          };
      } else {
          $translatedType = match ($typeKey) {
              'assets' => 'asset',
              'licenses' => 'license',
              'accessories' => 'accessory',
              'consumables' => 'consumable',
              'components' => 'component',
              default => 'asset'
          };
      }
      $extendedVars['ITEM_TYPE'] = $translatedType;
      $extendedVars['item_type'] = $translatedType;
  }

  // EKLERİ MESSAGE DEĞİŞKENİNİN İÇİNE GÖM (BÖYLECE FOOTER'IN ÜSTÜNDE KALIR)
  $attachmentsHtml = '';
  if (!empty($attachments)) {
      $headerText = ($lang === 'tr') ? 'Ek Dosyalar:' : 'Attachments:';
      $pColor = getSetting($pdo, 'primary_color', '#1e3c72');
      
      $attachmentsHtml = '<div style="margin-top: 25px; padding: 15px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">';
      $attachmentsHtml .= '<h4 style="margin: 0 0 10px 0; font-size: 14px; color: #334155; font-weight: 600;">' . htmlspecialchars($headerText) . '</h4>';
      $attachmentsHtml .= '<ul style="margin: 0; padding: 0; list-style: none;">';
      
      foreach ($attachments as $att) {
          $attName = htmlspecialchars($att['name'] ?? 'File');
          $attUrl = htmlspecialchars($att['url'] ?? '#');
          
          $attachmentsHtml .= '<li style="margin-bottom: 8px; font-size: 13px; line-height: 1.5;">';
          $attachmentsHtml .= '<a href="' . $attUrl . '" target="_blank" style="color: ' . $pColor . '; text-decoration: none; font-weight: 600; display: inline-block;">';
          $attachmentsHtml .= '📎 ' . $attName;
          $attachmentsHtml .= '</a>';
          $attachmentsHtml .= '</li>';
      }
      
      $attachmentsHtml .= '</ul>';
      $attachmentsHtml .= '</div>';
      
      // Eğer MESSAGE değişkeni varsa, ekleri hemen altına yapıştır (Şablonun içine gömülmüş olur)
      if (isset($extendedVars['MESSAGE'])) {
          $extendedVars['MESSAGE'] .= $attachmentsHtml;
      }
      if (isset($extendedVars['message'])) {
          $extendedVars['message'] .= $attachmentsHtml;
      }
  }

  // Support for {{ var }} with spaces and case-insensitive
  foreach ($extendedVars as $k => $v) {
    if ($v === null) $v = '';
    $valStr = (string)$v;
    $replaceVal = (stripos($body, '<html') !== false || stripos($body, '&lt;html') !== false) ? $valStr : nl2br($valStr);

    $patterns = [
        '/\{\{\s*' . preg_quote($k, '/') . '\s*\}\}/i',
        '/\[\[\s*' . preg_quote($k, '/') . '\s*\]\]/i'
    ];
    
    $subject = preg_replace($patterns, $valStr, $subject);
    $body = preg_replace($patterns, $replaceVal, $body);
  }

  // Eğer şablonda MESSAGE değişkeni kullanılmamışsa ve yine de ekler varsa, en sona fallback olarak ekle
  if (!empty($attachmentsHtml) && !isset($extendedVars['MESSAGE']) && !isset($extendedVars['message'])) {
      $body .= $attachmentsHtml;
  }

  $htmlContent = buildMailTemplate($body, $signature);
  return sendEaprimusMail($toEmail, $toName, $subject, $htmlContent, $attachments);
}

function getSetting($pdo, $key, $default = '') {
    if (!$pdo) return $default;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false) ? $val : $default;
    } catch(Exception $e) {
        return $default;
    }
}
?>

