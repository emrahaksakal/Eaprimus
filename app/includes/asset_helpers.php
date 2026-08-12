<?php
if (!function_exists('convertTurkishToAscii')) {
    function convertTurkishToAscii($str) {
        if (empty($str)) return '';
        
        $utf8_map = [
            "\xc3\x87" => 'C', "\xc3\xa7" => 'c',
            "\xc4\x9e" => 'G', "\xc4\x9f" => 'g',
            "\xc4\xb1" => 'i', "\xc4\xb0" => 'I',
            "\xc3\x96" => 'O', "\xc3\xb6" => 'o',
            "\xc5\x9e" => 'S', "\xc5\x9f" => 's',
            "\xc3\x9c" => 'U', "\xc3\xbc" => 'u',
        ];
        
        $ansi_map = [
            "\xc7" => 'C', "\xe7" => 'c',
            "\xd0" => 'G', "\xf0" => 'g',
            "\xfd" => 'i', "\xdd" => 'I',
            "\xd6" => 'O', "\xf6" => 'o',
            "\xde" => 'S', "\xfe" => 's',
            "\xdc" => 'U', "\xfc" => 'u'
        ];
        
        $str = str_replace(array_keys($utf8_map), array_values($utf8_map), $str);
        $str = str_replace(array_keys($ansi_map), array_values($ansi_map), $str);
        
        return $str;
    }
}

if (!function_exists('cleanCpuName')) {
    function cleanCpuName($cpu) {
        if (empty($cpu)) return '-';
        $cpu = preg_replace('/\s*\(R\)/i', '', $cpu);
        $cpu = preg_replace('/\s*\(TM\)/i', '', $cpu);
        $cpu = preg_replace('/\s*CPU\b/i', '', $cpu);
        $cpu = preg_replace('/\s*Processor\b/i', '', $cpu);
        $cpu = preg_replace('/\s*Core\b/i', '', $cpu);
        $cpu = preg_replace('/\s*@\s*\d+(\.\d+)?\s*GHz/i', '', $cpu);
        $cpu = preg_replace('/\s*with Radeon.*Graphics/i', '', $cpu);
        $cpu = preg_replace('/\s+/', ' ', $cpu);
        return trim($cpu);
    }
}

/**
 * Asset Timeline / Log Yardımcı Fonksiyon
 * Her yerde require_once ile dahil edilebilir.
 */

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function addAssetLog($pdo, $asset_id, $user_id, $event_type, $event_description, $context_id = null, $item_type = 'asset', $context_type = null)
{
    try {
        $stmt = $pdo->prepare("INSERT INTO asset_timeline (asset_id, item_type, user_id, event_type, event_description, context_id, context_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$asset_id, $item_type, $user_id, $event_type, $event_description, $context_id, $context_type]);

        // General System Log integration (English)
        if (!function_exists('systemLog')) {
            @include_once __DIR__ . '/logger.php';
        }
        if (function_exists('systemLog')) {
            $sysLogMsg = "[ASSET_EVENT] Type: " . strtoupper($item_type) . " | ID: $asset_id | Action: " . strtoupper($event_type) . " | Info: $event_description | Actor User ID: $user_id | Target Context ID: " . ($context_id ?? 'None') . " | IP: " . (function_exists('getRealIpAddr') ? getRealIpAddr() : 'SYSTEM');
            systemLog(strtoupper($item_type) . '_' . strtoupper($event_type), $sysLogMsg);
        }
    } catch (Throwable $e) {
        // Sessizce log at, sayfayı kırmasın
        error_log("AssetLog error: " . $e->getMessage());
    }
}
function getAssetTimeline($pdo, $item_id, $item_type = 'asset', $limit = null, $offset = null)
{
    $limitSql = "";
    if ($limit !== null) {
        $limitSql = " LIMIT " . (int)$limit;
        if ($offset !== null) {
            $limitSql .= " OFFSET " . (int)$offset;
        }
    }

    $role = $_SESSION['role'] ?? 3;
    $current_user_id = $_SESSION['user_id'] ?? 0;
    
    $whereFilter = "";
    $params = [$item_id, $item_type, $item_id, $item_type];
    
    if ($role == 2) {
        $whereFilter = " AND (at.user_id = ? OR at.context_id = ?)";
        $params[] = $current_user_id;
        $params[] = $current_user_id;
    }

    $fullnameSelect = ($role == 2) 
        ? "CASE WHEN at.user_id = $current_user_id THEN u.fullname ELSE 'Sistem / Yetkili' END as fullname" 
        : "u.fullname";

    $stmt = $pdo->prepare("
        SELECT at.*, $fullnameSelect, 
               CASE 
                 WHEN at.item_type = 'asset' THEN a.name
                 WHEN at.item_type = 'consumable' THEN c.name
                 WHEN at.item_type = 'accessory' THEN acc.name
                 WHEN at.item_type = 'component' THEN comp.name
                 WHEN at.item_type = 'license' THEN lic.software_name
                 ELSE NULL 
               END as item_name
        FROM asset_timeline at 
        LEFT JOIN users u ON at.user_id = u.id 
        LEFT JOIN assets a ON (at.item_type = 'asset' AND at.asset_id = a.id)
        LEFT JOIN asset_consumables c ON (at.item_type = 'consumable' AND at.asset_id = c.id)
        LEFT JOIN asset_accessories acc ON (at.item_type = 'accessory' AND (at.asset_id = acc.id OR at.context_id = acc.id))
        LEFT JOIN asset_components comp ON (at.item_type = 'component' AND at.asset_id = comp.id)
        LEFT JOIN asset_licenses lic ON (at.item_type = 'license' AND at.asset_id = lic.id)
        WHERE ((at.asset_id = ? AND at.item_type = ?) 
           OR (at.context_id = ? AND at.item_type = ?))
           AND at.is_deleted = 0
           $whereFilter
        ORDER BY at.created_at DESC
        $limitSql
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAssetTimelineCount($pdo, $item_id, $item_type = 'asset')
{
    $role = $_SESSION['role'] ?? 3;
    $current_user_id = $_SESSION['user_id'] ?? 0;
    
    $whereFilter = "";
    $params = [$item_id, $item_type, $item_id, $item_type];
    
    if ($role == 2) {
        $whereFilter = " AND (at.user_id = ? OR at.context_id = ?)";
        $params[] = $current_user_id;
        $params[] = $current_user_id;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM asset_timeline at 
        WHERE ((at.asset_id = ? AND at.item_type = ?) 
           OR (at.context_id = ? AND at.item_type = ?))
           AND at.is_deleted = 0
           $whereFilter
    ");
    $stmt->execute($params);
    return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
}

function getUserTimeline($pdo, $user_id)
{
    $role = $_SESSION['role'] ?? 3;
    $curr_u = $_SESSION['user_id'] ?? 0;
    $performerSelect = ($role == 2) 
        ? "CASE WHEN at.user_id = $curr_u THEN u.fullname ELSE 'Sistem / Yetkili' END as performer_name" 
        : "u.fullname as performer_name";

    // Fetch logs where user is the TARGET (context_id)
    $stmt = $pdo->prepare("
        SELECT at.*, 
               $performerSelect,
               CASE 
                 WHEN at.item_type = 'asset' THEN a.name
                 WHEN at.item_type = 'consumable' THEN c.name
                 WHEN at.item_type = 'accessory' THEN acc.name
                 WHEN at.item_type = 'component' THEN comp.name
                 WHEN at.item_type = 'license' THEN lic.software_name
                 ELSE '[Silinmiş Öğe]'
               END as item_name,
               CASE 
                 WHEN at.item_type = 'asset' THEN a.deleted_at
                 WHEN at.item_type = 'consumable' THEN c.deleted_at
                 WHEN at.item_type = 'accessory' THEN acc.deleted_at
                 WHEN at.item_type = 'component' THEN comp.deleted_at
                 WHEN at.item_type = 'license' THEN lic.deleted_at
                 ELSE NULL
               END as item_deleted
        FROM asset_timeline at 
        LEFT JOIN users u ON at.user_id = u.id 
        LEFT JOIN assets a ON (at.item_type = 'asset' AND at.asset_id = a.id)
        LEFT JOIN asset_consumables c ON (at.item_type = 'consumable' AND at.asset_id = c.id)
        LEFT JOIN asset_accessories acc ON (at.item_type = 'accessory' AND (at.asset_id = acc.id OR at.context_id = acc.id))
        LEFT JOIN asset_components comp ON (at.item_type = 'component' AND at.asset_id = comp.id)
        LEFT JOIN asset_licenses lic ON (at.item_type = 'license' AND at.asset_id = lic.id)
        WHERE at.context_id = ? AND at.is_deleted = 0
        ORDER BY at.created_at DESC
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ensureInventoryColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    try {
        // Use IF NOT EXISTS - works on MySQL 5.7+ and MariaDB. Never fails even if column exists.
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN IF NOT EXISTS `{$column}` {$definition}");
    } catch (Exception $e) {
        // Fallback: check manually and add if missing
        if (!tableHasColumn($pdo, $table, $column)) {
            try {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            } catch (Exception $e2) {
                // Column likely already exists in a slightly different form, ignore
            }
        }
    }
}

/**
 * Kullanıcı zimmet onayını (imza) yönetir.
 * Pasif kullanıcılarda otomatik onaylanır, aktiflerde 'pending' (beklemede) kalır.
 */
function handleSignature(PDO $pdo, int $userId, ?int $itemId, string $itemType = 'asset', array $payload = [], string $actionType = 'checkout'): bool
{
    try {
        // Kullanıcı durumunu çek (0: Pasif, 1: Aktif)
        $stmtUser = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $userStatus = $stmtUser->fetchColumn();

        // Pasif ise personel imzasını atla (pending_admin'e düşür), aktif ise personeli bekle (pending_user)
        $status = ($userStatus == 0) ? 'pending_admin' : 'pending_user';
        $bypassUser = ($userStatus == 0) ? 1 : 0;
        $signedAt = null;

        $assetId = null;
        $accessoryId = null;
        $componentId = null;
        $licenseId = null;

        if ($itemType === 'asset') {
            $assetId = $itemId;
        } elseif ($itemType === 'accessory') {
            $accessoryId = $itemId;
        } elseif ($itemType === 'component') {
            $componentId = $itemId;
        } elseif ($itemType === 'license') {
            $licenseId = $itemId;
        }

        $createdBy = $payload['created_by'] ?? $_SESSION['user_id'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO asset_signatures (asset_id, accessory_id, component_id, license_id, user_id, status, signed_at, template_id, action_type, bypass_user_signature, created_by) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE status = VALUES(status), signed_at = VALUES(signed_at), action_type = VALUES(action_type), bypass_user_signature = VALUES(bypass_user_signature), created_by = VALUES(created_by)");
        $stmt->execute([$assetId, $accessoryId, $componentId, $licenseId, $userId, $status, $signedAt, $payload['template_id'] ?? null, $actionType, $bypassUser, $createdBy]);
        return true;
    } catch (Exception $e) {
        error_log("handleSignature error: " . $e->getMessage());
        return false;
    }
}


if (!function_exists('get_setting_fallback')) {
    function get_setting_fallback($key, $default = '') {
        if (function_exists('s')) {
            return s($key, $default);
        }
        return $default;
    }
}

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('translateLogDescription')) {
    function translateLogDescription($desc, $isTr) {
        if (empty($desc)) return '';
        $desc = (string)$desc;

        // 1. Normalize Turkish mojibake first
        if (function_exists('normalize_turkish_mojibake')) {
            $desc = normalize_turkish_mojibake($desc);
        }

        // 2. Double Language split
        if (strpos($desc, ' / ') !== false) {
            $parts = explode(' / ', $desc);
            $desc = $isTr ? trim($parts[0]) : trim($parts[1]);
        }

        // 3. Status transitions and info update translations
        if ($isTr) {
            $desc = preg_replace('/^Info updated:\s*/i', 'Bilgi güncellendi: ', $desc);
            $desc = preg_replace('/Status:\s*/i', 'Durum: ', $desc);
        } else {
            $desc = preg_replace('/^Bilgi güncellendi:\s*/i', 'Info updated: ', $desc);
            $desc = preg_replace('/Durum:\s*/i', 'Status: ', $desc);
        }

        // Translate individual status values in the transition
        $statusMap = [
            'Hazır' => ['tr' => 'Hazır', 'en' => 'Ready'],
            'Ready' => ['tr' => 'Hazır', 'en' => 'Ready'],
            'Arızalı' => ['tr' => 'Arızalı', 'en' => 'Faulty'],
            'Faulty' => ['tr' => 'Arızalı', 'en' => 'Faulty'],
            'Hurda' => ['tr' => 'Hurda', 'en' => 'Scrap'],
            'Scrap' => ['tr' => 'Hurda', 'en' => 'Scrap'],
            'Hurdaya Ayrılmış' => ['tr' => 'Hurda', 'en' => 'Scrap'],
            'Atanmış' => ['tr' => 'Atanmış', 'en' => 'Assigned'],
            'Assigned' => ['tr' => 'Atanmış', 'en' => 'Assigned'],
            'İmza Bekliyor' => ['tr' => 'İmza Bekliyor', 'en' => 'Pending Signature'],
            'Pending Signature' => ['tr' => 'İmza Bekliyor', 'en' => 'Pending Signature'],
            'Beklemede' => ['tr' => 'Beklemede', 'en' => 'Pending'],
            'Pending' => ['tr' => 'Beklemede', 'en' => 'Pending'],
            'Yok' => ['tr' => 'Yok', 'en' => 'None'],
            'None' => ['tr' => 'Yok', 'en' => 'None']
        ];

        foreach ($statusMap as $key => $vals) {
            $target = $isTr ? $vals['tr'] : $vals['en'];
            $desc = preg_replace('/\b' . preg_quote($key, '/') . '\b/u', $target, $desc);
        }


        // 2. Checkin / Return Approved
        if (stripos($desc, 'İade onaylandı ve imzalandı') !== false || stripos($desc, 'Return approved and signed') !== false) {
            return $isTr 
                ? "İade onaylandı ve imzalandı (Resmi Tutanak Oluşturuldu)." 
                : "Return approved and signed (Official Protocol Generated).";
        }

        // 3. Checkout Approved
        if (stripos($desc, 'Zimmet onaylandı ve imzalandı') !== false || stripos($desc, 'Checkout approved and signed') !== false) {
            return $isTr 
                ? "Zimmet onaylandı ve imzalandı (Resmi Tutanak Oluşturuldu)." 
                : "Checkout approved and signed (Official Protocol Generated).";
        }

        // 4. Checkin dynamic
        if (preg_match('/^(.*?) personeli üzerinden geri alındı\s*\((Sebep|Reason):\s*(.*?)\s*-\s*(.*?)\)$/ui', $desc, $matches)) {
            $user = trim($matches[1]);
            $reason = trim($matches[3]);
            $status = trim($matches[4]);
            
            $statusMap = [
                'hasarsiz' => ['tr' => 'Hasarsız', 'en' => 'Undamaged'],
                'hasarli' => ['tr' => 'Hasarlı', 'en' => 'Damaged'],
                'hasarsız' => ['tr' => 'Hasarsız', 'en' => 'Undamaged'],
                'hasarlı' => ['tr' => 'Hasarlı', 'en' => 'Damaged'],
                'undamaged' => ['tr' => 'Hasarsız', 'en' => 'Undamaged'],
                'damaged' => ['tr' => 'Hasarlı', 'en' => 'Damaged']
            ];
            $normStatus = $statusMap[strtolower($status)] ?? ['tr' => $status, 'en' => $status];
            
            $reasonMap = [
                'geri alma' => ['tr' => 'Geri Alma', 'en' => 'Check In'],
                'check in' => ['tr' => 'Geri Alma', 'en' => 'Check In']
            ];
            $normReason = $reasonMap[strtolower($reason)] ?? ['tr' => $reason, 'en' => $reason];
            
            if ($isTr) {
                return "{$user} personeli üzerinden geri alındı (Sebep: " . $normReason['tr'] . " - " . $normStatus['tr'] . ")";
            } else {
                return "Checked in from user {$user} (Reason: " . $normReason['en'] . " - " . $normStatus['en'] . ")";
            }
        }
        if (preg_match('/^Checked in from user (.*?)\s*\((Sebep|Reason):\s*(.*?)\s*-\s*(.*?)\)$/ui', $desc, $matches)) {
            $user = trim($matches[1]);
            $reason = trim($matches[3]);
            $status = trim($matches[4]);
            
            $statusMap = [
                'hasarsiz' => ['tr' => 'Hasarsız', 'en' => 'Undamaged'],
                'hasarli' => ['tr' => 'Hasarlı', 'en' => 'Damaged'],
                'hasarsız' => ['tr' => 'Hasarsız', 'en' => 'Undamaged'],
                'hasarlı' => ['tr' => 'Hasarlı', 'en' => 'Damaged'],
                'undamaged' => ['tr' => 'Hasarsız', 'en' => 'Undamaged'],
                'damaged' => ['tr' => 'Hasarlı', 'en' => 'Damaged']
            ];
            $normStatus = $statusMap[strtolower($status)] ?? ['tr' => $status, 'en' => $status];
            
            $reasonMap = [
                'geri alma' => ['tr' => 'Geri Alma', 'en' => 'Check In'],
                'check in' => ['tr' => 'Geri Alma', 'en' => 'Check In']
            ];
            $normReason = $reasonMap[strtolower($reason)] ?? ['tr' => $reason, 'en' => $reason];
            
            if ($isTr) {
                return "{$user} personeli üzerinden geri alındı (Sebep: " . $normReason['tr'] . " - " . $normStatus['tr'] . ")";
            } else {
                return "Checked in from user {$user} (Reason: " . $normReason['en'] . " - " . $normStatus['en'] . ")";
            }
        }

        // 5. Automatic checkin
        if (preg_match('/^Durum \'(.*?)\' olarak değiştiği için zimmet otomatik olarak geri alındı\.$/ui', $desc, $matches)) {
            $status = trim($matches[1]);
            if ($isTr) return "Durum '{$status}' olarak değiştiği için zimmet otomatik olarak geri alındı.";
            return "Automatic check-in because status changed to '{$status}'.";
        }
        if (preg_match('/^Automatic check-in because status changed to \'(.*?)\'\.$/ui', $desc, $matches)) {
            $status = trim($matches[1]);
            if ($isTr) return "Durum '{$status}' olarak değiştiği için zimmet otomatik olarak geri alındı.";
            return "Automatic check-in because status changed to '{$status}'.";
        }

        // 6. Checkout dynamic
        if (preg_match('/^(.*?) personeline zimmetlendi$/ui', $desc, $matches)) {
            $user = trim($matches[1]);
            if ($isTr) return "{$user} personeline zimmetlendi";
            return "Checked out to {$user}";
        }
        if (preg_match('/^Checked out to (.*?)$/ui', $desc, $matches)) {
            $user = trim($matches[1]);
            if ($isTr) return "{$user} personeline zimmetlendi";
            return "Checked out to {$user}";
        }

        return $desc;
    }
}

/**
 * Üst seviye ve yetkilendirilmiş donanım teslim PDF'i üretir.
 * Dijital imza varsa görselini yerleştirir, yoksa (Kağıt/Islak İmza) imza alanını fiziksel kullanım için boş bırakır.
 */
function generateHandoverPDF(PDO $pdo, int $item_id, string $item_type, int $target_user_id, ?string $signature, ?string $sig_notes, bool $isTr, int $current_user_id, ?string $admin_signature = null): int
{
    // PDF için kullanıcı bilgilerini çek
    $userInfo = $pdo->query("SELECT u.fullname, u.username, b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.id = $target_user_id")->fetch();
    if (!$userInfo) {
        throw new Exception($isTr ? "Hedef kullanıcı bulunamadı." : "Target user not found.");
    }

    require_once __DIR__ . '/../../libs/tcpdf/tcpdf.php';
    
    // Fetch full asset, model and user details based on type
    $adminInfo = $pdo->query("SELECT u.fullname, b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.id = $current_user_id")->fetch();
    if (!$adminInfo) {
        $adminInfo = ['fullname' => 'Sistem Yöneticisi', 'dept_name' => ''];
    }
    
    // Check signature record for admin_name override
    $adminNameOverride = null;
    $colName = match($item_type) {
        'accessory' => 'accessory_id',
        'component' => 'component_id',
        'license' => 'license_id',
        default => 'asset_id'
    };
    $stmtSig = $pdo->prepare("SELECT admin_name FROM asset_signatures WHERE `{$colName}` = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
    $stmtSig->execute([$item_id]);
    $adminNameOverride = $stmtSig->fetchColumn();
    if (!empty($adminNameOverride)) {
        $adminInfo['fullname'] = $adminNameOverride;
    }
    
    $specs = [];
    if ($item_type === 'asset') {
        $assetInfo = $pdo->query("SELECT a.*, m.name as model_name, c.name as category_name, co.name as company_name, b.bolum_adi as dept_name 
                                 FROM assets a 
                                 LEFT JOIN asset_models m ON a.model_id = m.id 
                                 LEFT JOIN asset_categories c ON a.category_id = c.id
                                 LEFT JOIN asset_companies co ON a.company_id = co.id
                                 LEFT JOIN bolumler b ON a.department_id = b.id
                                 WHERE a.id = $item_id")->fetch();
        
        $stmtSpecs = $pdo->prepare("SELECT cf.field_label, fv.value FROM inventory_asset_field_values fv JOIN inventory_custom_fields cf ON fv.field_id = cf.id WHERE fv.asset_id = ?");
        $stmtSpecs->execute([$item_id]);
        while($s = $stmtSpecs->fetch(PDO::FETCH_ASSOC)) {
            $specs[$s['field_label']] = $s['value'];
        }
        
        $rawSpecs = [];
        if (!empty($assetInfo['specs'])) {
            $rawSpecs = json_decode($assetInfo['specs'], true);
        }
        if (!is_array($rawSpecs)) {
            $rawSpecs = [];
        }

        $getVal = function($key, $col) use ($rawSpecs, $assetInfo) {
            if (!empty($rawSpecs[$key])) return $rawSpecs[$key];
            if (!empty($assetInfo[$col])) return $assetInfo[$col];
            return null;
        };

        // 1. IP and MAC Addresses (Ethernet vs Wi-Fi)
        $ethIp = $getVal('ethernet_ip', 'ip_address');
        $ethMac = $getVal('ethernet_mac', 'mac_address');
        $wifiIp = $getVal('wifi_ip', null);
        $wifiMac = $getVal('wifi_mac', null);

        if (empty($ethMac) && !empty($assetInfo['mac_address'])) {
            $ethMac = $assetInfo['mac_address'];
        }
        if (empty($ethIp) && !empty($assetInfo['ip_address'])) {
            $ethIp = $assetInfo['ip_address'];
        }

        if (!empty($ethIp)) {
            $specs[$isTr ? 'IP Adresi (Ethernet)' : 'IP Address (Ethernet)'] = $ethIp;
        }
        if (!empty($ethMac)) {
            $specs[$isTr ? 'MAC Adresi (Ethernet)' : 'MAC Address (Ethernet)'] = $ethMac;
        }
        if (!empty($wifiIp)) {
            $specs[$isTr ? 'IP Adresi (Wi-Fi)' : 'IP Address (Wi-Fi)'] = $wifiIp;
        }
        if (!empty($wifiMac)) {
            $specs[$isTr ? 'MAC Adresi (Wi-Fi)' : 'MAC Address (Wi-Fi)'] = $wifiMac;
        }

        if (empty($specs)) {
            $generalIp = $getVal('ip_address', 'ip_address');
            $generalMac = $getVal('mac_address', 'mac_address');
            if (!empty($generalIp)) {
                $specs[$isTr ? 'IP Adresi' : 'IP Address'] = $generalIp;
            }
            if (!empty($generalMac)) {
                $specs[$isTr ? 'MAC Adresi' : 'MAC Address'] = $generalMac;
            }
        }

        // 2. Operating System
        $osVal = $getVal('os', 'os');
        if (!empty($osVal)) {
            $specs[$isTr ? 'İşletim Sistemi' : 'Operating System'] = $osVal;
        }

        // 3. CPU
        $cpuVal = $getVal('cpu', 'cpu');
        if (!empty($cpuVal)) {
            $specs[$isTr ? 'İşlemci (CPU)' : 'Processor (CPU)'] = cleanCpuName($cpuVal);
        }

        // 4. RAM
        $ramVal = $getVal('ram_gb', 'ram');
        if (!empty($ramVal)) {
            if (is_numeric($ramVal)) {
                $ramVal = $ramVal . ' GB';
            } elseif (stripos($ramVal, 'GB') === false) {
                $ramVal = $ramVal . ' GB';
            }
            $specs[$isTr ? 'Bellek (RAM)' : 'Memory (RAM)'] = $ramVal;
        }

        // 5. GPU
        $gpuVal = $getVal('gpu', 'gpu');
        if (!empty($gpuVal)) {
            $specs[$isTr ? 'Ekran Kartı (GPU)' : 'Graphics Card (GPU)'] = $gpuVal;
        }

        // 6. Disk
        $diskVal = $getVal('disk', 'disk');
        if (empty($diskVal)) {
            $diskVal = $getVal('disk_c_total_gb', null);
        }
        if (!empty($diskVal)) {
            if (is_numeric($diskVal)) {
                $diskVal = $diskVal . ' GB';
            } elseif (stripos($diskVal, 'GB') === false) {
                $diskVal = $diskVal . ' GB';
            }
            $specs[$isTr ? 'Disk' : 'Disk'] = $diskVal;
        }

        // 7. Monitor
        $monitorVal = $getVal('monitor', 'monitor');
        if (!empty($monitorVal)) {
            $specs[$isTr ? 'Monitör' : 'Monitor'] = $monitorVal;
        }

        // 8. Mainboard
        $mainboardVal = $getVal('mainboard', 'mainboard');
        if (!empty($mainboardVal)) {
            $specs[$isTr ? 'Anakart' : 'Mainboard'] = $mainboardVal;
        }
    } elseif ($item_type === 'accessory') {
        $assetInfo = $pdo->query("SELECT a.*, a.model_no as model_name, c.name as category_name, co.name as company_name, b.bolum_adi as dept_name, mfg.name as manufacturer_name 
                                 FROM asset_accessories a 
                                 LEFT JOIN asset_categories c ON a.category_id = c.id
                                 LEFT JOIN asset_companies co ON a.company_id = co.id
                                 LEFT JOIN bolumler b ON a.department_id = b.id
                                 LEFT JOIN asset_manufacturers mfg ON a.manufacturer_id = mfg.id
                                 WHERE a.id = $item_id")->fetch();
        
        $totalQty = intval($assetInfo['total_qty'] ?? 1);
        $stmtChk = $pdo->prepare("SELECT id FROM asset_accessory_checkouts WHERE accessory_id = ? AND user_id = ? AND (transaction_type = 'assign' OR transaction_type IS NULL) ORDER BY id DESC LIMIT 1");
        $stmtChk->execute([$item_id, $target_user_id]);
        $chkId = $stmtChk->fetchColumn();
        if (!$chkId) {
            $stmtChk2 = $pdo->prepare("SELECT id FROM asset_accessory_checkouts WHERE accessory_id = ? AND (transaction_type = 'assign' OR transaction_type IS NULL) ORDER BY id DESC LIMIT 1");
            $stmtChk2->execute([$item_id]);
            $chkId = $stmtChk2->fetchColumn();
        }
        if ($chkId) {
            $stmtRank = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM asset_accessory_checkouts WHERE accessory_id = ? AND id <= ? AND (transaction_type = 'assign' OR transaction_type IS NULL)");
            $stmtRank->execute([$item_id, $chkId]);
            $rank = intval($stmtRank->fetchColumn() ?: 1);
        } else {
            $stmtRankFallback = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM asset_accessory_checkouts WHERE accessory_id = ? AND (transaction_type = 'assign' OR transaction_type IS NULL)");
            $stmtRankFallback->execute([$item_id]);
            $rank = max(1, intval($stmtRankFallback->fetchColumn() ?: 1));
        }
        $totalQty = max($totalQty, $rank);

        if (!empty($assetInfo['model_no'])) $specs[$isTr ? 'Model No' : 'Model No'] = $assetInfo['model_no'];
        if (!empty($assetInfo['manufacturer_name'])) $specs[$isTr ? 'Üretici' : 'Manufacturer'] = $assetInfo['manufacturer_name'];
        $specs[$isTr ? 'Adet' : 'Quantity'] = "$rank / $totalQty";
        if (!empty($assetInfo['purchase_cost'])) $specs[$isTr ? 'Satın Alma Bedeli' : 'Purchase Cost'] = $assetInfo['purchase_cost'] . ' ' . ($assetInfo['purchase_currency'] ?? 'TRY');
        if (!empty($assetInfo['notes'])) $specs[$isTr ? 'Notlar' : 'Notes'] = $assetInfo['notes'];
    } elseif ($item_type === 'component') {
        $assetInfo = $pdo->query("SELECT a.*, '-' as model_name, c.name as category_name, co.name as company_name, b.bolum_adi as dept_name, mfg.name as manufacturer_name 
                                 FROM asset_components a 
                                 LEFT JOIN asset_categories c ON a.category_id = c.id
                                 LEFT JOIN asset_companies co ON a.company_id = co.id
                                 LEFT JOIN bolumler b ON a.department_id = b.id
                                 LEFT JOIN asset_manufacturers mfg ON a.manufacturer_id = mfg.id
                                 WHERE a.id = $item_id")->fetch();
        
        $totalQty = intval($assetInfo['total_qty'] ?? 1);
        $stmtChk = $pdo->prepare("SELECT id FROM asset_component_checkouts WHERE component_id = ? AND user_id = ? AND (transaction_type = 'assign' OR transaction_type IS NULL) ORDER BY id DESC LIMIT 1");
        $stmtChk->execute([$item_id, $target_user_id]);
        $chkId = $stmtChk->fetchColumn();
        if (!$chkId) {
            $stmtChk2 = $pdo->prepare("SELECT id FROM asset_component_checkouts WHERE component_id = ? AND (transaction_type = 'assign' OR transaction_type IS NULL) ORDER BY id DESC LIMIT 1");
            $stmtChk2->execute([$item_id]);
            $chkId = $stmtChk2->fetchColumn();
        }
        if ($chkId) {
            $stmtRank = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM asset_component_checkouts WHERE component_id = ? AND id <= ? AND (transaction_type = 'assign' OR transaction_type IS NULL)");
            $stmtRank->execute([$item_id, $chkId]);
            $rank = intval($stmtRank->fetchColumn() ?: 1);
        } else {
            $stmtRankFallback = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM asset_component_checkouts WHERE component_id = ? AND (transaction_type = 'assign' OR transaction_type IS NULL)");
            $stmtRankFallback->execute([$item_id]);
            $rank = max(1, intval($stmtRankFallback->fetchColumn() ?: 1));
        }
        $totalQty = max($totalQty, $rank);

        if (!empty($assetInfo['manufacturer_name'])) $specs[$isTr ? 'Üretici' : 'Manufacturer'] = $assetInfo['manufacturer_name'];
        $specs[$isTr ? 'Adet' : 'Quantity'] = "$rank / $totalQty";
        if (!empty($assetInfo['purchase_cost'])) $specs[$isTr ? 'Satın Alma Bedeli' : 'Purchase Cost'] = $assetInfo['purchase_cost'] . ' ' . ($assetInfo['purchase_currency'] ?? 'TRY');
        if (!empty($assetInfo['notes'])) $specs[$isTr ? 'Notlar' : 'Notes'] = $assetInfo['notes'];
    } elseif ($item_type === 'license') {
        $assetInfo = $pdo->query("SELECT a.*, a.software_name as name, '-' as model_name, c.name as category_name, co.name as company_name, b.bolum_adi as dept_name, mfg.name as manufacturer_name 
                                 FROM asset_licenses a 
                                 LEFT JOIN asset_categories c ON a.category_id = c.id
                                 LEFT JOIN asset_companies co ON a.company_id = co.id
                                 LEFT JOIN bolumler b ON a.department_id = b.id
                                 LEFT JOIN asset_manufacturers mfg ON a.manufacturer_id = mfg.id
                                 WHERE a.id = $item_id")->fetch();
        
        $totalQty = intval($assetInfo['seats'] ?? 1);
        $stmtChk = $pdo->prepare("SELECT id FROM asset_license_checkouts WHERE license_id = ? AND user_id = ? AND (transaction_type = 'assign' OR transaction_type IS NULL) ORDER BY id DESC LIMIT 1");
        $stmtChk->execute([$item_id, $target_user_id]);
        $chkId = $stmtChk->fetchColumn();
        if (!$chkId) {
            $stmtChk2 = $pdo->prepare("SELECT id FROM asset_license_checkouts WHERE license_id = ? AND (transaction_type = 'assign' OR transaction_type IS NULL) ORDER BY id DESC LIMIT 1");
            $stmtChk2->execute([$item_id]);
            $chkId = $stmtChk2->fetchColumn();
        }
        if ($chkId) {
            $stmtRank = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM asset_license_checkouts WHERE license_id = ? AND id <= ? AND (transaction_type = 'assign' OR transaction_type IS NULL)");
            $stmtRank->execute([$item_id, $chkId]);
            $rank = intval($stmtRank->fetchColumn() ?: 1);
        } else {
            $stmtRankFallback = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM asset_license_checkouts WHERE license_id = ? AND (transaction_type = 'assign' OR transaction_type IS NULL)");
            $stmtRankFallback->execute([$item_id]);
            $rank = max(1, intval($stmtRankFallback->fetchColumn() ?: 1));
        }
        $totalQty = max($totalQty, $rank);

        if (!empty($assetInfo['license_key'])) $specs[$isTr ? 'Lisans Anahtarı' : 'License Key'] = $assetInfo['license_key'];
        if (!empty($assetInfo['license_email'])) $specs[$isTr ? 'Lisans Maili' : 'License Email'] = $assetInfo['license_email'];
        if (!empty($assetInfo['license_name'])) $specs[$isTr ? 'Lisans Sahibi' : 'License Owner'] = $assetInfo['license_name'];
        $specs[$isTr ? 'Koltuk Sayısı' : 'Number of Seats'] = "$rank / $totalQty";
        if (!empty($assetInfo['expire_date'])) $specs[$isTr ? 'Bitiş Tarihi' : 'Expiration Date'] = $assetInfo['expire_date'];
        if (!empty($assetInfo['purchase_cost'])) $specs[$isTr ? 'Satın Alma Bedeli' : 'Purchase Cost'] = $assetInfo['purchase_cost'] . ' ' . ($assetInfo['purchase_currency'] ?? 'TRY');
        if (!empty($assetInfo['notes'])) $specs[$isTr ? 'Notlar' : 'Notes'] = $assetInfo['notes'];
    }

    if (!$assetInfo) {
        throw new Exception($isTr ? "Zimmetlenecek donanım kaydı bulunamadı." : "Asset to handover not found.");
    }

    // Setup dynamic titles
    $titleTr = 'Donanım Teslim Tutanağı';
    $titleEn = 'Hardware Delivery Report';
    $titleFile = 'Donanim_Teslim_Tutanagi';
    $paramHeader = $isTr ? 'Donanım Özellikleri' : 'Hardware Specifications';
    $nameLabel = $isTr ? 'Donanım Adı / ID' : 'Hardware Name / ID';
    $typeLabel = $isTr ? 'Donanım Modeli' : 'Hardware Model';

    if ($item_type === 'accessory') {
        $titleTr = 'Aksesuar Teslim Tutanağı';
        $titleEn = 'Accessory Delivery Report';
        $titleFile = 'Aksesuar_Teslim_Tutanagi';
        $paramHeader = $isTr ? 'Aksesuar Özellikleri' : 'Accessory Specifications';
        $nameLabel = $isTr ? 'Aksesuar Adı / ID' : 'Accessory Name / ID';
        $typeLabel = $isTr ? 'Aksesuar Modeli' : 'Accessory Model';
    } elseif ($item_type === 'component') {
        $titleTr = 'Bileşen Teslim Tutanağı';
        $titleEn = 'Component Delivery Report';
        $titleFile = 'Bilesen_Teslim_Tutanagi';
        $paramHeader = $isTr ? 'Bileşen Özellikleri' : 'Component Specifications';
        $nameLabel = $isTr ? 'Bileşen Adı / ID' : 'Bileşen Adı / ID';
        $typeLabel = $isTr ? 'Bileşen Modeli' : 'Component Model';
    } elseif ($item_type === 'license') {
        $titleTr = 'Lisans Teslim Tutanağı';
        $titleEn = 'License Delivery Report';
        $titleFile = 'Lisans_Teslim_Tutanagi';
        $paramHeader = $isTr ? 'Lisans Özellikleri' : 'License Specifications';
        $nameLabel = $isTr ? 'Lisans Adı / ID' : 'License Name / ID';
        $typeLabel = $isTr ? 'Lisans Modeli' : 'License Model';
    }
    $titleText = $isTr ? $titleTr : $titleEn;

    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->setFontSubsetting(false);
    $pdf->SetCreator('Antigravity Inventory');
    $pdf->SetTitle('');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 8, 15);
    $pdf->SetAutoPageBreak(TRUE, 8);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 8.0); 

    // Logo and Header
    $logoPath = __DIR__ . '/../../public/logo.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, 8, 20, '', 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
    }
    
    $pdf->SetY(22); 

    // Get Dynamic Agreement Text matching language
    $defaultAgreementTr = 'Aşağıda donanımsal detayları belirtilerek tarafıma teslim edilen envanteri, sağlam ve çalışır durumda teslim aldığımı, kullanımına ve temizliğine özen göstereceğimi, arıza veya hata durumunda Bilgi İşlem Personeli\'ni bilgilendireceğimi ve kendi başıma müdahale etmeyeceğimi, iş amacı dışında kullanmayacağımı ve başkasına kullandırmayacağımı, Bilgi İşlem Personeli\'nin izni olmadan donanımsal veya yazılımsal değişiklik yapmayacağımı, yazılım kurmayacağımı ve şifre belirlemeyeceğimi, her türlü aktivitenin (e-posta kayıtları, anlık mesajlaşma yazılımları, ziyaret edilen web siteleri, kopyalanan ve silinen dosyalar, telefon görüşmeleri, donanım kimlikleri, kullanıcı hesapları vb. gibi) Bilgi İşlem birimi tarafından uygun teknik yöntemlerle kayıt altında tutulduğunu ve ihtiyaç durumunda bu kayıtlara bakılabildiğini, bu teslim tutanağı ile birlikte ekte tarafıma teslim edilen "Donanım Kullanma Talimatı"na uyacağımı beyan ve taahhüt ederim.';
    $defaultAgreementEn = 'I hereby acknowledge that I have received the assets specified below in good working condition, agree to use them carefully and keep them clean. I agree to notify IT staff in case of any failure or error and will not attempt to repair it myself. I agree not to use the assets for non-work purposes or allow others to use them. I will not make any hardware or software changes, install software, or set passwords without IT authorization. I am aware that all activities (email records, instant messaging, web browsing, file operations, hardware IDs, user accounts, etc.) are monitored and recorded by the IT department for security and audit purposes.';
    
    if ($isTr) {
        $agreementText = get_setting_fallback('inv_signature_agreement_tr', get_setting_fallback('inv_signature_agreement', $defaultAgreementTr));
    } else {
        $agreementText = get_setting_fallback('inv_signature_agreement_en', $defaultAgreementEn);
    }
    $agreementTextClean = html_entity_decode($agreementText, ENT_QUOTES, 'UTF-8');
    
    $categoryLabel = $isTr ? 'Kategori' : 'Category';
    if ($item_type === 'asset') {
        $categoryLabel = $isTr ? 'Donanım Kategorisi' : 'Hardware Category';
    } elseif ($item_type === 'accessory') {
        $categoryLabel = $isTr ? 'Aksesuar Kategorisi' : 'Accessory Category';
    } elseif ($item_type === 'component') {
        $categoryLabel = $isTr ? 'Bileşen Kategorisi' : 'Component Category';
    } elseif ($item_type === 'license') {
        $categoryLabel = $isTr ? 'Lisans Kategorisi' : 'License Category';
    }

    $assetName = $assetInfo['name'] ?? '';
    $assetTagOrSerial = $assetInfo['asset_tag'] ?? $assetInfo['serial_no'] ?? '-';
    $displayName = e($assetName);
    if ($assetTagOrSerial !== '-' && $assetTagOrSerial !== $assetName) {
        $displayName .= ' / ' . e($assetTagOrSerial);
    }
    $html = '
    <div style="text-align:center; line-height:1.3;">
        <span style="font-size:11.5pt; font-weight:bold;">' . e(get_setting_fallback('company_name', 'Envanter Sistemi')) . '</span><br>
        <span style="font-size:11pt; font-weight:bold;">' . e($titleText) . '</span>
    </div>
    <p style="text-align:justify; font-size:8.0pt; line-height:1.25; margin-bottom:5px; text-indent:15px;">' . $agreementTextClean . '</p>
    
    <table border="1" cellpadding="3.5" style="border-collapse:collapse; font-size:8.5pt; width:100%;" width="100%">
        <tr bgcolor="#f2f2f2"><th width="35%"><strong>' . e($paramHeader) . '</strong></th><th width="65%"><strong>' . ($isTr ? 'Açıklama' : 'Description') . '</strong></th></tr>
        <tr><td>' . e($nameLabel) . '</td><td>' . $displayName . '</td></tr>
        <tr><td>' . e($typeLabel) . '</td><td>' . (!empty($assetInfo['model_name']) && $assetInfo['model_name'] !== '-' ? e($assetInfo['model_name']) : '-') . '</td></tr>
        <tr><td>' . $categoryLabel . '</td><td>' . (!empty($assetInfo['category_name']) ? e($assetInfo['category_name']) : '-') . '</td></tr>
        <tr><td>' . ($isTr ? 'Seri Numarası' : 'Serial Number') . '</td><td>' . (!empty($assetInfo['serial_no']) && $assetInfo['serial_no'] !== '-' ? e($assetInfo['serial_no']) : '-') . '</td></tr>';

    foreach ($specs as $specName => $specVal) {
        if ($specVal !== null && $specVal !== '') {
            $html .= '<tr><td>' . e($specName) . '</td><td>' . e($specVal) . '</td></tr>';
        }
    }

    // Find the actual signature date from DB
    $handoverDate = date('d.m.Y H:i'); // Fallback
    $stmtHandoverDate = $pdo->prepare("SELECT signed_at, created_at FROM asset_signatures WHERE `{$colName}` = ? AND user_id = ? AND (action_type = 'checkout' OR action_type IS NULL) ORDER BY id DESC LIMIT 1");
    $stmtHandoverDate->execute([$item_id, $target_user_id]);
    $dbHandoverDate = $stmtHandoverDate->fetch();
    if ($dbHandoverDate) {
        $dateToUse = !empty($dbHandoverDate['signed_at']) ? $dbHandoverDate['signed_at'] : $dbHandoverDate['created_at'];
        $handoverDate = date('d.m.Y H:i', strtotime($dateToUse));
    } else {
        // Fallback to asset_timeline table
        $stmtTimelineDate = $pdo->prepare("SELECT created_at FROM asset_timeline WHERE asset_id = ? AND item_type = ? AND event_type = 'checkout' AND context_id = ? AND context_type = 'user' ORDER BY id DESC LIMIT 1");
        $stmtTimelineDate->execute([$item_id, $item_type, $target_user_id]);
        $dbTimelineDate = $stmtTimelineDate->fetchColumn();
        if ($dbTimelineDate) {
            $handoverDate = date('d.m.Y H:i', strtotime($dbTimelineDate));
        }
    }

    $html .= '</table>
    <table border="0" cellpadding="3.5" style="border-collapse:collapse; font-size:8.5pt; width:100%; margin-top:4px;" width="100%">
        <tr>
            <td width="50%" border="LTB" height="42">
                <strong>' . ($isTr ? 'Teslim Alan' : 'Receiver') . '</strong><br>
                ' . ($isTr ? 'Teslim Tarihi' : 'Handover Date') . ': ' . e($handoverDate) . '<br>
                ' . ($isTr ? 'Adı Soyadı' : 'Full Name') . ': ' . e($userInfo['fullname']) . '<br>
                ' . ($isTr ? 'Bölümü' : 'Department') . ': ' . (!empty($userInfo['dept_name']) ? e($userInfo['dept_name']) : '-') . '<br>
                <br>' . ($isTr ? 'İmza' : 'Signature') . ': ' . ((!empty($signature) && strpos($signature, 'data:image/') === 0) ? '<span style="font-size:7.5pt; color:#666;">(' . ($isTr ? 'Dijital İmza' : 'Digital Signature') . ')</span><br><div align="left" style="text-align:left;"><img src="@' . preg_replace('#^data:image/\w+;base64,#i', '', $signature) . '" width="85" height="26" /></div>' : ((!empty($signature)) ? '<br><div align="left" style="text-align:left;"><span style="font-size:8.5pt; color:#333; font-weight:bold;">(' . ($isTr ? 'Islak / Kağıt İmza' : 'Wet / Paper Signature') . ')</span></div>' : '<br><br><br>')) . '
            </td>
            <td width="50%" border="TRB" height="42">
                <strong>' . ($isTr ? 'Teslim Eden' : 'Deliverer') . '</strong><br>
                ' . ($isTr ? 'Adı Soyadı' : 'Full Name') . ': ' . ((!empty($admin_signature) && strpos($admin_signature, 'data:image/') === 0) ? e($adminInfo['fullname']) : '........................................') . '<br>
                ' . ($isTr ? 'Bölümü' : 'Department') . ': ' . ((!empty($admin_signature) && strpos($admin_signature, 'data:image/') === 0 && !empty($adminInfo['dept_name'])) ? e($adminInfo['dept_name']) : '........................................') . '<br>
                <br>' . ($isTr ? 'İmza' : 'Signature') . ': ' . ((!empty($admin_signature) && strpos($admin_signature, 'data:image/') === 0) ? '<span style="font-size:7.5pt; color:#666;">(' . ($isTr ? 'Dijital İmza' : 'Digital Signature') . ')</span><br><div align="left" style="text-align:left;"><img src="@' . preg_replace('#^data:image/\w+;base64,#i', '', $admin_signature) . '" width="85" height="26" /></div>' : ((!empty($admin_signature)) ? '<br><div align="left" style="text-align:left;"><span style="font-size:8.5pt; color:#333; font-weight:bold;">(' . ($isTr ? 'Islak / Kağıt İmza' : 'Wet / Paper Signature') . ')</span></div>' : '<br><br><br>')) . '
            </td>
        </tr>
    </table>';
    
    $html .= '
    <br><br><br><br>
    <table border="1" cellpadding="3.5" style="border-collapse:collapse; font-size:8.5pt; width:100%;" width="100%">
        <tr>
            <td style="background-color:#f0f0f0; text-align:center; font-weight:bold; font-size:8.5pt;">
                ' . ($isTr ? '(Bu bölüm geri teslimde doldurulacaktır)' : '(This section will be filled upon return)') . '
            </td>
        </tr>
    </table>
    <table border="1" cellpadding="3.5" style="border-collapse:collapse; font-size:8.5pt; width:100%; margin-top:4px;" width="100%">
        <tr>
            <td style="font-size:8.5pt; line-height:1.25;">
                ........................................ ' . ($isTr ? 'sebebi ile teslim edilen envanterin aşağıda adı, soyadı ve imzası olan personelden;' : 'due to this reason from the personnel whose name and signature are below;') . '<br>
                [ ] ' . ($isTr ? 'Hasarsız ve Tam Teslim Edilmiştir.' : 'Returned undamaged and complete.') . ' &nbsp;&nbsp;&nbsp;&nbsp; [ ] ' . ($isTr ? 'Hasarlı yada Eksik Teslim Edilmiştir.' : 'Returned undamaged or missing.') . '
            </td>
        </tr>
    </table>
    <table border="0" cellpadding="3.5" style="border-collapse:collapse; font-size:8.5pt; width:100%; margin-top:4px;" width="100%">
        <tr>
            <td width="50%" border="LTB" height="42">
                <strong>' . ($isTr ? 'Teslim Eden' : 'Returned By') . '</strong><br>
                ' . ($isTr ? 'İade Tarihi' : 'Return Date') . ': ..../..../20...<br>
                ' . ($isTr ? 'Adı Soyadı' : 'Full Name') . ': ........................................<br>
                ' . ($isTr ? 'Bölümü' : 'Department') . ': ........................................<br>
                <br>' . ($isTr ? 'İmza' : 'Signature') . ':
            </td>
            <td width="50%" border="TRB" height="42">
                <strong>' . ($isTr ? 'Teslim Alan' : 'Received By') . '</strong><br>
                ' . ($isTr ? 'Adı Soyadı' : 'Full Name') . ': ........................................<br>
                ' . ($isTr ? 'Bölümü' : 'Department') . ': ........................................<br>
                <br>' . ($isTr ? 'İmza' : 'Signature') . ':
            </td>
        </tr>
    </table>';
    
    $html = preg_replace('/^[ \t]+</m', '<', $html);
    $pdf->writeHTML($html, true, false, true, false, '');
    
    $username = $userInfo['username'] ?? '';
    if (!empty($username)) {
        $userPart = strtoupper($username);
    } else {
        $fullName = $userInfo['fullname'] ?? '';
        $fullNameClean = convertTurkishToAscii($fullName);
        $fullNameClean = preg_replace('/[^A-Za-z0-9 ]/', '', $fullNameClean);
        $fullNameClean = preg_replace('/\s+/', '', $fullNameClean);
        $userPart = strtoupper($fullNameClean);
    }
    $dateStr = date('d-m-Y');
    $timeStr = date('H-i');
    $fileName = $userPart . ' - ' . $dateStr . ' - ' . $timeStr . '.pdf';
    
    $uploadDir = __DIR__ . '/../storage/signatures/';
    if (!file_exists($uploadDir)) {
        if (!@mkdir($uploadDir, 0777, true)) {
            throw new Exception($isTr ? "İmza klasörü oluşturulamadı! İzinleri kontrol edin. Klasör: $uploadDir" : "Could not create signature directory! Check permissions. Folder: $uploadDir");
        }
    }
    
    // Verify directory is writable
    if (!is_writable($uploadDir)) {
        throw new Exception($isTr ? "İmza klasörüne yazma izni yok! Klasör: $uploadDir" : "No write permission for signature directory! Folder: $uploadDir");
    }
    
    $fullPath = $uploadDir . $fileName;
    $uploadPath = 'app/storage/signatures/' . $fileName;
    
    try {
        $pdf->Output($fullPath, 'F');
        
        if (!file_exists($fullPath)) {
            throw new Exception($isTr ? "PDF dosyası diske yazılamadı. Klasör izinlerini kontrol edin." : "PDF file could not be written to disk. Check folder permissions.");
        }
        
        if (filesize($fullPath) === 0) {
            throw new Exception($isTr ? "PDF dosyası boş oluşturuldu. Lütfen tekrar deneyin." : "PDF file was created empty. Please try again.");
        }
        
        $stmtAttach = $pdo->prepare("INSERT INTO attachments (entity_type, entity_id, file_name, file_path, file_type, file_size, uploaded_by, created_at, document_type) VALUES (?, ?, ?, ?, 'application/pdf', ?, ?, NOW(), 'handover')");
        $stmtAttach->execute([$item_type, $item_id, $fileName, $uploadPath, filesize($fullPath), $current_user_id]);
        return (int)$pdo->lastInsertId();
    } catch (Exception $pdfEx) {
        throw new Exception(($isTr ? "PDF Oluşturma Hatası: " : "PDF Generation Error: ") . $pdfEx->getMessage());
    }
}

/**
 * Geri Teslim (İade) PDF'i üretir.
 */
function generateReturnPDF(PDO $pdo, int $item_id, string $item_type, int $source_user_id, string $return_reason, string $return_status, ?string $personnel_signature, ?string $admin_signature, bool $isTr, int $current_user_id, string $proxy_name = '', int $checkout_id = 0): int
{
    $isPaperReturn = (empty($personnel_signature) || strpos($personnel_signature, 'data:image/') !== 0);
    // PDF için kullanıcı bilgilerini çek
    $stmtUser = $pdo->prepare("SELECT u.fullname, u.username, b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.id = ?");
    $stmtUser->execute([$source_user_id]);
    $userInfo = $stmtUser->fetch();
    if (!$userInfo) {
        throw new Exception($isTr ? "Kaynak kullanıcı bulunamadı." : "Source user not found.");
    }

    require_once __DIR__ . '/../../libs/tcpdf/tcpdf.php';
    
    $adminInfo = null;
    $initialDate = '';
    $initialSignature = null;
    $initialAdminSignature = null;
    
    // Attempt to get the latest return signature record to check for damage note
    $damage_note = '';
    $colNameForReturn = match($item_type) {
        'accessory' => 'accessory_id',
        'component' => 'component_id',
        'license' => 'license_id',
        default => 'asset_id'
    };
    $stmtSigReturn = $pdo->prepare("SELECT notes FROM asset_signatures WHERE `{$colNameForReturn}` = ? AND user_id = ? AND action_type = 'checkin' ORDER BY id DESC LIMIT 1");
    $stmtSigReturn->execute([$item_id, $source_user_id]);
    $sigReturn = $stmtSigReturn->fetch();
    if ($sigReturn && !empty($sigReturn['notes'])) {
        $notesArr = json_decode($sigReturn['notes'], true);
        if (is_array($notesArr) && !empty($notesArr['damage_note'])) {
            $damage_note = $notesArr['damage_note'];
        }
    }
    
    // Fetch original checkout data from asset_timeline (universal for all types)
    $stmtTimeline = $pdo->prepare("SELECT user_id, created_at FROM asset_timeline WHERE asset_id = ? AND item_type = ? AND event_type = 'checkout' AND context_id = ? AND context_type = 'user' ORDER BY id DESC LIMIT 1");
    $stmtTimeline->execute([$item_id, $item_type, $source_user_id]);
    $timeline = $stmtTimeline->fetch();
    if ($timeline) {
        $stmtAdmin = $pdo->prepare("SELECT u.fullname, b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.id = ?");
        $stmtAdmin->execute([$timeline['user_id']]);
        $adminInfo = $stmtAdmin->fetch();
        $initialDate = date('d.m.Y H:i', strtotime($timeline['created_at']));
    }
    
    // Attempt to get the signature from asset_signatures
    $col = '';
    if ($item_type === 'asset') { $col = 'asset_id'; }
    elseif ($item_type === 'accessory') { $col = 'accessory_id'; }
    elseif ($item_type === 'component') { $col = 'component_id'; }
    elseif ($item_type === 'license') { $col = 'license_id'; }
    
    $sig = null;
    if ($checkout_id > 0) {
        $stmtSig = $pdo->prepare("SELECT signature_image, admin_signature_image, signed_at, admin_name, admin_id FROM asset_signatures WHERE id = ?");
        $stmtSig->execute([$checkout_id]);
        $sig = $stmtSig->fetch();
    }
    
    if (!$sig && $col) {
        $stmtSig = $pdo->prepare("SELECT signature_image, admin_signature_image, signed_at, admin_name, admin_id FROM asset_signatures WHERE $col = ? AND user_id = ? AND status = 'approved' AND (action_type = 'checkout' OR action_type IS NULL) ORDER BY id DESC LIMIT 1");
        $stmtSig->execute([$item_id, $source_user_id]);
        $sig = $stmtSig->fetch();
    }
    
    if ($sig) {
        $initialSignature = $sig['signature_image'];
        $initialAdminSignature = $sig['admin_signature_image'] ?? null;
        if (!empty($sig['signed_at'])) {
            $initialDate = date('d.m.Y H:i', strtotime($sig['signed_at']));
        }
        if (!empty($sig['admin_name'])) {
            $adminInfo = ['fullname' => $sig['admin_name'], 'dept_name' => ''];
        } elseif (!empty($sig['admin_id'])) {
            $stmtAdmin2 = $pdo->prepare("SELECT u.fullname, b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.id = ?");
            $stmtAdmin2->execute([$sig['admin_id']]);
            $adminDbInfo = $stmtAdmin2->fetch();
            if ($adminDbInfo) {
                $adminInfo = $adminDbInfo;
            }
        }
    }
    
    if (!$adminInfo) {
        $adminInfo = ['fullname' => 'Sistem Yöneticisi', 'dept_name' => ''];
    }
    
    $specs = [];
    if ($item_type === 'asset') {
        $stmtAsset = $pdo->prepare("SELECT a.*, m.name as model_name, c.name as category_name, co.name as company_name, b.bolum_adi as dept_name 
                                 FROM assets a 
                                 LEFT JOIN asset_models m ON a.model_id = m.id 
                                 LEFT JOIN asset_categories c ON a.category_id = c.id
                                 LEFT JOIN asset_companies co ON a.company_id = co.id
                                 LEFT JOIN bolumler b ON a.department_id = b.id
                                 WHERE a.id = ?");
        $stmtAsset->execute([$item_id]);
        $assetInfo = $stmtAsset->fetch();
        
        $stmtSpecs = $pdo->prepare("SELECT cf.field_label, fv.value FROM inventory_asset_field_values fv JOIN inventory_custom_fields cf ON fv.field_id = cf.id WHERE fv.asset_id = ?");
        $stmtSpecs->execute([$item_id]);
        while($s = $stmtSpecs->fetch(PDO::FETCH_ASSOC)) {
            $specs[$s['field_label']] = $s['value'];
        }
        
        $rawSpecs = [];
        if (!empty($assetInfo['specs'])) {
            $rawSpecs = json_decode($assetInfo['specs'], true);
        }
        if (!is_array($rawSpecs)) {
            $rawSpecs = [];
        }

        $getVal = function($key, $col) use ($rawSpecs, $assetInfo) {
            if (!empty($rawSpecs[$key])) return $rawSpecs[$key];
            if (!empty($assetInfo[$col])) return $assetInfo[$col];
            return null;
        };

        $specs = [];

        // 1. IP and MAC Addresses (Ethernet vs Wi-Fi)
        $ethIp = $getVal('ethernet_ip', 'ip_address');
        $ethMac = $getVal('ethernet_mac', 'mac_address');
        $wifiIp = $getVal('wifi_ip', null);
        $wifiMac = $getVal('wifi_mac', null);

        if (empty($ethMac) && !empty($assetInfo['mac_address'])) {
            $ethMac = $assetInfo['mac_address'];
        }
        if (empty($ethIp) && !empty($assetInfo['ip_address'])) {
            $ethIp = $assetInfo['ip_address'];
        }

        if (!empty($ethIp)) {
            $specs[$isTr ? 'IP Adresi (Ethernet)' : 'IP Address (Ethernet)'] = $ethIp;
        }
        if (!empty($ethMac)) {
            $specs[$isTr ? 'MAC Adresi (Ethernet)' : 'MAC Address (Ethernet)'] = $ethMac;
        }
        if (!empty($wifiIp)) {
            $specs[$isTr ? 'IP Adresi (Wi-Fi)' : 'IP Address (Wi-Fi)'] = $wifiIp;
        }
        if (!empty($wifiMac)) {
            $specs[$isTr ? 'MAC Adresi (Wi-Fi)' : 'MAC Address (Wi-Fi)'] = $wifiMac;
        }

        if (empty($specs)) {
            $generalIp = $getVal('ip_address', 'ip_address');
            $generalMac = $getVal('mac_address', 'mac_address');
            if (!empty($generalIp)) {
                $specs[$isTr ? 'IP Adresi' : 'IP Address'] = $generalIp;
            }
            if (!empty($generalMac)) {
                $specs[$isTr ? 'MAC Adresi' : 'MAC Address'] = $generalMac;
            }
        }

        // 2. Operating System
        $osVal = $getVal('os', 'os');
        if (!empty($osVal)) {
            $specs[$isTr ? 'İşletim Sistemi' : 'Operating System'] = $osVal;
        }

        // 3. CPU
        $cpuVal = $getVal('cpu', 'cpu');
        if (!empty($cpuVal)) {
            $specs[$isTr ? 'İşlemci (CPU)' : 'Processor (CPU)'] = cleanCpuName($cpuVal);
        }

        // 4. RAM
        $ramVal = $getVal('ram_gb', 'ram');
        if (!empty($ramVal)) {
            if (is_numeric($ramVal)) {
                $ramVal = $ramVal . ' GB';
            } elseif (stripos($ramVal, 'GB') === false) {
                $ramVal = $ramVal . ' GB';
            }
            $specs[$isTr ? 'Bellek (RAM)' : 'Memory (RAM)'] = $ramVal;
        }

        // 5. GPU
        $gpuVal = $getVal('gpu', 'gpu');
        if (!empty($gpuVal)) {
            $specs[$isTr ? 'Ekran Kartı (GPU)' : 'Graphics Card (GPU)'] = $gpuVal;
        }

        // 6. Disk
        $diskVal = $getVal('disk', 'disk');
        if (empty($diskVal)) {
            $diskVal = $getVal('disk_c_total_gb', null);
        }
        if (!empty($diskVal)) {
            if (is_numeric($diskVal)) {
                $diskVal = $diskVal . ' GB';
            } elseif (stripos($diskVal, 'GB') === false) {
                $diskVal = $diskVal . ' GB';
            }
            $specs[$isTr ? 'Disk' : 'Disk'] = $diskVal;
        }

        // 7. Monitor
        $monitorVal = $getVal('monitor', 'monitor');
        if (!empty($monitorVal)) {
            $specs[$isTr ? 'Monitör' : 'Monitor'] = $monitorVal;
        }
    } elseif ($item_type === 'accessory') {
        $stmtAccessory = $pdo->prepare("SELECT a.*, a.model_no as model_name, c.name as category_name, co.name as company_name, b.bolum_adi as dept_name, mfg.name as manufacturer_name 
                                 FROM asset_accessories a 
                                 LEFT JOIN asset_categories c ON a.category_id = c.id
                                 LEFT JOIN asset_companies co ON a.company_id = co.id
                                 LEFT JOIN bolumler b ON a.department_id = b.id
                                 LEFT JOIN asset_manufacturers mfg ON a.manufacturer_id = mfg.id
                                 WHERE a.id = ?");
        $stmtAccessory->execute([$item_id]);
        $assetInfo = $stmtAccessory->fetch();
        if (!empty($assetInfo['model_no'])) $specs[$isTr ? 'Model No' : 'Model No'] = $assetInfo['model_no'];
        if (!empty($assetInfo['manufacturer_name'])) $specs[$isTr ? 'Üretici' : 'Manufacturer'] = $assetInfo['manufacturer_name'];
        if (!empty($assetInfo['purchase_cost'])) $specs[$isTr ? 'Satın Alma Bedeli' : 'Purchase Cost'] = $assetInfo['purchase_cost'] . ' ' . ($assetInfo['purchase_currency'] ?? 'TRY');
        if (!empty($assetInfo['notes'])) $specs[$isTr ? 'Notlar' : 'Notes'] = $assetInfo['notes'];
    } elseif ($item_type === 'component') {
        $stmtComponent = $pdo->prepare("SELECT a.*, '-' as model_name, c.name as category_name, co.name as company_name, b.bolum_adi as dept_name, mfg.name as manufacturer_name 
                                 FROM asset_components a 
                                 LEFT JOIN asset_categories c ON a.category_id = c.id
                                 LEFT JOIN asset_companies co ON a.company_id = co.id
                                 LEFT JOIN bolumler b ON a.department_id = b.id
                                 LEFT JOIN asset_manufacturers mfg ON a.manufacturer_id = mfg.id
                                 WHERE a.id = ?");
        $stmtComponent->execute([$item_id]);
        $assetInfo = $stmtComponent->fetch();
        if (!empty($assetInfo['manufacturer_name'])) $specs[$isTr ? 'Üretici' : 'Manufacturer'] = $assetInfo['manufacturer_name'];
        if (!empty($assetInfo['purchase_cost'])) $specs[$isTr ? 'Satın Alma Bedeli' : 'Purchase Cost'] = $assetInfo['purchase_cost'] . ' ' . ($assetInfo['purchase_currency'] ?? 'TRY');
        if (!empty($assetInfo['notes'])) $specs[$isTr ? 'Notlar' : 'Notes'] = $assetInfo['notes'];
    } elseif ($item_type === 'license') {
        $stmtLicense = $pdo->prepare("SELECT a.*, a.software_name as name, '-' as model_name, c.name as category_name, co.name as company_name, b.bolum_adi as dept_name, mfg.name as manufacturer_name 
                                 FROM asset_licenses a 
                                 LEFT JOIN asset_categories c ON a.category_id = c.id
                                 LEFT JOIN asset_companies co ON a.company_id = co.id
                                 LEFT JOIN bolumler b ON a.department_id = b.id
                                 LEFT JOIN asset_manufacturers mfg ON a.manufacturer_id = mfg.id
                                 WHERE a.id = ?");
        $stmtLicense->execute([$item_id]);
        $assetInfo = $stmtLicense->fetch();
        if (!empty($assetInfo['license_key'])) $specs[$isTr ? 'Lisans Anahtarı' : 'License Key'] = $assetInfo['license_key'];
        if (!empty($assetInfo['license_email'])) $specs[$isTr ? 'Lisans Maili' : 'License Email'] = $assetInfo['license_email'];
        if (!empty($assetInfo['license_name'])) $specs[$isTr ? 'Lisans Sahibi' : 'License Owner'] = $assetInfo['license_name'];
        if (!empty($assetInfo['expire_date'])) $specs[$isTr ? 'Bitiş Tarihi' : 'Expiration Date'] = $assetInfo['expire_date'];
        if (!empty($assetInfo['purchase_cost'])) $specs[$isTr ? 'Satın Alma Bedeli' : 'Purchase Cost'] = $assetInfo['purchase_cost'] . ' ' . ($assetInfo['purchase_currency'] ?? 'TRY');
        if (!empty($assetInfo['notes'])) $specs[$isTr ? 'Notlar' : 'Notes'] = $assetInfo['notes'];
    }

    if (!$assetInfo) {
        throw new Exception($isTr ? "Zimmetli donanım kaydı bulunamadı." : "Asset not found.");
    }

    $titleTr = 'Donanım İade Tutanağı';
    $titleEn = 'Hardware Return Report';
    $titleFile = 'Donanim_Iade_Tutanagi';
    $paramHeader = $isTr ? 'Donanım Özellikleri' : 'Hardware Specifications';
    $nameLabel = $isTr ? 'Donanım Adı / ID' : 'Hardware Name / ID';
    $typeLabel = $isTr ? 'Donanım Modeli' : 'Hardware Model';

    if ($item_type === 'accessory') {
        $titleTr = 'Aksesuar İade Tutanağı';
        $titleEn = 'Accessory Return Report';
        $titleFile = 'Aksesuar_Iade_Tutanagi';
        $paramHeader = $isTr ? 'Aksesuar Özellikleri' : 'Accessory Specifications';
        $nameLabel = $isTr ? 'Aksesuar Adı / ID' : 'Accessory Name / ID';
        $typeLabel = $isTr ? 'Aksesuar Modeli' : 'Accessory Model';
    } elseif ($item_type === 'component') {
        $titleTr = 'Bileşen İade Tutanağı';
        $titleEn = 'Component Return Report';
        $titleFile = 'Bilesen_Iade_Tutanagi';
        $paramHeader = $isTr ? 'Bileşen Özellikleri' : 'Component Specifications';
        $nameLabel = $isTr ? 'Bileşen Adı / ID' : 'Bileşen Adı / ID';
        $typeLabel = $isTr ? 'Bileşen Modeli' : 'Component Model';
    } elseif ($item_type === 'license') {
        $titleTr = 'Lisans İade Tutanağı';
        $titleEn = 'License Return Report';
        $titleFile = 'Lisans_Iade_Tutanagi';
        $paramHeader = $isTr ? 'Lisans Özellikleri' : 'License Specifications';
        $nameLabel = $isTr ? 'Lisans Adı / ID' : 'License Name / ID';
        $typeLabel = $isTr ? 'Lisans Modeli' : 'License Model';
    }
    $titleText = $isTr ? $titleTr : $titleEn;

    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('Antigravity Inventory');
    $pdf->SetTitle('');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 8, 15);
    $pdf->SetAutoPageBreak(TRUE, 8);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 8.0); 

    $logoPath = __DIR__ . '/../../public/logo.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, 8, 20, '', 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
    }
    
    $pdf->SetY(22); 

    $defaultAgreementTr = 'Aşağıda donanımsal detayları belirtilerek tarafıma teslim edilen envanteri, sağlam ve çalışır durumda teslim aldığımı, kullanımına ve temizliğine özen göstereceğimi, arıza veya hata durumunda Bilgi İşlem Personeli\'ni bilgilendireceğimi ve kendi başıma müdahale etmeyeceğimi, iş amacı dışında kullanmayacağımı ve başkasına kullandırmayacağımı, Bilgi İşlem Personeli\'nin izni olmadan donanımsal veya yazılımsal değişiklik yapmayacağımı, yazılım kurmayacağımı ve şifre belirlemeyeceğimi, her türlü aktivitenin (e-posta kayıtları, anlık mesajlaşma yazılımları, ziyaret edilen web siteleri, kopyalanan ve silinen dosyalar, telefon görüşmeleri, donanım kimlikleri, kullanıcı hesapları vb. gibi) Bilgi İşlem birimi tarafından uygun teknik yöntemlerle kayıt altında tutulduğunu ve ihtiyaç durumunda bu kayıtlara bakılabildiğini, bu teslim tutanağı ile birlikte ekte tarafıma teslim edilen "Donanım Kullanma Talimatı"na uyacağımı beyan ve taahhüt ederim.';
    $defaultAgreementEn = 'I hereby acknowledge that I have received the assets specified below in good working condition, agree to use them carefully and keep them clean. I agree to notify IT staff in case of any failure or error and will not attempt to repair it myself. I agree not to use the assets for non-work purposes or allow others to use them. I will not make any hardware or software changes, install software, or set passwords without IT authorization. I am aware that all activities (email records, instant messaging, web browsing, file operations, hardware IDs, user accounts, etc.) are monitored and recorded by the IT department for security and audit purposes.';
    
    if ($isTr) {
        $agreementText = get_setting_fallback('inv_signature_agreement_tr', get_setting_fallback('inv_signature_agreement', $defaultAgreementTr));
    } else {
        $agreementText = get_setting_fallback('inv_signature_agreement_en', $defaultAgreementEn);
    }
    $agreementTextClean = html_entity_decode($agreementText, ENT_QUOTES, 'UTF-8');
    
    $categoryLabel = $isTr ? 'Kategori' : 'Category';
    if ($item_type === 'asset') {
        $categoryLabel = $isTr ? 'Donanım Kategorisi' : 'Hardware Category';
    } elseif ($item_type === 'accessory') {
        $categoryLabel = $isTr ? 'Aksesuar Kategorisi' : 'Accessory Category';
    } elseif ($item_type === 'component') {
        $categoryLabel = $isTr ? 'Bileşen Kategorisi' : 'Component Category';
    } elseif ($item_type === 'license') {
        $categoryLabel = $isTr ? 'Lisans Kategorisi' : 'License Category';
    }

    $assetName = $assetInfo['name'] ?? '';
    $assetTagOrSerial = $assetInfo['asset_tag'] ?? $assetInfo['serial_no'] ?? '-';
    $displayName = e($assetName);
    if ($assetTagOrSerial !== '-' && $assetTagOrSerial !== $assetName) {
        $displayName .= ' / ' . e($assetTagOrSerial);
    }
    
    $html = '
    <div style="text-align:center; line-height:1.3;">
        <span style="font-size:11.5pt; font-weight:bold;">' . e(get_setting_fallback('company_name', 'Envanter Sistemi')) . '</span><br>
        <span style="font-size:11pt; font-weight:bold;">' . e($titleText) . '</span>
    </div>
    <p style="text-align:justify; font-size:8.0pt; line-height:1.25; margin-bottom:5px; text-indent:15px;">' . $agreementTextClean . '</p>
    <table border="1" cellpadding="3.5" style="border-collapse:collapse; font-size:8.5pt; width:100%;" width="100%">
        <tr bgcolor="#f2f2f2"><th width="35%"><strong>' . e($paramHeader) . '</strong></th><th width="65%"><strong>' . ($isTr ? 'Açıklama' : 'Description') . '</strong></th></tr>
        <tr><td>' . e($nameLabel) . '</td><td>' . $displayName . '</td></tr>
        <tr><td>' . e($typeLabel) . '</td><td>' . (!empty($assetInfo['model_name']) && $assetInfo['model_name'] !== '-' ? e($assetInfo['model_name']) : '-') . '</td></tr>
        <tr><td>' . $categoryLabel . '</td><td>' . (!empty($assetInfo['category_name']) ? e($assetInfo['category_name']) : '-') . '</td></tr>
        <tr><td>' . ($isTr ? 'Seri Numarası' : 'Serial Number') . '</td><td>' . (!empty($assetInfo['serial_no']) && $assetInfo['serial_no'] !== '-' ? e($assetInfo['serial_no']) : '-') . '</td></tr>';
    if (!empty($specs)) {
        foreach ($specs as $label => $val) {
            if (!empty($val) && $val !== '-') {
                $html .= '<tr><td>' . e($label) . '</td><td>' . e($val) . '</td></tr>';
            }
        }
    }
    $html .= '</table>
    <table border="0" cellpadding="3.5" style="border-collapse:collapse; font-size:8.5pt; width:100%; margin-top:4px;" width="100%">
        <tr>
            <td width="50%" border="LTB" height="42">
                <strong>' . ($isTr ? 'Teslim Alan' : 'Receiver') . '</strong><br>
                ' . ($isTr ? 'Teslim Tarihi' : 'Handover Date') . ': ' . (!empty($initialDate) ? e($initialDate) : date('d.m.Y H:i')) . '<br>
                ' . ($isTr ? 'Adı Soyadı' : 'Full Name') . ': ' . e($userInfo['fullname']) . '<br>
                ' . ($isTr ? 'Bölümü' : 'Department') . ': ' . (!empty($userInfo['dept_name']) ? e($userInfo['dept_name']) : '-') . '<br>
                <br>' . ($isTr ? 'İmza' : 'Signature') . ': ' . ($isPaperReturn ? '<br><br><br>' : ((!empty($initialSignature) && strpos($initialSignature, 'data:image/') === 0) ? '<span style="font-size:7.5pt; color:#666;">(' . ($isTr ? 'Dijital İmza' : 'Digital Signature') . ')</span><br><div align="left" style="text-align:left;"><img src="@' . preg_replace('#^data:image/\w+;base64,#i', '', $initialSignature) . '" width="85" height="26" /></div>' : ((!empty($initialSignature)) ? '<br><div align="left" style="text-align:left;"><span style="font-size:8.5pt; color:#333; font-weight:bold;">(' . ($isTr ? 'Islak İmza' : 'Wet Signature') . ')</span></div>' : ''))) . '
            </td>
            <td width="50%" border="TRB" height="42">
                <strong>' . ($isTr ? 'Teslim Eden' : 'Deliverer') . '</strong><br>
                ' . ($isTr ? 'Adı Soyadı' : 'Full Name') . ': ' . ((!empty($initialAdminSignature) && strpos($initialAdminSignature, 'data:image/') === 0) ? e($adminInfo['fullname']) : '........................................') . '<br>
                ' . ($isTr ? 'Bölümü' : 'Department') . ': ' . ((!empty($initialAdminSignature) && strpos($initialAdminSignature, 'data:image/') === 0 && !empty($adminInfo['dept_name'])) ? e($adminInfo['dept_name']) : '........................................') . '<br>
                <br>' . ($isTr ? 'İmza' : 'Signature') . ': ' . ($isPaperReturn ? '<br><br><br>' : ((!empty($initialAdminSignature) && strpos($initialAdminSignature, 'data:image/') === 0) ? '<span style="font-size:7.5pt; color:#666;">(' . ($isTr ? 'Dijital İmza' : 'Digital Signature') . ')</span><br><div align="left" style="text-align:left;"><img src="@' . preg_replace('#^data:image/\w+;base64,#i', '', $initialAdminSignature) . '" width="85" height="26" /></div>' : ((!empty($initialAdminSignature)) ? '<br><div align="left" style="text-align:left;"><span style="font-size:8.5pt; color:#333; font-weight:bold;">(' . ($isTr ? 'Islak İmza' : 'Wet Signature') . ')</span></div>' : ''))) . '
            </td>
        </tr>
    </table>';
    // Current admin who receives the return
    $currentAdminInfo = $pdo->query("SELECT u.fullname, b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.id = $current_user_id")->fetch();
    $currentAdminName = $currentAdminInfo ? $currentAdminInfo['fullname'] : 'Sistem Yöneticisi';
    $currentAdminDept = $currentAdminInfo ? $currentAdminInfo['dept_name'] : '';
    
    // Check signature record for admin_name override
    $stmtSig = $pdo->prepare("SELECT admin_name FROM asset_signatures WHERE `{$col}` = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
    $stmtSig->execute([$item_id]);
    $adminNameOverride = $stmtSig->fetchColumn();
    if (!empty($adminNameOverride)) {
        $currentAdminName = $adminNameOverride;
        
        $stmtOverrideDept = $pdo->prepare("SELECT b.bolum_adi as dept_name FROM users u LEFT JOIN bolumler b ON u.bolum = b.id WHERE u.fullname = ? LIMIT 1");
        $stmtOverrideDept->execute([$adminNameOverride]);
        $overrideDept = $stmtOverrideDept->fetchColumn();
        if ($overrideDept) {
            $currentAdminDept = $overrideDept;
        } else {
            $currentAdminDept = '';
        }
    }
    
    // Spacer before Return section
    $html .= '<br><br><br><br>';
    
    // Return section header
    $html .= '
    <table border="1" cellpadding="3.5" style="border-collapse:collapse; font-size:8.5pt; width:100%;" width="100%">
        <tr>
            <td style="background-color:#f0f0f0; text-align:center; font-weight:bold; font-size:8.5pt;">
                ' . ($isTr ? '(Bu bölüm geri teslimde doldurulacaktır)' : '(This section will be filled upon return)') . '
            </td>
        </tr>
    </table>';

    // Return details table
    $html .= '
    <table border="1" cellpadding="3.5" style="border-collapse:collapse; font-size:8.5pt; width:100%; margin-top:4px;" width="100%">
        <tr>
            <td style="font-size:8.5pt; line-height:1.25;">
                <span style="text-decoration:underline;">' . e($return_reason) . '</span> ' . ($isTr ? 'sebebi ile teslim edilen envanterin aşağıda adı, soyadı ve imzası olan personelden;' : 'due to this reason from the personnel whose name and signature are below;') . '<br>
                [' . ($return_status == 'hasarsiz' ? 'X' : ' ') . '] ' . ($isTr ? 'Hasarsız ve Tam Teslim Edilmiştir.' : 'Returned undamaged and complete.') . ' &nbsp;&nbsp;&nbsp;&nbsp; [' . ($return_status == 'hasarli' || $return_status == 'hasarli_kullanilabilir' ? 'X' : ' ') . '] ' . ($isTr ? 'Hasarlı yada Eksik Teslim Edilmiştir.' : 'Returned undamaged or missing.') . '
                ' . (($return_status == 'hasarli' || $return_status == 'hasarli_kullanilabilir') && !empty($damage_note) ? '<br><span style="color:#d9534f; font-weight:bold;">' . ($isTr ? 'Hasar / Eksik Açıklaması: ' : 'Damage / Missing Details: ') . '</span>' . e($damage_note) : '') . '
            </td>
        </tr>
    </table>';

    // Return signatures table (equal width 50% / 50%, no middle line, border="0" and cell border="LTB"/"TRB")
    $html .= '
    <table border="0" cellpadding="3.5" style="border-collapse:collapse; font-size:8.5pt; width:100%; margin-top:4px;" width="100%">
        <tr>
            <td width="50%" border="LTB" height="42">
                <strong>' . ($isTr ? 'Teslim Eden' : 'Returned By') . '</strong><br>
                ' . ($isTr ? 'İade Tarihi' : 'Return Date') . ': ' . date('d.m.Y H:i') . '<br>
                ' . ($isTr ? 'Adı Soyadı' : 'Full Name') . ': ' . (!empty($proxy_name) ? e($proxy_name) . ' <span style="font-size:8.5pt; font-weight:normal; color:#d97706;">(' . ($isTr ? 'Vekaleten' : 'By Proxy') . ')</span>' : e($userInfo['fullname'])) . '<br>
                ' . ($isTr ? 'Bölümü' : 'Department') . ': ' . (!empty($userInfo['dept_name']) ? e($userInfo['dept_name']) : '-') . '<br>
                <br>' . ($isTr ? 'İmza' : 'Signature') . ': ' . ($isPaperReturn ? '<br><br><br>' : ((!empty($personnel_signature) && strpos($personnel_signature, 'data:image/') === 0) ? '<span style="font-size:7.5pt; color:#666;">(' . ($isTr ? 'Dijital İmza' : 'Digital Signature') . ')</span><br><div align="left" style="text-align:left;"><img src="@' . preg_replace('#^data:image/\w+;base64,#i', '', $personnel_signature) . '" width="85" height="26" /></div>' : ((!empty($personnel_signature)) ? '<br><div align="left" style="text-align:left;"><span style="font-size:8.5pt; color:#333; font-weight:bold;">(' . ($isTr ? 'Islak / Kağıt İmza' : 'Wet / Paper Signature') . ')</span></div>' : '<br><br><br>'))) . '
            </td>
            <td width="50%" border="TRB" height="42">
                <strong>' . ($isTr ? 'Teslim Alan' : 'Received By') . '</strong><br>
                ' . ($isTr ? 'Adı Soyadı' : 'Full Name') . ': ' . ((!empty($admin_signature) && strpos($admin_signature, 'data:image/') === 0) ? e($currentAdminName) : '........................................') . '<br>
                ' . ($isTr ? 'Bölümü' : 'Department') . ': ' . ((!empty($admin_signature) && strpos($admin_signature, 'data:image/') === 0 && !empty($currentAdminDept)) ? e($currentAdminDept) : '........................................') . '<br>
                <br>' . ($isTr ? 'İmza' : 'Signature') . ': ' . ($isPaperReturn ? '<br><br><br>' : ((!empty($admin_signature) && strpos($admin_signature, 'data:image/') === 0) ? '<span style="font-size:7.5pt; color:#666;">(' . ($isTr ? 'Dijital İmza' : 'Digital Signature') . ')</span><br><div align="left" style="text-align:left;"><img src="@' . preg_replace('#^data:image/\w+;base64,#i', '', $admin_signature) . '" width="85" height="26" /></div>' : ((!empty($admin_signature)) ? '<br><div align="left" style="text-align:left;"><span style="font-size:8.5pt; color:#333; font-weight:bold;">(' . ($isTr ? 'Islak / Kağıt İmza' : 'Wet / Paper Signature') . ')</span></div>' : '<br><br><br>'))) . '
            </td>
        </tr>
    </table>';
    
    $html = preg_replace('/^[ \t]+</m', '<', $html);
    $pdf->writeHTML($html, true, false, true, false, '');
    
    $username = $userInfo['username'] ?? '';
    if (!empty($username)) {
        $userPart = strtoupper($username);
    } else {
        $fullName = $userInfo['fullname'] ?? '';
        $fullNameClean = convertTurkishToAscii($fullName);
        $fullNameClean = preg_replace('/[^A-Za-z0-9 ]/', '', $fullNameClean);
        $fullNameClean = preg_replace('/\s+/', '', $fullNameClean);
        $userPart = strtoupper($fullNameClean);
    }
    $dateStr = date('d-m-Y');
    $timeStr = date('H-i');
    $fileName = $userPart . ' - ' . $dateStr . ' - ' . $timeStr . '.pdf';
    
    $uploadDir = __DIR__ . '/../storage/signatures/';
    if (!file_exists($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    
    $fullPath = $uploadDir . $fileName;
    $uploadPath = 'app/storage/signatures/' . $fileName;
    
    try {
        $pdf->Output($fullPath, 'F');
        if (filesize($fullPath) > 0) {
            $stmtAttach = $pdo->prepare("INSERT INTO attachments (entity_type, entity_id, file_name, file_path, file_type, file_size, uploaded_by, created_at, document_type) VALUES (?, ?, ?, ?, 'application/pdf', ?, ?, NOW(), 'return')");
            $stmtAttach->execute([$item_type, $item_id, $fileName, $uploadPath, filesize($fullPath), $current_user_id]);
            return (int)$pdo->lastInsertId();
        }
        return 0;
    } catch (Exception $pdfEx) {
        throw new Exception("Return PDF Generation Error: " . $pdfEx->getMessage());
    }
}


function finalizeAssetCheckin($pdo, $item_id, $view_submit, $current_user_id, $isTr, $checkout_id = 0, $fromAssetId = 0, $returnReason = '', $returnStatus = 'hasarsiz', $damageNote = '') {
    $noteSuffix = "";
    $statusText = ($returnStatus === 'hasarli') ? ($isTr ? "Hasarlı" : "Damaged") : (($returnStatus === 'hasarli_kullanilabilir') ? ($isTr ? "Hasarlı (Kullanılabilir)" : "Damaged (Usable)") : ($isTr ? "Hasarsız" : "Undamaged"));
    $reasonText = !empty($returnReason) ? $returnReason : ($isTr ? "Geri Alma" : "Check In");
    $noteSuffix = " (" . ($isTr ? "Sebep: " : "Reason: ") . $reasonText . " - " . $statusText . (!empty($damageNote) ? " - " . ($isTr ? "Hasar Notu: " : "Damage Note: ") . $damageNote : "") . ")";
        if ($view_submit == 'assets') {
            $astData = $pdo->query("SELECT a.assigned_user_id, a.asset_id, u.fullname, pa.name as parent_name FROM assets a LEFT JOIN users u ON a.assigned_user_id = u.id LEFT JOIN assets pa ON a.asset_id = pa.id WHERE a.id = $item_id")->fetch();
            $assigned_user_id = $astData['assigned_user_id'] ?? 0;
            $assigned_asset_id = $astData['asset_id'] ?? 0;
            $source_name = $astData['fullname'] ?: ($astData['parent_name'] ?: 'Unknown');

            // Find Ready (Hazır) and Faulty (Arızalı) status IDs dynamically from DB
            $ready_status_id = 3; // Fallback
            $stmtReady = $pdo->query("SELECT id FROM asset_status_labels WHERE type = 'deployable' AND is_default = 1 AND deleted_at IS NULL LIMIT 1");
            $ready_db = $stmtReady->fetchColumn();
            if ($ready_db) {
                $ready_status_id = (int)$ready_db;
            } else {
                $stmtReady2 = $pdo->prepare("SELECT id FROM asset_status_labels WHERE type = 'deployable' AND name LIKE ? AND deleted_at IS NULL LIMIT 1");
                $stmtReady2->execute(['%Hazır%']);
                $ready_db2 = $stmtReady2->fetchColumn();
                if ($ready_db2) {
                    $ready_status_id = (int)$ready_db2;
                } else {
                    $stmtReady3 = $pdo->query("SELECT id FROM asset_status_labels WHERE type = 'deployable' AND deleted_at IS NULL LIMIT 1");
                    $ready_db3 = $stmtReady3->fetchColumn();
                    if ($ready_db3) {
                        $ready_status_id = (int)$ready_db3;
                    }
                }
            }

            $arizali_status_id = 1; // Fallback
            $stmtArizali = $pdo->prepare("SELECT id FROM asset_status_labels WHERE type = 'undeployable' AND (name LIKE ? OR name LIKE ?) AND deleted_at IS NULL LIMIT 1");
            $stmtArizali->execute(['%Arızalı%', '%Faulty%']);
            $arizali_db = $stmtArizali->fetchColumn();
            if ($arizali_db) {
                $arizali_status_id = (int)$arizali_db;
            } else {
                $stmtArizali2 = $pdo->query("SELECT id FROM asset_status_labels WHERE type = 'undeployable' AND deleted_at IS NULL LIMIT 1");
                $arizali_db2 = $stmtArizali2->fetchColumn();
                if ($arizali_db2) {
                    $arizali_status_id = (int)$arizali_db2;
                }
            }

            $statusIdToSet = ($returnStatus === 'hasarli') ? $arizali_status_id : $ready_status_id;
            $pdo->prepare("UPDATE assets SET assigned_user_id = NULL, asset_id = NULL, status_id = ? WHERE id = ?")->execute([$statusIdToSet, $item_id]);
            $ctxId = $assigned_user_id ?: $assigned_asset_id;
            $ctxType = $assigned_user_id ? 'user' : ($assigned_asset_id ? 'asset' : null);
            
            $isAssetSource = ($ctxType === 'asset');
            $trMsg = $isAssetSource 
                ? "Bağlı olduğu cihazdan ($source_name) geri alındı" 
                : "Zimmetli personelden ($source_name) geri alındı";
            $enMsg = $isAssetSource 
                ? "Checked in from device: $source_name" 
                : "Checked in from user: $source_name";

            $statusTextTr = ($returnStatus === 'hasarli') ? "Hasarlı" : (($returnStatus === 'hasarli_kullanilabilir') ? "Hasarlı (Kullanılabilir)" : "Hasarsız");
            $statusTextEn = ($returnStatus === 'hasarli') ? "Damaged" : (($returnStatus === 'hasarli_kullanilabilir') ? "Damaged (Usable)" : "Undamaged");
            $reasonTextTr = !empty($returnReason) ? $returnReason : "Geri Alma";
            $reasonTextEn = !empty($returnReason) ? $returnReason : "Check In";

            $noteSuffixTr = " (Sebep: " . $reasonTextTr . " - " . $statusTextTr . (!empty($damageNote) ? " - Hasar Notu: " . $damageNote : "") . ")";
            $noteSuffixEn = " (Reason: " . $reasonTextEn . " - " . $statusTextEn . (!empty($damageNote) ? " - Damage Note: " . $damageNote : "") . ")";

            $logMsg = $trMsg . $noteSuffixTr . " / " . $enMsg . $noteSuffixEn;
            addAssetLog($pdo, $item_id, $current_user_id, 'checkin', $logMsg, $ctxId, 'asset', $ctxType);

            if ($assigned_user_id > 0) {
                $uInfoAssetReturn = $pdo->query("SELECT fullname, mail FROM users WHERE id = $assigned_user_id")->fetch();
                if ($uInfoAssetReturn && !empty($uInfoAssetReturn['mail'])) {
                    $astName = $pdo->query("SELECT name FROM assets WHERE id = $item_id")->fetchColumn() ?: 'Varlık';
                    $lang = $_SESSION['lang'] ?? 'tr';
                    if ($lang !== 'en') $lang = 'tr';
                    sendTemplatedMail($uInfoAssetReturn['mail'], $uInfoAssetReturn['fullname'], 'asset_returned', [
                        'fullname' => $uInfoAssetReturn['fullname'],
                        'ITEM_NAME' => $astName,
                        'DATE' => date('d.m.Y H:i'),
                        'ITEM_TYPE' => 'assets'
                    ], '', $lang);
                }
            }
        } elseif (in_array($view_submit, ['accessories', 'licenses', 'components', 'consumables'])) {
            $table = match($view_submit) {
                'licenses' => 'asset_licenses',
                'accessories' => 'asset_accessories',
                'consumables' => 'asset_consumables',
                'components' => 'asset_components',
                default => 'asset_assets'
            };
            $singular = getSingularType($view_submit);

            $item = $pdo->query("SELECT * FROM $table WHERE id = $item_id")->fetch();
            if (!$item) {
                if ($isAjax) {
                    echo json_encode(['success' => false, 'message' => ($isTr ? "Hata: Öğe bulunamadı." : "Error: Item not found.")]);
                    exit;
                }
                $_SESSION['hata'] = ($isTr ? "Hata: Öğe bulunamadı." : "Error: Item not found.");
                header("Location: varliklar?view=$view_submit");
                exit;
            }
            $itmName = $item['name'] ?? $item['software_name'] ?? $singular;
            $curSource = NULL;
            $fromAssetId = intval($_POST['from_asset_id'] ?? 0);
            $targetIdForLog = 0;
            $ctxTypeForLog = null;

            // Determine the correct checkout record to remove and the previous target
            $checkoutTable = "asset_" . $singular . "_checkouts";
            $checkoutId = intval($_POST['checkout_id'] ?? 0);
            $fromAssetId = intval($_POST['from_asset_id'] ?? 0);
            $checkoutInfo = null;

            // 1) If explicit checkout_id provided, use it (most precise)
            if ($checkoutId > 0) {
                $checkoutInfo = $pdo->query("SELECT * FROM $checkoutTable WHERE id = $checkoutId")->fetch();
                if ($checkoutInfo) {
                    $pdo->prepare("DELETE FROM $checkoutTable WHERE id = ?")->execute([$checkoutId]);
                }
            }

            // 2) If no explicit id, but returning from a specific asset, try to remove the matching asset checkout first
            if ($checkoutInfo === null && $fromAssetId > 0) {
                $stmtCheck = $pdo->prepare("SELECT * FROM $checkoutTable WHERE " . $singular . "_id = ? AND asset_id = ? ORDER BY id DESC LIMIT 1");
                $stmtCheck->execute([$item_id, $fromAssetId]);
                $checkoutInfo = $stmtCheck->fetch();
                if ($checkoutInfo) {
                    $pdo->prepare("DELETE FROM $checkoutTable WHERE id = ?")->execute([$checkoutInfo['id']]);
                }
            }

            // 3) Fallback: take the last assignment record for this item (regardless of asset/user)
            if ($checkoutInfo === null) {
                $stmtLast = $pdo->prepare("SELECT * FROM $checkoutTable WHERE " . $singular . "_id = ? ORDER BY id DESC LIMIT 1");
                $stmtLast->execute([$item_id]);
                $checkoutInfo = $stmtLast->fetch();
                if ($checkoutInfo) {
                    $pdo->prepare("DELETE FROM $checkoutTable WHERE id = ?")->execute([$checkoutInfo['id']]);
                }
            }

            // Determine log target from the checkout record we identified
            if ($checkoutInfo) {
                if (!empty($checkoutInfo['user_id'])) {
                    $targetIdForLog = (int) $checkoutInfo['user_id'];
                    $ctxTypeForLog = 'user';
                    $curSource = $pdo->query("SELECT fullname FROM users WHERE id = " . $targetIdForLog)->fetchColumn();
                } elseif (!empty($checkoutInfo['asset_id'])) {
                    $targetIdForLog = (int) $checkoutInfo['asset_id'];
                    $ctxTypeForLog = 'asset';
                    $curSource = $pdo->query("SELECT name FROM assets WHERE id = " . $targetIdForLog)->fetchColumn();
                }

                // SYNC: Also clear the main table if this was the last or primary assignment record
                $mainTable = match($view_submit) {
                    'licenses' => 'asset_licenses',
                    'accessories' => 'asset_accessories',
                    'consumables' => 'asset_consumables',
                    'components' => 'asset_components',
                    default => 'assets'
                };
                
                if ($view_submit === 'components') {
                    // For components, we always clear everything for this instance
                    $compStatus = ($returnStatus === 'hasarli') ? 0 : 1;
                    $pdo->prepare("UPDATE $mainTable SET asset_id = NULL, assigned_user_id = NULL, status = ? WHERE id = ?")->execute([$compStatus, $item_id]);
                    // Also ensure all related checkouts are gone (instance-based uniqueness)
                    $pdo->prepare("DELETE FROM $checkoutTable WHERE component_id = ?")->execute([$item_id]);
                } else if ($view_submit === 'accessories' && $returnStatus === 'hasarli') {
                    // Accessories: if returned damaged, decrement total_qty so it does not increase available pool (Kalan)
                    $qtyToReturn = (int) ($checkoutInfo['quantity'] ?? 1);
                    $pdo->prepare("UPDATE asset_accessories SET total_qty = total_qty - ? WHERE id = ?")->execute([$qtyToReturn, $item_id]);
                    
                    if ($ctxTypeForLog === 'user') {
                        $pdo->prepare("UPDATE $mainTable SET assigned_user_id = NULL WHERE id = ? AND assigned_user_id = ?")->execute([$item_id, $targetIdForLog]);
                    } elseif ($ctxTypeForLog === 'asset') {
                        $pdo->prepare("UPDATE $mainTable SET asset_id = NULL WHERE id = ? AND asset_id = ?")->execute([$item_id, $targetIdForLog]);
                    }
                } else if ($view_submit === 'consumables') {
                    $qtyToReturn = (int) ($checkoutInfo['quantity'] ?? 1);
                    $pdo->prepare("UPDATE asset_consumables SET remaining_qty = remaining_qty + ? WHERE id = ?")->execute([$qtyToReturn, $item_id]);
                    // Record a checkin entry in the checkout table as well to keep history correct
                    $pdo->prepare("INSERT INTO asset_consumable_checkouts (consumable_id, quantity, transaction_type, performer_id, notes) VALUES (?, ?, 'checkin', ?, ?)")->execute([$item_id, $qtyToReturn, $current_user_id, "İade alındı: " . ($curSource ?: '-') . " / Returned from: " . ($curSource ?: '-')]);
                } else {
                    if ($ctxTypeForLog === 'user') {
                        $pdo->prepare("UPDATE $mainTable SET assigned_user_id = NULL WHERE id = ? AND assigned_user_id = ?")->execute([$item_id, $targetIdForLog]);
                    } elseif ($ctxTypeForLog === 'asset') {
                        $pdo->prepare("UPDATE $mainTable SET asset_id = NULL WHERE id = ? AND asset_id = ?")->execute([$item_id, $targetIdForLog]);
                    }
                }
            } else {
                // FALLBACK: If we couldn't find any checkout record, but it's a component, check main table fields
                if ($view_submit === 'components') {
                    $fallback = $pdo->query("SELECT asset_id, assigned_user_id FROM asset_components WHERE id = $item_id")->fetch();
                    if ($fallback && ($fallback['asset_id'] || $fallback['assigned_user_id'])) {
                        if ($fallback['asset_id']) {
                            $targetIdForLog = (int) $fallback['asset_id'];
                            $ctxTypeForLog = 'asset';
                            $curSource = $pdo->query("SELECT name FROM assets WHERE id = " . $targetIdForLog)->fetchColumn();
                        } else {
                            $targetIdForLog = (int) $fallback['assigned_user_id'];
                            $ctxTypeForLog = 'user';
                            $curSource = $pdo->query("SELECT fullname FROM users WHERE id = " . $targetIdForLog)->fetchColumn();
                        }
                        // Clear the main table fields
                        $pdo->prepare("UPDATE asset_components SET asset_id = NULL, assigned_user_id = NULL WHERE id = ?")->execute([$item_id]);
                    }
                }
                
                // Existing asset context fallback
                if (!$targetIdForLog && $fromAssetId > 0) {
                    $targetIdForLog = $fromAssetId;
                    $ctxTypeForLog = 'asset';
                    $curSource = $pdo->query("SELECT name FROM assets WHERE id = $fromAssetId")->fetchColumn();
                    // attempt to clear main table for safety
                    $mainTable = ($view_submit == 'licenses') ? 'asset_licenses' : (($view_submit == 'accessories') ? 'asset_accessories' : 'asset_components');
                    $pdo->prepare("UPDATE $mainTable SET asset_id = NULL WHERE id = ? AND asset_id = ?")->execute([$item_id, $targetIdForLog]);
                }
            }

            $isAssetSource = ($ctxTypeForLog === 'asset');
            $sourceName = $curSource ?: 'Unknown';
            if ($view_submit === 'licenses') {
                $trMsg = $isAssetSource 
                    ? "Lisans $itmName, cihaz üzerinden ($sourceName) boşa çıkarıldı" 
                    : "Lisans $itmName, personelden ($sourceName) geri alındı";
                $enMsg = $isAssetSource 
                    ? "License $itmName unassigned from device $sourceName" 
                    : "License $itmName returned from user $sourceName";
            } elseif ($view_submit === 'accessories') {
                $trMsg = $isAssetSource 
                    ? "Aksesuar $itmName, cihaz üzerinden ($sourceName) iade alındı" 
                    : "Aksesuar $itmName, personelden ($sourceName) iade alındı";
                $enMsg = $isAssetSource 
                    ? "Accessory $itmName returned from device $sourceName" 
                    : "Accessory $itmName returned from user $sourceName";
            } elseif ($view_submit === 'consumables') {
                $qtyToReturn = (int) ($checkoutInfo['quantity'] ?? 1);
                $trMsg = $isAssetSource 
                    ? "Sarf malzemesi $itmName, cihaz üzerinden ($sourceName) geri alındı ($qtyToReturn Adet)" 
                    : "Sarf malzemesi $itmName, personelden ($sourceName) geri alındı ($qtyToReturn Adet)";
                $enMsg = $isAssetSource 
                    ? "Consumable $itmName returned from device $sourceName ($qtyToReturn Qty)" 
                    : "Consumable $itmName returned from user $sourceName ($qtyToReturn Qty)";
            } else { // components
                $trMsg = $isAssetSource 
                    ? "Bileşen $itmName, cihaz üzerinden ($sourceName) söküldü" 
                    : "Bileşen $itmName, personelden ($sourceName) geri alındı";
                $enMsg = $isAssetSource 
                    ? "Component $itmName detached from device $sourceName" 
                    : "Component $itmName returned from user $sourceName";
            }

            $statusTextTr = ($returnStatus === 'hasarli') ? "Hasarlı" : (($returnStatus === 'hasarli_kullanilabilir') ? "Hasarlı (Kullanılabilir)" : "Hasarsız");
            $statusTextEn = ($returnStatus === 'hasarli') ? "Damaged" : (($returnStatus === 'hasarli_kullanilabilir') ? "Damaged (Usable)" : "Undamaged");
            $reasonTextTr = !empty($returnReason) ? $returnReason : "Geri Alma";
            $reasonTextEn = !empty($returnReason) ? $returnReason : "Check In";

            $noteSuffixTr = " (Sebep: " . $reasonTextTr . " - " . $statusTextTr . (!empty($damageNote) ? " - Hasar Notu: " . $damageNote : "") . ")";
            $noteSuffixEn = " (Reason: " . $reasonTextEn . " - " . $statusTextEn . (!empty($damageNote) ? " - Damage Note: " . $damageNote : "") . ")";

            $logMsg = $trMsg . $noteSuffixTr . " / " . $enMsg . $noteSuffixEn;
            addAssetLog($pdo, $item_id, $current_user_id, 'checkin', $logMsg, $targetIdForLog, $singular, $ctxTypeForLog);

            // Send Return Notification Email (Only for users)
            if ($ctxTypeForLog === 'user' && !empty($targetIdForLog)) {
                $uInfo = $pdo->query("SELECT fullname, mail FROM users WHERE id = $targetIdForLog")->fetch();
                if ($uInfo && !empty($uInfo['mail'])) {
                    $lang = $_SESSION['lang'] ?? 'tr';
                    if ($lang !== 'en') $lang = 'tr';
                    sendTemplatedMail($uInfo['mail'], $uInfo['fullname'], 'asset_returned', [
                        'fullname' => $uInfo['fullname'],
                        'ITEM_NAME' => $itmName,
                        'DATE' => date('d.m.Y H:i'),
                        'ITEM_TYPE' => $view_submit
                    ], '', $lang);
                }
            }
        }

}
