<?php
// public/api/v1/index.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/includes/jwt.php';
require_once __DIR__ . '/../../../app/includes/logger.php';

$pdo = db();

function cleanCompare($val1, $val2) {
    $v1 = strtolower(trim((string)($val1 ?? '')));
    $v2 = strtolower(trim((string)($val2 ?? '')));
    return $v1 === $v2;
}

function cleanCompareSpecs($old, $new) {
    if (!is_array($old)) $old = [];
    if (!is_array($new)) $new = [];
    
    $oldFiltered = [];
    foreach ($old as $k => $v) {
        $val = trim((string)($v ?? ''));
        if ($val !== '') {
            $oldFiltered[strtolower(trim($k))] = strtolower($val);
        }
    }
    
    $newFiltered = [];
    foreach ($new as $k => $v) {
        $val = trim((string)($v ?? ''));
        if ($val !== '') {
            $newFiltered[strtolower(trim($k))] = strtolower($val);
        }
    }
    
    ksort($oldFiltered);
    ksort($newFiltered);
    
    return $oldFiltered === $newFiltered;
}

// Rate limiting implementation (Max 60 requests per minute)
try {
    $pdo->query("SELECT 1 FROM api_rate_limits LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_rate_limits (
            ip VARCHAR(45) PRIMARY KEY,
            requests INT DEFAULT 0,
            reset_time INT NOT NULL
        )");
    } catch (Exception $ex) {}
}

// Ensure agent_keys table exists
try {
    $pdo->query("SELECT 1 FROM agent_keys LIMIT 1");
    try {
        $pdo->query("SELECT registered_by_client_id FROM agent_keys LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE `agent_keys` ADD COLUMN `registered_by_client_id` varchar(64) DEFAULT NULL");
    }
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `agent_keys` (
            `id` int NOT NULL AUTO_INCREMENT,
            `mac_address` varchar(45) NOT NULL,
            `computer_name` varchar(255) NOT NULL,
            `client_id` varchar(64) NOT NULL,
            `client_secret_hash` varchar(255) NOT NULL,
            `client_secret_plain` varchar(255) NOT NULL,
            `registered_by_client_id` varchar(64) DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `revoked_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_agent_client_id` (`client_id`),
            UNIQUE KEY `idx_agent_mac` (`mac_address`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $ex) {}
}

// Ensure agent_activation_tokens table exists
try {
    $pdo->query("SELECT 1 FROM agent_activation_tokens LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `agent_activation_tokens` (
            `id` int NOT NULL AUTO_INCREMENT,
            `token` varchar(64) NOT NULL,
            `created_by` int NOT NULL,
            `expires_at` datetime NOT NULL,
            `used_count` int DEFAULT 0,
            `max_uses` int DEFAULT 100,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_act_token` (`token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $ex) {}
}

// Ensure default settings exist
$defaultSettings = [
    'api_agent_auto_register' => '0',
    'api_verify_ssl' => '0'
];
foreach ($defaultSettings as $k => $v) {
    try {
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $stmtCheck->execute([$k]);
        if ((int)$stmtCheck->fetchColumn() === 0) {
            $stmtIns = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmtIns->execute([$k, $v]);
        }
    } catch (Exception $ex) {}
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$currentTime = time();

try {
    $stmtDel = $pdo->prepare("DELETE FROM api_rate_limits WHERE reset_time < ?");
    $stmtDel->execute([$currentTime]);

    $stmtLimit = $pdo->prepare("SELECT requests, reset_time FROM api_rate_limits WHERE ip = ?");
    $stmtLimit->execute([$clientIp]);
    $limitData = $stmtLimit->fetch(PDO::FETCH_ASSOC);

    if ($limitData) {
        if ($limitData['requests'] >= 60) {
            http_response_code(429);
            header('Retry-After: ' . ($limitData['reset_time'] - $currentTime));
            echo json_encode([
                'error' => 'Too Many Requests',
                'message' => 'Rate limit exceeded. Maximum 60 requests per minute are allowed.'
            ]);
            exit;
        }
        $stmtUpLimit = $pdo->prepare("UPDATE api_rate_limits SET requests = requests + 1 WHERE ip = ?");
        $stmtUpLimit->execute([$clientIp]);
    } else {
        $stmtInsLimit = $pdo->prepare("INSERT INTO api_rate_limits (ip, requests, reset_time) VALUES (?, 1, ?)");
        $stmtInsLimit->execute([$clientIp, $currentTime + 60]);
    }
} catch (Exception $e) {
    // Fail silently if rate limiter DB errors occur
}

// Ensure required columns exist on assets table
$required_cols = [
    'last_api_sync' => "ALTER TABLE assets ADD COLUMN last_api_sync DATETIME DEFAULT NULL",
    'sync_requested' => "ALTER TABLE assets ADD COLUMN sync_requested TINYINT(1) DEFAULT 0",
    'ip_secondary' => "ALTER TABLE assets ADD COLUMN ip_secondary VARCHAR(45) DEFAULT NULL",
    'device_name' => "ALTER TABLE assets ADD COLUMN device_name VARCHAR(255) DEFAULT NULL",
    'cpu' => "ALTER TABLE assets ADD COLUMN cpu VARCHAR(255) DEFAULT NULL",
    'ram' => "ALTER TABLE assets ADD COLUMN ram VARCHAR(50) DEFAULT NULL",
    'disk' => "ALTER TABLE assets ADD COLUMN disk VARCHAR(255) DEFAULT NULL",
    'gpu' => "ALTER TABLE assets ADD COLUMN gpu VARCHAR(255) DEFAULT NULL",
    'os' => "ALTER TABLE assets ADD COLUMN os VARCHAR(255) DEFAULT NULL",
    'monitor' => "ALTER TABLE assets ADD COLUMN monitor VARCHAR(255) DEFAULT NULL",
    'mainboard' => "ALTER TABLE assets ADD COLUMN mainboard VARCHAR(255) DEFAULT NULL",
    'specs' => "ALTER TABLE assets ADD COLUMN specs LONGTEXT DEFAULT NULL"
];
foreach ($required_cols as $col => $sql) {
    try {
        $pdo->query("SELECT `$col` FROM assets LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec($sql);
        } catch (Exception $ex) {}
    }
}

// Ensure existing columns have sufficient length (255)
$alter_lengths = [
    "ALTER TABLE assets MODIFY COLUMN cpu VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE assets MODIFY COLUMN disk VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE assets MODIFY COLUMN gpu VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE assets MODIFY COLUMN os VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE assets MODIFY COLUMN monitor VARCHAR(255) DEFAULT NULL"
];
foreach ($alter_lengths as $sql) {
    try {
        $pdo->exec($sql);
    } catch (Exception $e) {
        if (function_exists('systemLog')) {
            systemLog('DATABASE_MIGRATION_ERROR', 'Veritabanı Sütun Genişletme Hatası / Database Column Expand Error: ' . $e->getMessage());
        }
    }
}

// Get the requested path from rewrite parameter or URI
$request = $_GET['request'] ?? '';
$request = trim($request, '/');
$parts = explode('/', $request);
$endpoint = $parts[0] ?? '';

// 1. AUTH ENDPOINT
if ($endpoint === 'auth') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $client_id = trim($input['client_id'] ?? $input['api_key'] ?? '');
    $client_secret = trim($input['client_secret'] ?? $input['api_secret'] ?? '');

    if (empty($client_id) || empty($client_secret)) {
        http_response_code(400);
        echo json_encode(['error' => 'client_id and client_secret are required']);
        exit;
    }

    $authenticated = false;
    $isAgent = false;
    $isSystemKey = false;
    $userId = null;
    $userRole = null;
    $jwtSecret = null;

    // A. Check user-specific api_keys table if it exists
    $tableExists = false;
    try {
        $pdo->query("SELECT 1 FROM api_keys LIMIT 1");
        $tableExists = true;
    } catch (Exception $e) {}

    if ($tableExists) {
        $stmt = $pdo->prepare('SELECT ak.id, ak.user_id, u.role, ak.client_secret_hash FROM api_keys ak JOIN users u ON u.id = ak.user_id WHERE ak.client_id = ? AND ak.revoked_at IS NULL');
        $stmt->execute([$client_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && password_verify($client_secret, $row['client_secret_hash'])) {
            $authenticated = true;
            $userId = (int)$row['user_id'];
            $userRole = $row['role'];
            $jwtSecret = $row['client_secret_hash'];
        }
    }

    // B. Fallback to global API keys in settings table
    if (!$authenticated) {
        $sys_api_key = s('api_client_id');
        $sys_api_secret = s('api_client_secret');
        $api_enabled = s('api_enabled');

        if (!empty($sys_api_key) && !empty($sys_api_secret)) {
            if ($client_id === $sys_api_key && $client_secret === $sys_api_secret) {
                // Authenticated as the first master admin (role = 1)
                $stmtAdmin = $pdo->query("SELECT id, role FROM users WHERE role = 1 AND deleted_at IS NULL LIMIT 1");
                $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
                $userId = $admin ? (int)$admin['id'] : 1;
                $userRole = $admin ? (int)$admin['role'] : 1;
                $jwtSecret = $sys_api_secret;
                $authenticated = true;
                $isSystemKey = true;
            }
        }
    }

    // C. Check agent_keys table if it exists
    if (!$authenticated) {
        $agentTableExists = false;
        try {
            $pdo->query("SELECT 1 FROM agent_keys LIMIT 1");
            $agentTableExists = true;
        } catch (Exception $e) {}

        if ($agentTableExists) {
            $stmtAgent = $pdo->prepare('SELECT client_secret_hash, computer_name FROM agent_keys WHERE client_id = ? AND revoked_at IS NULL');
            $stmtAgent->execute([$client_id]);
            $rowAgent = $stmtAgent->fetch(PDO::FETCH_ASSOC);
            if ($rowAgent && password_verify($client_secret, $rowAgent['client_secret_hash'])) {
                // Authenticated as agent, assign the first admin's ID/role so the token is authorized for asset syncing
                $stmtAdmin = $pdo->query("SELECT id, role FROM users WHERE role = 1 AND deleted_at IS NULL LIMIT 1");
                $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
                $userId = $admin ? (int)$admin['id'] : 1;
                $userRole = $admin ? (int)$admin['role'] : 1;
                $jwtSecret = $rowAgent['client_secret_hash'];
                $authenticated = true;
                $isAgent = true;
            }
        }
    }

    if (!$authenticated) {
        if (function_exists('systemLog')) {
            systemLog('AGENT_AUTH_FAILED', "Ajan kimlik doğrulama başarısız. Client ID: {$client_id} IP: {$clientIp}");
        }
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API credentials']);
        exit;
    }

    // Enforce api_enabled check ONLY for global system integrations
    if ($isSystemKey) {
        if (s('api_enabled') !== '1') {
            http_response_code(403);
            echo json_encode([
                'error' => 'API is disabled',
                'message' => 'API Girişi kapalıdır. Lütfen sistem ayarlarından aktifleştirin.'
            ]);
            exit;
        }
    }

    // Build JWT payload with user identification, role and client_id
    $payload = [
        'sub'  => $userId,
        'role' => $userRole,
        'client_id' => $client_id,
        'iat'  => time(),
        'exp'  => time() + 3600,
    ];

    $token = JWT::encode($payload, $jwtSecret, 3600);

    echo json_encode([
        'token' => $token,
        'expires_in' => 3600,
        'token_type' => 'Bearer'
    ]);
    exit;
}

// 1.5. AGENT REGISTER ENDPOINT
if ($endpoint === 'agent-register') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $activation_token = trim($input['activation_token'] ?? $input['api_key'] ?? '');
    $client_secret = trim($input['client_secret'] ?? $input['api_secret'] ?? '');

    $mac_address = trim($input['mac_address'] ?? '');
    $computer_name = trim($input['computer_name'] ?? '');
    $ip_address = trim($input['ip_address'] ?? '');

    if (empty($activation_token) || empty($mac_address) || empty($computer_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'activation_token, mac_address, and computer_name are required']);
        exit;
    }

    $authenticated = false;
    $is_auto_register = s('api_agent_auto_register') === '1';

    if ($is_auto_register) {
        // Authenticate using agent_activation_tokens table
        $actTableExists = false;
        try {
            $pdo->query("SELECT 1 FROM agent_activation_tokens LIMIT 1");
            $actTableExists = true;
        } catch (Exception $e) {}

        if ($actTableExists) {
            $stmtAct = $pdo->prepare('SELECT id, used_count, max_uses FROM agent_activation_tokens WHERE token = ? AND expires_at > NOW() LIMIT 1');
            $stmtAct->execute([$activation_token]);
            $rowAct = $stmtAct->fetch(PDO::FETCH_ASSOC);

            if ($rowAct && (int)$rowAct['used_count'] < (int)$rowAct['max_uses']) {
                // Token is valid and has uses left!
                $authenticated = true;
                
                // Increment use count
                $stmtInc = $pdo->prepare('UPDATE agent_activation_tokens SET used_count = used_count + 1 WHERE id = ?');
                $stmtInc->execute([$rowAct['id']]);
            }
        }
    } else {
        // Fallback to global credentials or user API keys if auto-register is disabled
        // A. Check user api_keys
        $tableExists = false;
        try {
            $pdo->query("SELECT 1 FROM api_keys LIMIT 1");
            $tableExists = true;
        } catch (Exception $e) {}

        if ($tableExists && !empty($client_secret)) {
            $stmt = $pdo->prepare('SELECT ak.client_secret_hash FROM api_keys ak JOIN users u ON u.id = ak.user_id WHERE ak.client_id = ? AND ak.revoked_at IS NULL AND u.role = 1');
            $stmt->execute([$activation_token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && password_verify($client_secret, $row['client_secret_hash'])) {
                $authenticated = true;
            }
        }

        // B. Check global credentials
        if (!$authenticated && !empty($client_secret)) {
            $sys_api_key = s('api_client_id');
            $sys_api_secret = s('api_client_secret');
            $api_enabled = s('api_enabled');

            if (!empty($sys_api_key) && !empty($sys_api_secret)) {
                if ($activation_token === $sys_api_key && $client_secret === $sys_api_secret) {
                    $authenticated = true;
                }
            }
        }
    }

    if (!$authenticated) {
        if (function_exists('systemLog')) {
            systemLog('AGENT_REGISTER_FAILED', "Ajan kaydı başarısız (Geçersiz token/şifre). Cihaz: {$computer_name} MAC: {$mac_address} IP: {$ip_address}");
        }
        http_response_code(401);
        echo json_encode(['error' => 'Invalid activation token or registration credentials']);
        exit;
    }

    // Check if MAC is already registered in agent_keys
    try {
        $stmtCheck = $pdo->prepare("SELECT * FROM agent_keys WHERE mac_address = ? LIMIT 1");
        $stmtCheck->execute([$mac_address]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if (!empty($existing['revoked_at'])) {
                // If revoked, generate a new secret and reactivate it
                $newSecret = 'ea_a_sec_' . bin2hex(random_bytes(16));
                $newHash = password_hash($newSecret, PASSWORD_DEFAULT);
                $stmtUp = $pdo->prepare("UPDATE agent_keys SET client_secret_hash = ?, client_secret_plain = ?, revoked_at = NULL, computer_name = ?, created_at = NOW(), registered_by_client_id = ? WHERE id = ?");
                $stmtUp->execute([$newHash, $newSecret, $computer_name, $activation_token, $existing['id']]);

                if (function_exists('systemLog')) {
                    systemLog('AGENT_RE_REGISTERED', "Ajan yeniden kaydedildi (Yetki açıldı): {$computer_name} (MAC: {$mac_address})");
                }

                echo json_encode([
                    'success' => true,
                    'client_id' => $existing['client_id'],
                    'client_secret' => $newSecret
                ]);
            } else {
                // Already registered and active, return existing credentials
                $secret = $existing['client_secret_plain'];
                if (empty($secret)) {
                    $secret = 'ea_a_sec_' . bin2hex(random_bytes(16));
                    $hash = password_hash($secret, PASSWORD_DEFAULT);
                    $stmtUp = $pdo->prepare("UPDATE agent_keys SET client_secret_hash = ?, client_secret_plain = ?, computer_name = ?, created_at = NOW(), registered_by_client_id = ? WHERE id = ?");
                    $stmtUp->execute([$hash, $secret, $computer_name, $activation_token, $existing['id']]);
                }
                echo json_encode([
                    'success' => true,
                    'client_id' => $existing['client_id'],
                    'client_secret' => $secret
                ]);
            }
            exit;
        }

        // Generate new agent credentials
        $newClientId = 'ea_a_key_' . bin2hex(random_bytes(12));
        $newClientSecret = 'ea_a_sec_' . bin2hex(random_bytes(16));
        $newSecretHash = password_hash($newClientSecret, PASSWORD_DEFAULT);

        $stmtIns = $pdo->prepare("INSERT INTO agent_keys (mac_address, computer_name, client_id, client_secret_hash, client_secret_plain, registered_by_client_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtIns->execute([$mac_address, $computer_name, $newClientId, $newSecretHash, $newClientSecret, $activation_token]);

        if (function_exists('systemLog')) {
            systemLog('AGENT_REGISTERED', "Yeni ajan kaydedildi: {$computer_name} (MAC: {$mac_address}, IP: {$ip_address})");
        }

        echo json_encode([
            'success' => true,
            'client_id' => $newClientId,
            'client_secret' => $newClientSecret
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// 2. JWT TOKEN VALIDATION FOR ALL OTHER ENDPOINTS
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (empty($authHeader) && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authorization token required']);
    exit;
}

$jwtToken = $matches[1];

// Decode JWT payload without verification to get user ID (sub)
$tokenParts = explode('.', $jwtToken);
if (count($tokenParts) !== 3) {
    http_response_code(401);
    echo json_encode(['error' => 'Malformed JWT token']);
    exit;
}

$payloadObj = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1])), true);
$userId = intval($payloadObj['sub'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid user scope in token']);
    exit;
}

// Fetch user's secret for signature verification
$userSecret = null;
$tokenClientId = $payloadObj['client_id'] ?? '';
$isAgentToken = false;
$isSystemToken = false;

if (!empty($tokenClientId)) {
    // A. Check agent_keys table
    $agentTableExists = false;
    try {
        $pdo->query("SELECT 1 FROM agent_keys LIMIT 1");
        $agentTableExists = true;
    } catch (Exception $e) {}

    if ($agentTableExists) {
        $stmtAgent = $pdo->prepare('SELECT client_secret_hash FROM agent_keys WHERE client_id = ? AND revoked_at IS NULL LIMIT 1');
        $stmtAgent->execute([$tokenClientId]);
        $userSecret = $stmtAgent->fetchColumn();
        if ($userSecret) {
            $isAgentToken = true;
        }
    }

    // B. Check api_keys table
    if (!$userSecret) {
        $tableExists = false;
        try {
            $pdo->query("SELECT 1 FROM api_keys LIMIT 1");
            $tableExists = true;
        } catch (Exception $e) {}

        if ($tableExists) {
            $stmt = $pdo->prepare('SELECT client_secret_hash FROM api_keys WHERE client_id = ? AND revoked_at IS NULL LIMIT 1');
            $stmt->execute([$tokenClientId]);
            $userSecret = $stmt->fetchColumn();
        }
    }

    // C. Fallback to global secret
    if (!$userSecret) {
        $sys_api_key = s('api_client_id');
        if ($tokenClientId === $sys_api_key) {
            $userSecret = s('api_client_secret');
            $isSystemToken = true;
        }
    }
}

if (empty($userSecret)) {
    // Fall back to old method (using $userId from payload) for backward compatibility
    $tableExists = false;
    try {
        $pdo->query("SELECT 1 FROM api_keys LIMIT 1");
        $tableExists = true;
    } catch (Exception $e) {}

    if ($tableExists) {
        $stmt = $pdo->prepare('SELECT client_secret_hash FROM api_keys WHERE user_id = ? AND revoked_at IS NULL LIMIT 1');
        $stmt->execute([$userId]);
        $userSecret = $stmt->fetchColumn();
    }

    if (!$userSecret) {
        $sys_api_secret = s('api_client_secret');
        if ($sys_api_secret) {
            $userSecret = $sys_api_secret;
            $isSystemToken = true;
        }
    }
}

if (!$userSecret) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token: secret not found']);
    exit;
}

// Verify JWT using user's secret
$payload = JWT::decode($jwtToken, $userSecret);
if (!$payload) {
    if (function_exists('systemLog')) {
        systemLog('AGENT_JWT_INVALID', "Ajan geçersiz veya süresi dolmuş JWT token gönderdi. IP: {$clientIp}");
    }
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired token']);
    exit;
}

// Enforce api_enabled check ONLY for global system integrations
if ($isSystemToken) {
    if (s('api_enabled') !== '1') {
        http_response_code(403);
        echo json_encode([
            'error' => 'API is disabled',
            'message' => 'API Girişi kapalıdır. Lütfen sistem ayarlarından aktifleştirin.'
        ]);
        exit;
    }
}

// Set tenant identifier to user ID for downstream code
$tenantId = $userId;

// 3. ENFORCE GLOBAL ROUTE SCOPE
define('CURRENT_TENANT_ID', $tenantId);

// 4. API ENDPOINTS IMPLEMENTATION

// ── agent-wait: Long-polling endpoint ─────────────────────────────────────
// Agent connects once and waits up to 55 seconds.
// Server checks DB every 2 seconds; returns instantly when sync_requested=1.
// No repeated short-interval polling needed on the agent side.
if ($endpoint === 'agent-wait') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }

    $mac  = trim($_GET['mac']  ?? '');
    $name = trim($_GET['name'] ?? '');

    if (empty($mac) && empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'mac or name parameter required']);
        exit;
    }

    // Allow this script to run up to 65 seconds
    set_time_limit(65);
    ignore_user_abort(false);

    $macNormalized = strtolower(str_replace([':', '-', ' '], '', $mac));
    $nameLower     = strtolower($name);

    $deadline = time() + 55; // wait up to 55 seconds

    while (time() < $deadline) {
        try {
            $st = $pdo->prepare("
                SELECT MAX(sync_requested)
                FROM assets
                WHERE (
                    (mac_address IS NOT NULL AND mac_address != ''
                     AND LOWER(REPLACE(REPLACE(mac_address,':',''),'-','')) = ?)
                    OR LOWER(name) = ?
                ) AND deleted_at IS NULL
            ");
            $st->execute([$macNormalized, $nameLower]);
            $val = $st->fetchColumn();
            if ($val !== null && (int)$val === 1) {
                // Trigger detected — respond immediately
                echo json_encode(['sync_requested' => true]);
                exit;
            }
        } catch (Exception $e) {}

        sleep(2); // check every 2 seconds
    }

    // Timeout — no trigger during this window
    echo json_encode(['sync_requested' => false]);
    exit;

// ── agent-check: Single instant check (kept for backward compat) ───────────
} elseif ($endpoint === 'agent-check') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }
    $mac = trim($_GET['mac'] ?? '');
    $name = trim($_GET['name'] ?? '');
    
    if (empty($mac) && empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'mac or name parameter required']);
        exit;
    }
    
    $sync_requested = false;
    try {
        $macNormalized = strtolower(str_replace([':', '-', ' '], '', $mac));
        $nameLower = strtolower($name);
        
        $stmtAsset = $pdo->prepare("
            SELECT MAX(sync_requested) 
            FROM assets 
            WHERE (
                (mac_address IS NOT NULL AND mac_address != '' AND LOWER(REPLACE(REPLACE(mac_address, ':', ''), '-', '')) = ?) 
                OR LOWER(name) = ?
            ) AND deleted_at IS NULL
        ");
        $stmtAsset->execute([$macNormalized, $nameLower]);
        $val = $stmtAsset->fetchColumn();
        if ($val !== null && $val !== false) {
            $sync_requested = ((int)$val === 1);
        }
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'sync_requested' => $sync_requested
    ]);
    exit;
} elseif ($endpoint === 'assets') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // GET /assets
        $stmt = $pdo->prepare("SELECT id, name, asset_tag, serial_no, ip_address, type, specs, created_at FROM assets WHERE deleted_at IS NULL ORDER BY id DESC");
        $stmt->execute();
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Unserialize specs if exists
        foreach ($assets as &$asset) {
            if (!empty($asset['specs'])) {
                $asset['specs'] = json_decode($asset['specs'], true) ?: $asset['specs'];
            }
        }
        echo json_encode(['success' => true, 'assets' => $assets]);
        exit;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // POST /assets (Insert or Sync)
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $asset_tag = trim($input['asset_tag'] ?? '');
        $serial_no = trim($input['serial_no'] ?? '');
        $ip_address = trim($input['ip_address'] ?? '');
        $mac_address = trim($input['mac_address'] ?? '');
        $ip_secondary = trim($input['ip_secondary'] ?? '');
        $type = trim($input['type'] ?? 'Laptop');
        $specs = $input['specs'] ?? [];

        if ($name === '' || $asset_tag === '') {
            http_response_code(400);
            echo json_encode(['error' => 'name and asset_tag are required']);
            exit;
        }

        // Link User API key to agent_keys for this MAC address if authenticated via user key
        if (!empty($tokenClientId) && strpos($tokenClientId, 'ea_u_key_') === 0) {
            try {
                $stmtUserKey = $pdo->prepare("SELECT client_secret_hash, client_secret_plain FROM api_keys WHERE client_id = ? AND revoked_at IS NULL LIMIT 1");
                $stmtUserKey->execute([$tokenClientId]);
                $userKeyRow = $stmtUserKey->fetch(PDO::FETCH_ASSOC);

                if ($userKeyRow) {
                    $stmtCheckAgent = $pdo->prepare("SELECT id FROM agent_keys WHERE mac_address = ? LIMIT 1");
                    $stmtCheckAgent->execute([$mac_address]);
                    $existingAgent = $stmtCheckAgent->fetch(PDO::FETCH_ASSOC);

                    if (!$existingAgent) {
                        $newAgentClientId = 'ea_a_key_' . bin2hex(random_bytes(12));
                        $stmtInsAgent = $pdo->prepare("INSERT INTO agent_keys (mac_address, computer_name, client_id, client_secret_hash, client_secret_plain, registered_by_client_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $stmtInsAgent->execute([
                            $mac_address,
                            $name,
                            $newAgentClientId,
                            $userKeyRow['client_secret_hash'],
                            $userKeyRow['client_secret_plain'],
                            $tokenClientId
                        ]);
                    } else {
                        $stmtUpdAgent = $pdo->prepare("UPDATE agent_keys SET computer_name = ?, registered_by_client_id = ?, revoked_at = NULL WHERE id = ?");
                        $stmtUpdAgent->execute([$name, $tokenClientId, $existingAgent['id']]);
                    }
                }
            } catch (Exception $e) {}
        }

        // Get actual column lengths from database to safely truncate values and avoid MySQL truncation errors
        $column_lengths = [
            'cpu' => 100,
            'ram' => 50,
            'disk' => 50,
            'gpu' => 100,
            'os' => 100,
            'monitor' => 100,
            'mainboard' => 255
        ];
        try {
            $q = $pdo->query("DESCRIBE assets");
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $field = $row['Field'] ?? $row['field'] ?? '';
                $colType = $row['Type'] ?? $row['type'] ?? '';
                if (isset($column_lengths[$field]) && preg_match('/varchar\((\d+)\)/i', $colType, $m)) {
                    $column_lengths[$field] = intval($m[1]);
                }
            }
        } catch (Exception $e) {}

        // Extract individual fields from specs if present and safely truncate to avoid database truncation errors
        $os = mb_substr(trim($specs['os'] ?? ''), 0, $column_lengths['os']);
        $cpu = mb_substr(trim($specs['cpu'] ?? ''), 0, $column_lengths['cpu']);
        
        $ram = trim((string)($specs['ram_gb'] ?? ''));
        if ($ram !== '' && stripos($ram, 'GB') === false) {
            $ram = $ram . ' GB';
        }
        $ram = mb_substr($ram, 0, $column_lengths['ram']);
        
        $disk = mb_substr(trim((string)($specs['disk_c_total_gb'] ?? '')), 0, $column_lengths['disk']);
        // disk_c_total_gb already contains 'GB' in the string - do NOT append again
        
        $gpu = mb_substr(trim($specs['gpu'] ?? ''), 0, $column_lengths['gpu']);
        $monitor = mb_substr(trim($specs['monitor'] ?? ''), 0, $column_lengths['monitor']);
        $mainboard = mb_substr(trim($specs['mainboard'] ?? ''), 0, $column_lengths['mainboard']);

        try {
            
            // Check unique asset tag or serial
            if (!function_exists('isDefaultSerial')) {
                function isDefaultSerial($serial) {
                    $serial = trim($serial);
                    if ($serial === '') return true;
                    $defaults = [
                        'default string',
                        'to be filled by o.e.m.',
                        'to be filled by oem',
                        'system serial number',
                        'system product name',
                        'chassis serial number',
                        'not specified',
                        'none',
                        'unknown',
                        '00000000',
                        '00000000000',
                        '0123456789',
                        '1234567890',
                        'type1productconfigid',
                        'evaluation only'
                    ];
                    return in_array(strtolower($serial), $defaults);
                }
            }

            // If the incoming serial_no is a default serial, do not use it to search/match other devices
            $searchSerial = $serial_no;
            if (isDefaultSerial($searchSerial)) {
                $searchSerial = '';
            }

            $stmtCheck = $pdo->prepare("SELECT * FROM assets WHERE (asset_tag = ? OR (serial_no = ? AND serial_no != '')) AND deleted_at IS NULL LIMIT 1");
            $stmtCheck->execute([$asset_tag, $searchSerial]);
            $existingRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            $existing = $existingRow ? $existingRow['id'] : null;

            require_once __DIR__ . '/../../../app/includes/asset_helpers.php';

            $nowStr = date('Y-m-d H:i:s');
            if ($existing) {
                // If the database has a valid serial number and the incoming serial is default, preserve the database serial number
                if (!empty($existingRow['serial_no']) && !isDefaultSerial($existingRow['serial_no'])) {
                    if (isDefaultSerial($serial_no)) {
                        $serial_no = $existingRow['serial_no'];
                    }
                }

                // Check for changes and resolve values to preserve existing DB values for empty incoming data
                $hasHardwareChanges = false;
                $hardwareChangesDetailsTr = [];
                $hardwareChangesDetailsEn = [];
                
                $resolveField = function($fieldName, $incomingVal, $type = 'string') use ($existingRow, &$hasHardwareChanges, &$hardwareChangesDetailsTr, &$hardwareChangesDetailsEn) {
                    $dbVal = trim((string)($existingRow[$fieldName] ?? ''));
                    $incomingVal = trim((string)($incomingVal ?? ''));
                    
                    // If incoming is empty or default, preserve database value (not counted as a change)
                    if ($incomingVal === '' || strtolower($incomingVal) === 'default string') {
                        return $dbVal;
                    }
                    
                    if ($type === 'mac') {
                        $normDb = preg_replace('/[^a-f0-9]/i', '', strtolower($dbVal));
                        $normIncoming = preg_replace('/[^a-f0-9]/i', '', strtolower($incomingVal));
                    } elseif ($type === 'serial') {
                        $normDb = preg_replace('/[^a-z0-9]/i', '', strtolower($dbVal));
                        $normIncoming = preg_replace('/[^a-z0-9]/i', '', strtolower($incomingVal));
                    } else {
                        $normDb = strtolower($dbVal);
                        $normDb = preg_replace('/\s+/', ' ', $normDb);
                        $normDb = preg_replace('/\s*gb\b/i', 'gb', $normDb);
                        $normDb = preg_replace('/\s*mb\b/i', 'mb', $normDb);
                        $normDb = preg_replace('/\s*tb\b/i', 'tb', $normDb);
                        
                        $normIncoming = strtolower($incomingVal);
                        $normIncoming = preg_replace('/\s+/', ' ', $normIncoming);
                        $normIncoming = preg_replace('/\s*gb\b/i', 'gb', $normIncoming);
                        $normIncoming = preg_replace('/\s*mb\b/i', 'mb', $normIncoming);
                        $normIncoming = preg_replace('/\s*tb\b/i', 'tb', $normIncoming);
                    }
                    
                    if ($fieldName === 'gpu') {
                        $cleanGpu = function($str) {
                            $str = preg_replace('/microsoft remote display adapter/i', '', $str);
                            $str = preg_replace('/microsoft basic render driver/i', '', $str);
                            $str = preg_replace('/microsoft basic display adapter/i', '', $str);
                            $str = preg_replace('/,\s*,/', ',', $str);
                            return trim($str, " ,");
                        };
                        $normDb = $cleanGpu($normDb);
                        $normIncoming = $cleanGpu($normIncoming);
                    }
                    
                    if ($fieldName === 'monitor') {
                        $cleanMonitor = function($str) {
                            $str = preg_replace('/generic non-pnp monitor/i', '', $str);
                            $str = preg_replace('/generic pnp monitor/i', '', $str);
                            $str = preg_replace('/,\s*,/', ',', $str);
                            return trim($str, " ,");
                        };
                        $normDb = $cleanMonitor($normDb);
                        $normIncoming = $cleanMonitor($normIncoming);
                    }
                    
                    if ($fieldName === 'disk') {
                        $cleanDisk = function($str) {
                            $parts = explode('+', $str);
                            $cleanParts = [];
                            foreach ($parts as $p) {
                                $pTrim = trim($p);
                                if (preg_match('/(usb\s*device|multi-reader|card\s*reader|usb\s*disk|external\s*drive|sd\s*card)/i', $pTrim)) {
                                    continue;
                                }
                                $cleanParts[] = $pTrim;
                            }
                            return implode(' + ', $cleanParts);
                        };
                        $normDbCleaned = $cleanDisk($normDb);
                        $normIncomingCleaned = $cleanDisk($normIncoming);
                        
                        if (strtolower(preg_replace('/\s+/', '', $normDbCleaned)) === strtolower(preg_replace('/\s+/', '', $normIncomingCleaned))) {
                            $normIncoming = $normDb;
                        }
                    }
                    
                    if ($normDb !== $normIncoming && !empty($normDb)) {
                        $hardwareFields = ['cpu', 'ram', 'disk', 'gpu', 'mainboard', 'monitor', 'serial_no', 'os'];
                        if (in_array($fieldName, $hardwareFields, true)) {
                            $hasHardwareChanges = true;
                            
                            $labelMap = [
                                'ram' => ['tr' => 'RAM', 'en' => 'RAM'],
                                'cpu' => ['tr' => 'İşlemci', 'en' => 'CPU'],
                                'ip_address' => ['tr' => 'IP Adresi', 'en' => 'IP Address'],
                                'os' => ['tr' => 'İşletim Sistemi', 'en' => 'OS'],
                                'disk' => ['tr' => 'Disk', 'en' => 'Disk'],
                                'gpu' => ['tr' => 'Ekran Kartı', 'en' => 'GPU'],
                                'serial_no' => ['tr' => 'Seri No', 'en' => 'Serial No'],
                                'name' => ['tr' => 'Cihaz Adı', 'en' => 'Device Name'],
                                'mainboard' => ['tr' => 'Anakart', 'en' => 'Motherboard'],
                                'monitor' => ['tr' => 'Monitör', 'en' => 'Monitor']
                            ];
                            
                            $label = $labelMap[$fieldName] ?? ['tr' => strtoupper($fieldName), 'en' => strtoupper($fieldName)];
                            
                            $hardwareChangesDetailsTr[] = "{$label['tr']}: {$dbVal} → {$incomingVal}";
                            $hardwareChangesDetailsEn[] = "{$label['en']}: {$dbVal} → {$incomingVal}";
                        }
                        return $incomingVal;
                    } elseif ($normDb !== $normIncoming && empty($normDb)) {
                        $hardwareFields = ['cpu', 'ram', 'disk', 'gpu', 'mainboard', 'monitor', 'serial_no', 'os'];
                        if (in_array($fieldName, $hardwareFields, true)) {
                            $hasHardwareChanges = true;
                        }
                        return $incomingVal;
                    }
                    return $dbVal;
                };
 
                $name = $resolveField('name', $name);
                $serial_no = $resolveField('serial_no', $serial_no, 'serial');
                $ip_address = $resolveField('ip_address', $ip_address);
                $mac_address = $resolveField('mac_address', $mac_address, 'mac');
                $ip_secondary = $resolveField('ip_secondary', $ip_secondary);
                $cpu = $resolveField('cpu', $cpu);
                $ram = $resolveField('ram', $ram);
                $disk = $resolveField('disk', $disk);
                $gpu = $resolveField('gpu', $gpu);
                $os = $resolveField('os', $os);
                $monitor = $resolveField('monitor', $monitor);
                $mainboard = $resolveField('mainboard', $mainboard);
                $type = $resolveField('type', $type);
 
                $oldSpecs = json_decode($existingRow['specs'] ?? '', true) ?: [];
                $mergedSpecs = $oldSpecs;
                
                $specsChanged = false;
                $mainColKeys = ['os', 'cpu', 'ram', 'ram_gb', 'disk', 'disk_c_total_gb',
                                'gpu', 'monitor', 'mainboard', 'ip_address', 'mac_address',
                                'ip_secondary', 'name', 'serial_no', 'asset_tag', 'type'];
                if (is_array($specs)) {
                    $translateSpecKey = function($key) {
                        $keyLower = strtolower(trim($key));
                        $map = [
                            'mevcut_guncellemeler' => ['tr' => 'Mevcut Güncellemeler', 'en' => 'Available Updates'],
                            'mevcut guncellemeler' => ['tr' => 'Mevcut Güncellemeler', 'en' => 'Available Updates'],
                            'kullanici' => ['tr' => 'Kullanıcı', 'en' => 'User'],
                            'isletim_sistemi' => ['tr' => 'İşletim Sistemi', 'en' => 'OS'],
                            'ram_boyutu' => ['tr' => 'RAM Boyutu', 'en' => 'RAM Size'],
                            'anakart' => ['tr' => 'Anakart', 'en' => 'Motherboard'],
                            'ekran_karti' => ['tr' => 'Ekran Kartı', 'en' => 'GPU'],
                            'islemci' => ['tr' => 'İşlemci', 'en' => 'CPU'],
                            'disk_boyutu' => ['tr' => 'Disk Boyutu', 'en' => 'Disk Size'],
                            'seri_no' => ['tr' => 'Seri No', 'en' => 'Serial No'],
                            'bilgisayar_adi' => ['tr' => 'Bilgisayar Adı', 'en' => 'Computer Name'],
                        ];
                        if (isset($map[$keyLower])) {
                            return $map[$keyLower];
                        }
                        return ['tr' => $key, 'en' => $key];
                    };

                    foreach ($specs as $key => $newVal) {
                        $keyLower = strtolower(trim($key));
                        $newValStr = trim((string)($newVal ?? ''));
                        
                        if ($newValStr === '') continue; // Skip empty incoming values
                        
                        // Skip Array/object type values (e.g., installed_software arrays)
                        if (is_array($newVal) || is_object($newVal)) continue;
                        if (strtolower($newValStr) === 'array') continue;
                        
                        // Skip keys that map directly to main asset table columns
                        if (in_array($keyLower, $mainColKeys, true)) continue;
                        
                        $found = false;
                        foreach ($oldSpecs as $oldKey => $oldVal) {
                            if (strtolower(trim($oldKey)) === $keyLower) {
                                $found = true;
                                $oldValStr = trim((string)($oldVal ?? ''));
                                
                                $normOld = strtolower($oldValStr);
                                $normOld = preg_replace('/\s+/', ' ', $normOld);
                                $normOld = preg_replace('/\s*gb\b/i', 'gb', $normOld);
                                $normOld = preg_replace('/\s*mb\b/i', 'mb', $normOld);
                                $normOld = preg_replace('/\s*tb\b/i', 'tb', $normOld);
                                
                                $normNew = strtolower($newValStr);
                                $normNew = preg_replace('/\s+/', ' ', $normNew);
                                $normNew = preg_replace('/\s*gb\b/i', 'gb', $normNew);
                                $normNew = preg_replace('/\s*mb\b/i', 'mb', $normNew);
                                $normNew = preg_replace('/\s*tb\b/i', 'tb', $normNew);
                                
                                if ($normOld !== $normNew && !empty($normOld)) {
                                    $specsChanged = true;
                                    $keyTrans = $translateSpecKey($oldKey);
                                    $keyLower = strtolower(trim($oldKey));
                                    if (in_array($keyLower, ['mevcut_guncellemeler', 'mevcut guncellemeler', 'available_updates'], true)) {
                                        $newInt = (int)$newValStr;
                                        if ($newInt > 0) {
                                            $hardwareChangesDetailsTr[] = "Mevcut Güncellemeler: Sunucuda {$newInt} adet paket güncellemesi var, yüklemeniz gerekiyor (Önceki: {$oldValStr})";
                                            $hardwareChangesDetailsEn[] = "Available Updates: {$newInt} package updates available on server, update required (Previous: {$oldValStr})";
                                        } else {
                                            $hardwareChangesDetailsTr[] = "Mevcut Güncellemeler: Sunucudaki tüm paket güncellemeleri tamamlandı (0 bekleyen)";
                                            $hardwareChangesDetailsEn[] = "Available Updates: All package updates completed on server (0 pending)";
                                        }
                                    } else {
                                        $hardwareChangesDetailsTr[] = "{$keyTrans['tr']}: {$oldValStr} → {$newValStr}";
                                        $hardwareChangesDetailsEn[] = "{$keyTrans['en']}: {$oldValStr} → {$newValStr}";
                                    }
                                    $mergedSpecs[$oldKey] = $newVal; // Update key value
                                } elseif ($normOld !== $normNew && empty($normOld)) {
                                    $specsChanged = true;
                                    $keyLower = strtolower(trim($oldKey));
                                    if (in_array($keyLower, ['mevcut_guncellemeler', 'mevcut guncellemeler', 'available_updates'], true)) {
                                        $newInt = (int)$newValStr;
                                        if ($newInt > 0) {
                                            $hardwareChangesDetailsTr[] = "Mevcut Güncellemeler: Sunucuda {$newInt} adet paket güncellemesi var, yüklemeniz gerekiyor";
                                            $hardwareChangesDetailsEn[] = "Available Updates: {$newInt} package updates available on server, update required";
                                        }
                                    }
                                    $mergedSpecs[$oldKey] = $newVal;
                                }
                                break;
                            }
                        }
                        
                        if (!$found) {
                            // Only log new spec if it has a meaningful non-Array value
                            $specsChanged = true;
                            $mergedSpecs[$key] = $newVal; // Add new custom specification silently
                        }
                    }
                }
                
                if ($specsChanged) {
                    $hasHardwareChanges = true;
                }

                $specsJson = json_encode($mergedSpecs);
 
                // Update existing
                $stmtUp = $pdo->prepare("UPDATE assets SET 
                    name = ?, device_name = ?, serial_no = ?, 
                    ip_address = ?, mac_address = ?, ip_secondary = ?,
                    cpu = ?, ram = ?, disk = ?, gpu = ?, os = ?, monitor = ?,
                    mainboard = ?, type = ?, specs = ?, last_api_sync = NOW(), sync_requested = 0 
                    WHERE id = ?");
                $stmtUp->execute([
                    $name, $name, $serial_no, 
                    $ip_address, $mac_address, $ip_secondary,
                    $cpu, $ram, $disk, $gpu, $os, $monitor,
                    $mainboard, $type, $specsJson, $existing
                ]);
 
                // Log timeline and system log events ONLY if real hardware/system changes are detected
                if (!empty($hardwareChangesDetailsTr)) {
                    // Use "Bilgi güncellendi: Güncellenenler:" format so the reports page can split
                    // the description into desc + Changes columns properly
                    $logDesc = 'Bilgi güncellendi: Cihaz API donanım bilgisi güncellendi. Güncellenenler: ' . implode(', ', $hardwareChangesDetailsTr);
                    addAssetLog($pdo, $existing, null, 'timeline_updated', $logDesc);

                    if (function_exists('systemLog')) {
                        systemLog('AGENT_SYNC_SUCCESS', "Cihaz senkronize edildi (Değişiklik Algılandı: " . implode(', ', $hardwareChangesDetailsEn) . "): {$name} (IP: {$ip_address})");
                    }
                }

                // Auto-sync agent_keys registered_by_client_id with asset assigned_user_id if asset is zimmetli
                try {
                    $stmtAssetUser = $pdo->prepare("SELECT assigned_user_id FROM assets WHERE id = ?");
                    $stmtAssetUser->execute([$existing]);
                    $assUserId = $stmtAssetUser->fetchColumn();
                    if ($assUserId && intval($assUserId) > 0) {
                        require_once __DIR__ . '/../../../app/includes/auth_helper.php';
                        $assUserKey = ensureUserApiKey($pdo, intval($assUserId));
                        if (!empty($assUserKey)) {
                            $stmtUpdAgentUserKey = $pdo->prepare("UPDATE agent_keys SET registered_by_client_id = ? WHERE mac_address = ?");
                            $stmtUpdAgentUserKey->execute([$assUserKey, $mac_address]);
                        }
                    }
                } catch (Exception $syncEx) {}

                echo json_encode(['success' => true, 'message' => 'Asset updated successfully', 'asset_id' => $existing]);
            } else {
                // Insert new
                $stmtIns = $pdo->prepare("INSERT INTO assets (
                    name, device_name, asset_tag, serial_no, 
                    ip_address, mac_address, ip_secondary,
                    cpu, ram, disk, gpu, os, monitor,
                    mainboard, type, specs, last_api_sync, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                $stmtIns->execute([
                    $name, $name, $asset_tag, $serial_no, 
                    $ip_address, $mac_address, $ip_secondary,
                    $cpu, $ram, $disk, $gpu, $os, $monitor,
                    $mainboard, $type, json_encode($specs), $nowStr
                ]);
                $newId = $pdo->lastInsertId();

                // Log timeline creation event
                addAssetLog($pdo, $newId, null, 'timeline_created', 'Cihaz API üzerinden eklendi. / Device added via API.');

                if (function_exists('systemLog')) {
                    systemLog('AGENT_SYNC_SUCCESS', "Yeni cihaz senkronize edildi: {$name} (IP: {$ip_address})");
                }

                echo json_encode(['success' => true, 'message' => 'Asset created successfully', 'asset_id' => $newId]);
            }
        } catch (Exception $e) {
            if (function_exists('systemLog')) {
                systemLog('API_ASSET_SYNC_ERROR', 'API Cihaz Senkronizasyon Hatası / API Asset Sync Error: ' . $e->getMessage());
            }
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
} elseif ($endpoint === 'users') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // GET /users
        $stmt = $pdo->prepare("SELECT id, username, fullname, mail, role, status, created_at FROM users WHERE deleted_at IS NULL ORDER BY id DESC");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'users' => $users]);
        exit;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // POST /users (Insert or Sync)
        $input = json_decode(file_get_contents('php://input'), true);
        $username = trim($input['username'] ?? '');
        $fullname = trim($input['fullname'] ?? '');
        $email = trim($input['email'] ?? '');
        $role = intval($input['role'] ?? 2); // Default to Personnel (2)
        $password = $input['password'] ?? '';

        if (empty($username) || empty($fullname) || empty($email)) {
            http_response_code(400);
            echo json_encode(['error' => 'username, fullname, and email are required']);
            exit;
        }

        try {
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ? AND deleted_at IS NULL");
            $stmtCheck->execute([$username]);
            $existing = $stmtCheck->fetchColumn();

            if ($existing) {
                // Update
                $stmtUp = $pdo->prepare("UPDATE users SET fullname = ?, mail = ?, role = ? WHERE id = ?");
                $stmtUp->execute([$fullname, $email, $role, $existing]);
                echo json_encode(['success' => true, 'message' => 'User updated successfully', 'user_id' => $existing]);
            } else {
                // Insert
                $hashedPass = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;
                $stmtIns = $pdo->prepare("INSERT INTO users (username, fullname, password, mail, role, status, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
                $stmtIns->execute([$username, $fullname, $hashedPass, $email, $role]);
                $newId = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'message' => 'User created successfully', 'user_id' => $newId]);
            }
        } catch (Exception $e) {
            if (function_exists('systemLog')) {
                systemLog('API_USER_SYNC_ERROR', 'API Kullanıcı Senkronizasyon Hatası / API User Sync Error: ' . $e->getMessage());
            }
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}

// ENDPOINT NOT FOUND
http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);
exit;


