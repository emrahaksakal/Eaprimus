<?php
/**
 * Network Scan AJAX Endpoint
 */

// Hatalari gizle - JSON'i bozmasin
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

// Shutdown handler: Beklenmedik bir hata veya timeout olursa JSON dondur
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_COMPILE_ERROR)) {
        ob_clean();
        echo json_encode(['error' => 'Sunucu hatasi olustu: ' . $error['message']]);
    }
});

// Once session baslat
// Use centralized session handling + CSRF helpers
require_once __DIR__ . '/../../app/includes/session.php';
require_csrf_token();

header('Content-Type: application/json; charset=utf-8');
set_time_limit(600); // 10 dakika

// Yetki kontrolleri
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    echo json_encode(['error' => 'Oturum bulunamadi. Lutfen tekrar giris yapin.']);
    exit;
}

$role = $_SESSION['role'] ?? 0;
if (!in_array((int) $role, [1, 3])) {
    $isTr = ($_SESSION['lang'] ?? 'tr') !== 'en';
    ob_clean();
    echo json_encode(['error' => $isTr
        ? 'Bu işlemi yapmak için yetkiniz yok. Sadece Teknik Destek ve Süper Admin erişebilir.'
        : 'You do not have permission for this action. Only Technical Support and Super Admin can access this.'
    ]);
    exit;
}

// DB baglan
require_once __DIR__ . '/../../app/config/db.php';
$pdo = db();

$action = $_POST['action'] ?? '';

if ($action === 'get_ips') {
    $cidr = trim($_POST['range'] ?? '');
    if (strpos($cidr, '/') === false) {
        if (substr_count($cidr, '.') === 2) {
            $cidr .= '.0/24';
        } else {
            $cidr .= '/24';
        }
    }

    list($ip, $mask) = explode('/', $cidr);
    $mask = (int)$mask;
    if ($mask < 20 || $mask > 32) {
        ob_clean();
        echo json_encode(['error' => 'Sadece /20 ile /32 arasindaki aglar taranabilir (max 4096 cihaz).']);
        exit;
    }

    $ip_long = ip2long($ip);
    if ($ip_long === false) {
        ob_clean();
        echo json_encode(['error' => 'Gecersiz IP adresi.']);
        exit;
    }

    $mask_long = ~((1 << (32 - $mask)) - 1);
    $network = $ip_long & $mask_long;
    $broadcast = $ip_long | (~$mask_long);

    $start = $network + 1;
    $end = $broadcast - 1;

    if (($end - $start) > 4094) {
        $end = $start + 4094;
    }
    
    $ips = [];
    for ($long = $start; $long <= $end; $long++) {
        $ips[] = long2ip($long);
    }
    
    ob_clean();
    echo json_encode(['ips' => $ips]);
    exit;
}

function isHostAliveSocket(string $ip): bool
{
    $ports = [445, 135, 80, 443, 3389];
    foreach ($ports as $port) {
        $conn = @fsockopen($ip, $port, $errno, $errstr, 0.2);
        if ($conn !== false) {
            fclose($conn);
            return true;
        }
    }
    return false;
}

function pingHost(string $ip): bool
{
    if (!function_exists('exec')) return false;
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $output = [];
    $ret = 1;
    if ($isWin) {
        @exec('ping -n 1 -w 200 ' . $ip, $output, $ret);
    } else {
        @exec('ping -c 1 -W 1 ' . escapeshellarg($ip), $output, $ret);
    }
    return $ret === 0;
}

function getMac(string $ip): string
{
    if (!function_exists('exec')) return '';
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $out = [];
    if ($isWin) {
        @exec('arp -a ' . $ip, $out);
    } else {
        @exec('arp -n ' . escapeshellarg($ip), $out);
    }
    foreach ($out as $line) {
        if (strpos($line, $ip) !== false) {
            if ($isWin) {
                preg_match('/([0-9a-f]{2}-[0-9a-f]{2}-[0-9a-f]{2}-[0-9a-f]{2}-[0-9a-f]{2}-[0-9a-f]{2})/i', $line, $m);
                if (!empty($m[1])) return strtoupper(str_replace('-', ':', $m[1]));
            } else {
                preg_match('/([0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2})/i', $line, $m);
                if (!empty($m[1])) return strtoupper($m[1]);
            }
        }
    }
    return '';
}

if ($action === 'scan_chunk') {
    session_write_close();
    $ips = json_decode($_POST['ips'] ?? '[]', true);
    if (!is_array($ips)) $ips = [];
    
    $discovered = [];
    foreach ($ips as $ip) {
        // Basit validasyon
        if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
        
        $alive = pingHost($ip);
        if (!$alive) {
            $alive = isHostAliveSocket($ip);
        }

        if (!$alive) continue;

        $hostname = @gethostbyaddr($ip);
        if ($hostname === $ip || $hostname === false) $hostname = '';
        $mac = getMac($ip);

        $discovered[] = ['ip' => $ip, 'mac' => $mac, 'hostname' => $hostname];

        try {
            $exist = $pdo->prepare("SELECT id FROM discovered_assets WHERE ip_address = ?");
            $exist->execute([$ip]);
            $found_id = $exist->fetchColumn();
            if ($found_id) {
                $pdo->prepare("UPDATE discovered_assets SET mac_address=?, hostname=?, discovered_at=NOW() WHERE ip_address=?")
                    ->execute([$mac, $hostname, $ip]);
            } else {
                $pdo->prepare("INSERT INTO discovered_assets (ip_address, mac_address, hostname, status) VALUES (?, ?, ?, 'pending')")
                    ->execute([$ip, $mac, $hostname]);
                $found_id = $pdo->lastInsertId();
            }
            $discovered[count($discovered) - 1]['id'] = $found_id;
        } catch (PDOException $e) {
        }
    }
    
    ob_clean();
    echo json_encode(['discovered' => $discovered]);
    exit;
}

ob_clean();
echo json_encode(['error' => 'Gecersiz islem.']);
exit;

