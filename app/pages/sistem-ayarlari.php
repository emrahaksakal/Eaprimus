<?php
// pages/sistem_ayarlari.php
require_once __DIR__ . '/../includes/session.php';
requireLogin();
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

// Master Admin (role=1) veya Hazır Yanıt yetkisi olan personel/teknik destek
$can_access_canned = hasPermission('canned_responses_access') || in_array((int)($_SESSION['role'] ?? 0), [1, 2, 3]);
if ((int) $_SESSION['role'] !== 1 && !$can_access_canned) {
    include __DIR__ . "/403.php";
    return;
}

// Master Admin değilse sadece Hazır Yanıt sekmesine izin ver
if ((int) $_SESSION['role'] !== 1) {
    $_GET['tab'] = 'canned';
    $active_tab = 'canned';
}

require_once __DIR__ . '/../includes/mailer.php';

if (!isset($base_url)) {
    $base_url = s('site_url');
    if (empty($base_url)) {
        $base_url = '/';
    } else {
        $base_url = rtrim($base_url, '/') . '/';
    }
}


$mesaj = '';
$hata = '';
$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

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
// Ensure agent_keys table exists
try {
    $pdo->query("SELECT 1 FROM agent_keys LIMIT 1");
    try {
        $pdo->query("SELECT registered_by_client_id FROM agent_keys LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE `agent_keys` ADD COLUMN `registered_by_client_id` varchar(64) DEFAULT NULL");
    }
    // Backfill empty registration keys based on current device assignment
    try {
        $pdo->exec("UPDATE agent_keys ak
                    JOIN assets a ON a.mac_address = ak.mac_address
                    JOIN api_keys k ON k.user_id = a.assigned_user_id
                    SET ak.registered_by_client_id = k.client_id
                    WHERE ak.registered_by_client_id IS NULL AND k.revoked_at IS NULL");

        // Fallback: If there is only one active user API key, backfill all NULL records with it
        $singleKey = $pdo->query("SELECT client_id FROM api_keys WHERE revoked_at IS NULL LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if (count($singleKey) === 1) {
            $pdo->prepare("UPDATE agent_keys SET registered_by_client_id = ? WHERE registered_by_client_id IS NULL")->execute([$singleKey[0]]);
        }
        // Cleanup invalid orphan records with empty MAC address
        $pdo->exec("DELETE FROM agent_keys WHERE mac_address IS NULL OR TRIM(mac_address) = ''");
    } catch (Exception $ex) {}
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

// Ensure canned_responses table exists
try {
    $pdo->query("SELECT 1 FROM canned_responses LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `canned_responses` (
            `id` int NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL,
            `category` varchar(100) DEFAULT 'Genel',
            `content` text NOT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $ex) {}
}
try {
    $pdo->query("SELECT user_id FROM canned_responses LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE canned_responses ADD COLUMN user_id INT NULL");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT sharing_type FROM canned_responses LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE canned_responses ADD COLUMN sharing_type VARCHAR(20) NOT NULL DEFAULT 'personal'");
    } catch (Exception $ex) {}
}

try {
    $pdo->query("SELECT team_id FROM canned_responses LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE canned_responses ADD COLUMN team_id INT NULL DEFAULT NULL");
    } catch (Exception $ex) {}
}

// Purge sahipsiz eski şablonlar veya hatalı tanımları düzelt
try {
    $pdo->exec("DELETE FROM canned_responses WHERE user_id IS NULL OR user_id = 0");
    $pdo->exec("UPDATE canned_responses SET sharing_type = 'personal' WHERE sharing_type IS NULL OR sharing_type = ''");
} catch (Exception $e) {}

// Toplu Bildirim ve Duyurular Tabloları Oluşturma
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'info',
        target_role VARCHAR(50) NOT NULL DEFAULT 'all',
        target_team_id INT NULL DEFAULT NULL,
        is_banner TINYINT(1) NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        start_date DATETIME NULL DEFAULT NULL,
        end_date DATETIME NULL DEFAULT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        announcement_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ann_user (announcement_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
} catch (Exception $e) {}

if (isset($_POST['action']) && $_POST['action'] === 'save_single_setting') {

    require_csrf_token();
    $key = $_POST['key'] ?? '';
    $val = $_POST['value'] ?? '';
    if ($key) {
        $stmtU = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmtU->execute([$key, $val]);
        echo json_encode(['status' => 'success']);
    }
    exit;
} elseif (isset($_POST['action']) && $_POST['action'] === 'trigger_agent_sync') {
    require_csrf_token();
    session_write_close();
    $asset_id = intval($_POST['asset_id'] ?? 0);
    if ($asset_id > 0) {
        // Ensure sync_requested exists in assets table
        try {
            $pdo->query("SELECT sync_requested FROM assets LIMIT 1");
        } catch (Exception $e) {
            try {
                $pdo->exec("ALTER TABLE assets ADD COLUMN sync_requested TINYINT(1) DEFAULT 0");
            } catch (Exception $ex) {}
        }

        $stmtSync = $pdo->prepare("UPDATE assets SET sync_requested = 1 WHERE id = ?");
        $stmtSync->execute([$asset_id]);
        echo json_encode([
            'status' => 'success',
            'message' => $isTr
                ? 'Senkronizasyon sinyali gönderildi. Cihaz 60 saniye içinde arka planda otomatik güncellenecektir.'
                : 'Sync signal sent. Device will update automatically in the background within 60 seconds.'
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid asset ID']);
    }
    exit;
} elseif (isset($_POST['action']) && $_POST['action'] === 'check_sync_status') {
    require_csrf_token();
    session_write_close();
    $asset_id = intval($_POST['asset_id'] ?? 0);
    if ($asset_id > 0) {
        $stmtStatus = $pdo->prepare("SELECT last_api_sync, sync_requested FROM assets WHERE id = ?");
        $stmtStatus->execute([$asset_id]);
        $status = $stmtStatus->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'status' => 'success',
            'last_api_sync' => $status['last_api_sync'] ?? '',
            'sync_requested' => intval($status['sync_requested'] ?? 0)
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid asset ID']);
    }
    exit;
} elseif (isset($_POST['action']) && $_POST['action'] === 'fix_agent_api') {
    require_csrf_token();
    if ((int)$_SESSION['role'] !== 1) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    // 1) API'yi her zaman etkinleştir (config.json olsun olmasın)
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('api_enabled','1')
                   ON DUPLICATE KEY UPDATE setting_value='1'")->execute();

    // 2) api_keys tablosunun var olduğundan emin ol
    try { $pdo->query("SELECT 1 FROM api_keys LIMIT 1"); }
    catch (Exception $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `api_keys` (
            `id` int NOT NULL AUTO_INCREMENT,
            `user_id` int NOT NULL DEFAULT 1,
            `client_id` varchar(64) NOT NULL,
            `client_secret_hash` varchar(255) NOT NULL,
            `client_secret_plain` varchar(255) DEFAULT '',
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `revoked_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `client_id` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // 3) Sunucuda config.json varsa (sunucu aynı zamanda client makine ise) key'i de kaydet
    $configPath = 'C:/ProgramData/Eaprimus/config.json';
    $registeredKey = null;
    if (file_exists($configPath)) {
        $config      = json_decode(file_get_contents($configPath), true);
        $agentKey    = trim($config['apiKey']    ?? '');
        $agentSecret = trim($config['apiSecret'] ?? '');
        if (!empty($agentKey) && !empty($agentSecret)) {
            $admin   = $pdo->query("SELECT id FROM users WHERE role = 1 AND deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $adminId = $admin ? (int)$admin['id'] : 1;
            $secretHash = password_hash($agentSecret, PASSWORD_DEFAULT);
            $stmtCheck = $pdo->prepare("SELECT id FROM api_keys WHERE client_id = ? LIMIT 1");
            $stmtCheck->execute([$agentKey]);
            if ($stmtCheck->fetchColumn()) {
                $pdo->prepare("UPDATE api_keys SET client_secret_hash=?, revoked_at=NULL, user_id=? WHERE client_id=?")
                    ->execute([$secretHash, $adminId, $agentKey]);
            } else {
                $pdo->prepare("INSERT INTO api_keys (user_id, client_id, client_secret_hash, revoked_at) VALUES (?,?,?,NULL)")
                    ->execute([$adminId, $agentKey, $secretHash]);
            }
            $registeredKey = $agentKey;
        }
    }

    $msg = $isTr
        ? 'API etkinleştirildi. Artık ajanlı cihazlardan BAT dosyasını çalıştırabilirsiniz.'
        : 'API enabled. You can now run the BAT file on agent devices.';
    if ($registeredKey) {
        $msg .= ($isTr ? ' Ajan anahtarı da kaydedildi: ' : ' Agent key registered: ') . $registeredKey;
    }
    echo json_encode(['status' => 'success', 'message' => $msg]);
    exit;
} elseif (isset($_POST['action']) && $_POST['action'] === 'generate_user_api_key_admin') {
    require_csrf_token();
    if ((int)$_SESSION['role'] !== 1) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    $target_user_id = intval($_POST['user_id'] ?? 0);
    if ($target_user_id > 0) {
        try {
            // Ensure api_keys table exists
            try {
                $pdo->query("SELECT 1 FROM api_keys LIMIT 1");
            } catch (Exception $e) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `api_keys` (
                    `id` int NOT NULL AUTO_INCREMENT,
                    `user_id` int NOT NULL,
                    `client_id` varchar(64) NOT NULL,
                    `client_secret_hash` varchar(255) NOT NULL,
                    `client_secret_plain` varchar(255) NOT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `revoked_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_client_id` (`client_id`),
                    KEY `idx_user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
            }

            // Revoke old
            $stmtRevoke = $pdo->prepare("UPDATE api_keys SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
            $stmtRevoke->execute([$target_user_id]);

            // New keys
            $clientId = 'ea_u_key_' . bin2hex(random_bytes(12));
            $clientSecret = 'ea_u_sec_' . bin2hex(random_bytes(16));
            $secretHash = password_hash($clientSecret, PASSWORD_DEFAULT);

            $stmtInsert = $pdo->prepare("INSERT INTO api_keys (user_id, client_id, client_secret_hash, client_secret_plain) VALUES (?, ?, ?, ?)");
            $stmtInsert->execute([$target_user_id, $clientId, $secretHash, $clientSecret]);

            echo json_encode([
                'status' => 'success',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'message' => $isTr ? 'Kullanıcı API anahtarı başarıyla oluşturuldu.' : 'User API key successfully generated.'
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid user ID']);
    }
    exit;
} elseif (isset($_POST['action']) && $_POST['action'] === 'revoke_user_api_key_admin') {
    require_csrf_token();
    if ((int)$_SESSION['role'] !== 1) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    $target_user_id = intval($_POST['user_id'] ?? 0);
    if ($target_user_id > 0) {
        try {
            $stmtRevoke = $pdo->prepare("UPDATE api_keys SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
            $stmtRevoke->execute([$target_user_id]);
            echo json_encode([
                'status' => 'success',
                'message' => $isTr ? 'Kullanıcı API anahtarı iptal edildi.' : 'User API key successfully revoked.'
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid user ID']);
    }
    exit;
} elseif (isset($_POST['action']) && $_POST['action'] === 'revoke_agent_key_admin') {
    require_csrf_token();
    if ((int)$_SESSION['role'] !== 1) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    $agent_key_id = intval($_POST['agent_key_id'] ?? 0);
    if ($agent_key_id > 0) {
        try {
            $stmtInfo = $pdo->prepare("SELECT computer_name, mac_address FROM agent_keys WHERE id = ?");
            $stmtInfo->execute([$agent_key_id]);
            $agentInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            $stmtRevoke = $pdo->prepare("UPDATE agent_keys SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL");
            $stmtRevoke->execute([$agent_key_id]);

            if ($agentInfo) {
                if (function_exists('systemLog')) {
                    systemLog('AGENT_KEY_REVOKED', "Ajan yetkisi iptal edildi: {$agentInfo['computer_name']} (MAC: {$agentInfo['mac_address']})");
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => $isTr ? 'Ajan API yetkisi iptal edildi.' : 'Agent API access successfully revoked.'
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid agent key ID']);
    }
    exit;
}

// POST İşlemi — PRG Pattern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['section'])) {
    require_csrf_token();
    $section = $_POST['section'];
    $hata_redirect = '';

    try {
        $pdo->beginTransaction();
        $stmtU = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

        // OPcache Sifirla — sadece Super Admin
        if ($section === 'opcache_reset') {
            if ((int)($_SESSION['role'] ?? 0) !== 1) {
                $_SESSION['settings_hata'] = $isTr ? 'Bu işlem için yetkiniz yok.' : 'You do not have permission for this action.';
                header('Location: sistem-ayarlari?tab=site');
                exit;
            }
            if (function_exists('opcache_reset')) {
                opcache_reset();
                $_SESSION['settings_mesaj'] = $isTr ? 'OPcache önbelleği başarıyla temizlendi.' : 'OPcache cleared successfully.';
            } else {
                $_SESSION['settings_hata'] = $isTr ? 'Sunucuda OPcache aktif değil.' : 'OPcache is not enabled on this server.';
            }
            header('Location: sistem-ayarlari?tab=site');
            exit;
        }

        if ($section === 'canned_save') {
            $canned_id = intval($_POST['canned_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $title_en = trim($_POST['title_en'] ?? '');
            $category = trim($_POST['category'] ?? 'Genel');
            $category_en = trim($_POST['category_en'] ?? 'General');
            $content = trim($_POST['content'] ?? '');
            $content_en = trim($_POST['content_en'] ?? '');
            $sharing_type = trim($_POST['sharing_type'] ?? 'personal');
            if (!in_array($sharing_type, ['personal', 'team', 'global'])) {
                $sharing_type = 'personal';
            }
            $team_id = (!empty($_POST['team_id']) && $sharing_type === 'team') ? intval($_POST['team_id']) : null;
            $user_id = $_SESSION['user_id'] ?? 0;
            $role = $_SESSION['role'] ?? 3;

            // Only admin can create global templates
            if ($sharing_type === 'global' && $role !== 1) {
                $sharing_type = 'personal';
            }

            if ($sharing_type === 'team' && empty($team_id)) {
                $_SESSION['settings_hata'] = $isTr ? "Takıma özel şablon için lütfen bir takım seçiniz." : "Please select a team for team-specific template.";
                $pdo->rollBack();
                header("Location: sistem-ayarlari?tab=canned");
                exit;
            }

            if (!empty($title) && !empty($content)) {
                if ($canned_id > 0) {
                    if ($role == 1) {
                        $stmtC = $pdo->prepare("UPDATE canned_responses SET title = ?, title_en = ?, category = ?, category_en = ?, content = ?, content_en = ?, sharing_type = ?, team_id = ? WHERE id = ?");
                        $stmtC->execute([$title, $title_en, $category, $category_en, $content, $content_en, $sharing_type, $team_id, $canned_id]);
                    } else {
                        $stmtC = $pdo->prepare("UPDATE canned_responses SET title = ?, title_en = ?, category = ?, category_en = ?, content = ?, content_en = ?, sharing_type = ?, team_id = ? WHERE id = ? AND user_id = ?");
                        $stmtC->execute([$title, $title_en, $category, $category_en, $content, $content_en, $sharing_type, $team_id, $canned_id, $user_id]);
                    }
                    $_SESSION['settings_mesaj'] = $isTr ? "Hazır yanıt şablonu güncellendi." : "Canned response updated.";
                } else {
                    $is_glob = ($sharing_type === 'global') ? 1 : 0;
                    $stmtC = $pdo->prepare("INSERT INTO canned_responses (title, title_en, category, category_en, content, content_en, user_id, is_global, sharing_type, team_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtC->execute([$title, $title_en, $category, $category_en, $content, $content_en, $user_id, $is_glob, $sharing_type, $team_id]);
                    $_SESSION['settings_mesaj'] = $isTr ? "Yeni hazır yanıt şablonu eklendi." : "New canned response added.";
                }
            } else {
                $_SESSION['settings_hata'] = $isTr ? "Lütfen başlık ve içerik alanlarını doldurun." : "Please fill in title and content.";
            }
            $pdo->commit();
            header("Location: sistem-ayarlari?tab=canned");
            exit;
        } elseif ($section === 'canned_delete') {
            $canned_id = intval($_POST['canned_id'] ?? 0);
            $user_id = $_SESSION['user_id'] ?? 0;
            if ($canned_id > 0) {
                $pdo->prepare("DELETE FROM canned_responses WHERE id = ? AND user_id = ?")->execute([$canned_id, $user_id]);
                $_SESSION['settings_mesaj'] = $isTr ? "Hazır yanıt şablonu silindi." : "Canned response deleted.";
            }
            $pdo->commit();
            header("Location: sistem-ayarlari?tab=canned");
            exit;
        }

        if ($section === 'site') {
            $stmtU->execute(['site_title', trim($_POST['site_title'] ?? '')]);
            $stmtU->execute(['site_description', trim($_POST['site_description'] ?? '')]);
            $stmtU->execute(['site_slogan', trim($_POST['site_slogan'] ?? '')]);
            $stmtU->execute(['company_name', trim($_POST['company_name'] ?? '')]);
            $stmtU->execute(['site_url', rtrim(trim($_POST['site_url'] ?? ''), '/')]);
            $stmtU->execute(['primary_color', trim($_POST['primary_color'] ?? '#1e3c72')]);
            $stmtU->execute(['ticket_prefix', strtoupper(trim($_POST['ticket_prefix'] ?? 'EA-'))]);
            $stmtU->execute(['logo_size_login', trim($_POST['logo_size_login'] ?? '200')]);
            $stmtU->execute(['show_slogan', isset($_POST['show_slogan']) ? '1' : '0']);

            // Logo yükleme
            if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === 0) {
                $upload_dir = __DIR__ . '/../../public/';
                $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'])) {
                    $new_logo_name = 'logo_' . time() . '.' . $ext;
                    $target_file = $upload_dir . $new_logo_name;
                    // Delete old logos
                    foreach (glob($upload_dir . 'logo*.*') as $old_logo) {
                        @unlink($old_logo);
                    }
                    $uploadedTmp = $_FILES['logo_file']['tmp_name'];
                    $gdSuccess = false;

                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp']) && function_exists('imagecreatefromstring')) {
                        $imgData = @file_get_contents($uploadedTmp);
                        if ($imgData) {
                            $srcImg = @imagecreatefromstring($imgData);
                            if ($srcImg) {
                                $srcW = imagesx($srcImg);
                                $srcH = imagesy($srcImg);
                                $maxW = 500; // Optimal max width for documents

                                if ($srcW > $maxW) {
                                    $dstW = $maxW;
                                    $dstH = round(($srcH / $srcW) * $dstW);
                                } else {
                                    $dstW = $srcW;
                                    $dstH = $srcH;
                                }

                                $dstImg = imagecreatetruecolor($dstW, $dstH);
                                imagealphablending($dstImg, false);
                                imagesavealpha($dstImg, true);

                                // Clean background (transparency)
                                $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
                                imagefill($dstImg, 0, 0, $transparent);

                                imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
                                if (imagepng($dstImg, $target_file, 8)) {
                                    $gdSuccess = true;
                                }
                                imagedestroy($srcImg);
                                imagedestroy($dstImg);
                            }
                        }
                    }

                    if (!$gdSuccess) {
                        $gdSuccess = move_uploaded_file($uploadedTmp, $target_file);
                    }
                    if ($gdSuccess || file_exists($target_file)) {
                        $stmtU->execute(['logo_path', $new_logo_name]);
                    } else {
                        $hata_redirect = $isTr ? "Logo kaydedilemedi. Lütfen 'public/' klasörünün yazılabilir (CHMOD 775 veya 777) olduğundan emin olun." : "Failed to save logo. Please ensure 'public/' folder is writable.";
                    }
                } else {
                    $hata_redirect = __("invalid_logo_format");
                }
            }

            // Favicon yükleme
            if (isset($_FILES['favicon_file']) && $_FILES['favicon_file']['error'] === 0) {
                $upload_dir2 = __DIR__ . '/../../public/';
                $ext2 = strtolower(pathinfo($_FILES['favicon_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext2, ['png', 'ico', 'svg'])) {
                    $new_favicon_name = 'favicon_' . time() . '.' . $ext2;
                    $target_file2 = $upload_dir2 . $new_favicon_name;
                    // Delete old favicons
                    foreach (glob($upload_dir2 . 'favicon*.*') as $old_fav) {
                        @unlink($old_fav);
                    }
                    $uploadedTmp2 = $_FILES['favicon_file']['tmp_name'];
                    $gdSuccess2 = false;

                    if (in_array($ext2, ['png', 'ico']) && function_exists('imagecreatefromstring')) {
                        $imgData2 = @file_get_contents($uploadedTmp2);
                        if ($imgData2) {
                            $srcImg2 = @imagecreatefromstring($imgData2);
                            if ($srcImg2) {
                                imagealphablending($srcImg2, false);
                                imagesavealpha($srcImg2, true);
                                if (imagepng($srcImg2, $target_file2, 8)) {
                                    $gdSuccess2 = true;
                                }
                                imagedestroy($srcImg2);
                            }
                        }
                    }

                    if (!$gdSuccess2) {
                        $gdSuccess2 = move_uploaded_file($uploadedTmp2, $target_file2);
                    }
                    if ($gdSuccess2 || file_exists($target_file2)) {
                        $stmtU->execute(['favicon_path', $new_favicon_name]);
                    } else {
                        $hata_redirect = $isTr ? "Favicon kaydedilemedi. Lütfen 'public/' klasörünün yazılabilir olduğundan emin olun." : "Failed to save favicon. Please ensure 'public/' folder is writable.";
                    }
                } else {
                    $hata_redirect = __("invalid_favicon_format");
                }
            }
        } elseif ($section === 'mail') {
            $keys = [
                'mail_host', 'mail_port', 'mail_secure', 'mail_username', 'mail_password',
                'mail_from_address', 'mail_from_name', 'mail_forward_address', 'mail_allowed_domains',
                'mail_block_list', 'mail_max_tickets_per_user_hour', 'mail_max_tickets_total_hour', 'mail_spam_keywords'
            ];
            foreach ($keys as $k) {
                if ($k === 'mail_password') {
                    if (!empty(trim($_POST[$k] ?? ''))) {
                        $stmtU->execute([$k, trim($_POST[$k])]);
                    }
                } else {
                    $stmtU->execute([$k, trim($_POST[$k] ?? '')]);
                }
            }
            // Müşteri Bildirimi Ayarı
            $stmtU->execute(['send_ticket_confirmation_to_customer', isset($_POST['send_ticket_confirmation_to_customer']) ? '1' : '0']);
        } elseif ($section === 'telegram') {
            $stmtU->execute(['telegram_bot_token', trim($_POST['telegram_bot_token'] ?? '')]);
            $stmtU->execute(['telegram_admin_chat_id', trim($_POST['telegram_admin_chat_id'] ?? '')]);
            $stmtU->execute(['webhook_slack_url', trim($_POST['webhook_slack_url'] ?? '')]);
            $stmtU->execute(['webhook_discord_url', trim($_POST['webhook_discord_url'] ?? '')]);
            $stmtU->execute(['webhook_teams_url', trim($_POST['webhook_teams_url'] ?? '')]);

            $tg_new_tr = $_POST['tg_new_ticket_tr_tpl'] ?? '';
            $tg_new_en = $_POST['tg_new_ticket_en_tpl'] ?? '';
            $stmtU->execute(['tg_new_ticket_tr_tpl', $tg_new_tr]);
            $stmtU->execute(['tg_new_ticket_en_tpl', $tg_new_en]);
            $stmtU->execute(['tg_new_ticket_tpl', $tg_new_tr]); // Legacy sync

            $tg_reply_tr = $_POST['tg_reply_ticket_tr_tpl'] ?? '';
            $tg_reply_en = $_POST['tg_reply_ticket_en_tpl'] ?? '';
            $stmtU->execute(['tg_reply_ticket_tr_tpl', $tg_reply_tr]);
            $stmtU->execute(['tg_reply_ticket_en_tpl', $tg_reply_en]);
            $stmtU->execute(['tg_reply_ticket_tpl', $tg_reply_tr]); // Legacy sync

            $tg_resolved_tr = $_POST['tg_resolved_ticket_tr_tpl'] ?? '';
            $tg_resolved_en = $_POST['tg_resolved_ticket_en_tpl'] ?? '';
            $stmtU->execute(['tg_resolved_ticket_tr_tpl', $tg_resolved_tr]);
            $stmtU->execute(['tg_resolved_ticket_en_tpl', $tg_resolved_en]);
            $stmtU->execute(['tg_resolved_ticket_tpl', $tg_resolved_tr]); // Legacy sync

            $stmtU->execute(['tg_assigned_tr_tpl', $_POST['tg_assigned_tr_tpl'] ?? '']);
            $stmtU->execute(['tg_assigned_en_tpl', $_POST['tg_assigned_en_tpl'] ?? '']);

            $stmtU->execute(['tg_status_update_tr_tpl', $_POST['tg_status_update_tr_tpl'] ?? '']);
            $stmtU->execute(['tg_status_update_en_tpl', $_POST['tg_status_update_en_tpl'] ?? '']);
        } elseif ($section === 'api') {
            $api_enabled = isset($_POST['api_enabled']) ? '1' : '0';
            $stmtU->execute(['api_enabled', $api_enabled]);

            $api_agent_auto_register = isset($_POST['api_agent_auto_register']) ? '1' : '0';
            $stmtU->execute(['api_agent_auto_register', $api_agent_auto_register]);

            $api_verify_ssl = isset($_POST['api_verify_ssl']) ? '1' : '0';
            $stmtU->execute(['api_verify_ssl', $api_verify_ssl]);

            $apiKey = s('api_client_id', '-');
            $apiSecret = s('api_client_secret', '-');

            if (isset($_POST['custom_api_client_id']) && !empty(trim($_POST['custom_api_client_id']))) {
                $stmtU->execute(['api_client_id', trim($_POST['custom_api_client_id'])]);
            }

            if (isset($_POST['allowed_upload_extensions'])) {
                $cleanExts = implode(', ', array_filter(array_map('trim', explode(',', strtolower($_POST['allowed_upload_extensions'])))));
                $stmtU->execute(['allowed_upload_extensions', $cleanExts]);
            }

            if (isset($_POST['eaprimus_key']) && !empty(trim($_POST['eaprimus_key']))) {
                $stmtU->execute(['eaprimus_key', trim($_POST['eaprimus_key'])]);
            }

            if ($api_enabled === '1' && ($apiKey === '-' || $apiSecret === '-' || empty($apiKey) || empty($apiSecret))) {
                $api_key = isset($_POST['custom_api_client_id']) && !empty(trim($_POST['custom_api_client_id'])) ? trim($_POST['custom_api_client_id']) : ('ea_key_' . bin2hex(random_bytes(12)));
                $api_secret = 'ea_sec_' . bin2hex(random_bytes(16));
                $stmtU->execute(['api_client_id', $api_key]);
                $stmtU->execute(['api_client_secret', $api_secret]);
                $_SESSION['settings_mesaj'] = $isTr ? "API başarıyla aktif edildi ve yeni anahtarlar oluşturuldu." : "API successfully enabled and new keys generated.";
            } elseif (isset($_POST['regenerate_keys'])) {
                if ($api_enabled === '1') {
                    $api_key = 'ea_key_' . bin2hex(random_bytes(12));
                    $api_secret = 'ea_sec_' . bin2hex(random_bytes(16));
                    $stmtU->execute(['api_client_id', $api_key]);
                    $stmtU->execute(['api_client_secret', $api_secret]);
                    $_SESSION['settings_mesaj'] = $isTr ? "API anahtarları başarıyla yeniden oluşturuldu." : "API keys successfully regenerated.";
                } else {
                    $_SESSION['settings_hata'] = $isTr ? "API girişi aktif edilmeden yeni anahtar oluşturulamaz!" : "Cannot regenerate keys unless API access is enabled!";
                }
            } else {
                $_SESSION['settings_mesaj'] = $api_enabled === '1'
                    ? ($isTr ? "API ayarları kaydedildi." : "API settings saved.")
                    : ($isTr ? "API erişimi devre dışı bırakıldı." : "API access disabled.");
            }
        } elseif ($section === 'status') {
            $pdo->exec("DELETE FROM ticket_statuses");
            $stmtST = $pdo->prepare("INSERT INTO ticket_statuses (id_name, label, color, sort_order, show_on_dashboard) VALUES (?,?,?,?,?)");
            if (isset($_POST['status_key']) && is_array($_POST['status_key'])) {
                $statuses = [];
                $show_keys = $_POST['status_show'] ?? []; // checked olan id_name'leri içerir
                foreach ($_POST['status_key'] as $idx => $key) {
                    if (!empty($key)) {
                        $label = $_POST['status_label'][$idx] ?? $key;
                        $color = $_POST['status_color'][$idx] ?? '#64748b';
                        $show = in_array($key, $show_keys) ? 1 : 0;
                        $stmtST->execute([$key, $label, $color, $idx, $show]);
                        $statuses[$key] = ['label' => $label, 'color' => $color, 'show' => $show];
                    }
                }
                $stmtU->execute(['ticket_statuses_config', json_encode($statuses, JSON_UNESCAPED_UNICODE)]);
            }
        } elseif ($section === 'mailtemplates') {
            if (isset($_POST['mail_default_lang'])) {
                $stmtU->execute(['mail_default_lang', $_POST['mail_default_lang']]);
            }
            foreach ($_POST as $k => $v) {
                if (strpos($k, 'mail_') === 0 && $k !== 'mail_default_lang') {
                    $stmtU->execute([$k, $v]);
                }
            }
        } elseif ($section === 'inventory') {
            $_POST['inv_signature_agreement'] = trim($_POST['inv_signature_agreement_tr'] ?? '');
            $keys = [
                'inv_slogan', 'ticket_slogan', 'inv_title', 'ticket_title',
                'inventory_asset_prefix', 'inventory_license_prefix', 'inventory_accessory_prefix',
                'inventory_consumable_prefix', 'inventory_component_prefix',
                'inventory_low_stock_threshold', 'inventory_audit_warning_days', 'inventory_warranty_warning_days',
                'inv_mail_host', 'inv_mail_port', 'inv_mail_encryption', 'inv_mail_user', 'inv_mail_pass',
                'inv_mail_from_name', 'inv_mail_from_email', 'inv_signature_agreement',
                'inv_signature_agreement_tr', 'inv_signature_agreement_en'
            ];
            foreach ($keys as $k) {
                $stmtU->execute([$k, trim($_POST[$k] ?? '')]);
            }

            $checkboxKeys = [
                'inv_mail_enabled',
                'inventory_checkout_requires_acceptance',
                'inventory_auto_assign_consumables',
                'inventory_enforce_unique_asset_tag',
                'inventory_enable_qr_labels',
            ];
            foreach ($checkboxKeys as $k) {
                $stmtU->execute([$k, isset($_POST[$k]) ? '1' : '0']);
            }
        } elseif ($section === 'ldap') {
            $keys = [
                'ldap_host', 'ldap_port', 'ldap_domain',
                'ldap_base_dn', 'ldap_admin_user',
                'ldap_default_role'
            ];
            foreach ($keys as $k) {
                $stmtU->execute([$k, trim($_POST[$k] ?? '')]);
            }
            // Şifre boş gelmediyse güncelle
            if (!empty($_POST['ldap_admin_pass'])) {
                $stmtU->execute(['ldap_admin_pass', trim($_POST['ldap_admin_pass'])]);
            }
            $stmtU->execute(['ldap_enabled', isset($_POST['ldap_enabled']) ? '1' : '0']);
        } elseif ($section === 'automations_add') {
            $rname = trim($_POST['rule_name'] ?? '');
            $cfield = $_POST['condition_field'] ?? '';
            $cop = $_POST['condition_operator'] ?? '';
            $cval = trim($_POST['condition_value'] ?? '');
            $atype = $_POST['action_type'] ?? '';
            $aval = $_POST['action_value'] ?? '';

            if ($rname && $cfield && $cop && $cval && $atype && $aval) {
                $st = $pdo->prepare("INSERT INTO ticket_rules (rule_name, condition_field, condition_operator, condition_value, action_type, action_value) VALUES (?,?,?,?,?,?)");
                $st->execute([$rname, $cfield, $cop, $cval, $atype, $aval]);
            }
            $_SESSION['settings_mesaj'] = "Kural eklendi.";
            $pdo->commit();
            header("Location: sistem-ayarlari?tab=automations");
            exit;
        } elseif ($section === 'automations_delete') {
            $rid = (int)($_POST['rule_id'] ?? 0);
            if ($rid) {
                $pdo->prepare("DELETE FROM ticket_rules WHERE id = ?")->execute([$rid]);
            }
            $_SESSION['settings_mesaj'] = "Kural silindi.";
            $pdo->commit();
            header("Location: sistem-ayarlari?tab=automations");
            exit;
        }

        if (empty($hata_redirect)) {
            $pdo->commit();
            $_SESSION['settings_mesaj'] = __("success_save");
        } else {
            $pdo->rollBack();
            $_SESSION['settings_hata'] = $hata_redirect;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $_SESSION['settings_hata'] = __("db_error") . ": " . $e->getMessage();
    }

    header("Location: sistem-ayarlari?tab=" . $section);
    exit;
}

// Flash mesajlar
$mesaj = '';
$hata = '';
if (!empty($_SESSION['settings_mesaj'])) {
    $mesaj = $_SESSION['settings_mesaj'];
    unset($_SESSION['settings_mesaj']);
}
if (!empty($_SESSION['settings_hata'])) {
    $hata = $_SESSION['settings_hata'];
    unset($_SESSION['settings_hata']);
}

// Aktif tab
$active_tab = $_GET['tab'] ?? 'site';
if ((int)($_SESSION['role'] ?? 0) !== 1) {
    $active_tab = 'canned';
}

$logo_path = !empty($allSettings['logo_path']) ? $allSettings['logo_path'] : 'logo.png';
$favicon_path = !empty($allSettings['favicon_path']) ? $allSettings['favicon_path'] : 'favicon.png';

if (!file_exists(__DIR__ . '/../../public/' . $logo_path)) {
    $logo_path = 'logo.png';
}
if (!file_exists(__DIR__ . '/../../public/' . $favicon_path)) {
    $favicon_path = 'favicon.png';
}

$logo_file = __DIR__ . '/../../public/' . $logo_path;
$favicon_file = __DIR__ . '/../../public/' . $favicon_path;

$logo_v = file_exists($logo_file) ? filemtime($logo_file) : time();
$favicon_v = file_exists($favicon_file) ? filemtime($favicon_file) : time();

$logo_url = $base_url . $logo_path . '?v=' . $logo_v;
$favicon_url = $base_url . $favicon_path . '?v=' . $favicon_v;
?>

<style>
    .settings-nav {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .settings-nav-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        color: #64748b;
        transition: all .2s;
        cursor: pointer;
        border: none;
        background: none;
        width: 100%;
        text-align: left;
    }

    .settings-nav-item:hover {
        background: #f1f5f9;
        color: #1e293b;
    }

    .settings-nav-item.active {
        background: #eff6ff;
        color: #2563eb;
        font-weight: 600;
    }

    .settings-nav-item i {
        width: 18px;
        text-align: center;
    }

    body.dark-mode .settings-nav-item {
        color: #94a3b8;
    }

    body.dark-mode .settings-nav-item:hover {
        background: #323842;
        color: #e2e8f0;
    }

    body.dark-mode .settings-nav-item.active {
        background: #1e3a5f;
        color: #60a5fa;
    }

    .settings-section {
        display: none;
    }

    .settings-section.active {
        display: block !important;
    }

    .settings-page-header {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        border-radius: 12px;
        padding: 24px 28px;
        color: #fff;
        margin-bottom: 20px;
        box-shadow: 0 4px 20px rgba(30, 60, 114, .3);
    }

    .logo-preview {
        width: 100%;
        max-width: 200px;
        max-height: 80px;
        object-fit: contain;
        border: 2px dashed #e2e8f0;
        border-radius: 8px;
        padding: 8px;
        background: #f8fafc;
    }

    body.dark-mode .logo-preview {
        background: #2e343b;
        border-color: #3a424a;
    }

    .template-card:hover {
        border-color: #2563eb !important;
        transform: translateY(-2px);
    }

    /* Side Drawer */
    .side-drawer {
        position: fixed;
        top: 0;
        right: -800px;
        width: 800px;
        height: 100vh;
        background: #fff;
        box-shadow: -5px 0 25px rgba(0,0,0,0.15);
        z-index: 9999;
        transition: right 0.3s ease;
        display: flex;
        flex-direction: column;
    }

    .side-drawer.open {
        right: 0;
    }

    .drawer-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.3);
        z-index: 9998;
        display: none;
    }

    .drawer-header {
        padding: 20px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f8fafc;
    }

    .drawer-content {
        flex: 1;
        padding: 20px;
        overflow-y: auto;
    }

    .drawer-footer {
        padding: 20px;
        border-top: 1px solid #eee;
        text-align: right;
        background: #f8fafc;
    }

    #template-editor {
        height: 350px;
    }

    body.dark-mode .side-drawer {
        background: #1a1d21;
        color: #eee;
    }
    body.dark-mode .drawer-header,
    body.dark-mode .drawer-footer {
        background: #24282d;
        border-color: #333;
    }
    body.dark-mode .drawer-content {
        background: #1a1d21;
    }
    body.dark-mode .card,
    body.dark-mode .bg-white {
        background: #24282d !important;
        color: #e5e7eb;
        border-color: #3a424a !important;
    }
    body.dark-mode .form-control,
    body.dark-mode .input-group-text,
    body.dark-mode .custom-file-label,
    body.dark-mode .ql-toolbar,
    body.dark-mode .ql-container,
    body.dark-mode .bg-light {
        background: #1f242a !important;
        color: #e5e7eb !important;
        border-color: #3a424a !important;
    }
    body.dark-mode .text-dark,
    body.dark-mode .font-weight-bold,
    body.dark-mode label,
    body.dark-mode h4,
    body.dark-mode h5,
    body.dark-mode h6 {
        color: #f8fafc !important;
    }
    body.dark-mode .text-muted {
        color: #94a3b8 !important;
    }

    /* Telegram Live Preview styles */
    .tg-preview-container {
        background-color: #0e1621;
        border-radius: 8px;
        padding: 12px;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
    }
    .tg-preview-container .tg-preview-title {
        font-size: 11px;
        font-weight: 700;
        color: #8ab4f8;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .tg-message-bubble {
        background-color: #182533;
        color: #f5f5f5;
        border-radius: 10px;
        padding: 10px 14px;
        line-height: 1.5;
        font-size: 13.5px;
        max-width: 100%;
        word-break: break-word;
        white-space: pre-wrap;
        box-shadow: 0 1px 2px rgba(0,0,0,0.15);
        border: 1px solid #202b36;
    }
    .tg-message-bubble a {
        color: #64b5f6 !important;
        text-decoration: underline !important;
    }
    .tg-message-bubble code {
        background-color: rgba(255, 255, 255, 0.1) !important;
        color: #f28c8c !important;
        padding: 2px 4px !important;
        border-radius: 4px !important;
        font-family: Consolas, Monaco, 'Andale Mono', 'Ubuntu Mono', monospace !important;
        font-size: 90% !important;
    }
    .tg-message-bubble pre {
        background-color: rgba(0, 0, 0, 0.3) !important;
        border: 1px solid rgba(255, 255, 255, 0.1) !important;
        padding: 8px !important;
        border-radius: 6px !important;
        color: #fff !important;
        font-family: Consolas, Monaco, 'Andale Mono', 'Ubuntu Mono', monospace !important;
        margin: 4px 0 !important;
        white-space: pre-wrap !important;
    }

    /* Custom alerts with dark-mode compatibility */
    .alert-custom-success {
        background: #f0fdf4 !important;
        color: #166534 !important;
        border: none !important;
        border-radius: 12px !important;
    }
    .alert-custom-success h6,
    .alert-custom-success strong,
    .alert-custom-success p,
    .alert-custom-success div,
    .alert-custom-success span,
    .alert-custom-success i {
        color: #166534 !important;
    }

    body.dark-mode .alert-custom-success {
        background: rgba(16, 185, 129, 0.1) !important;
        color: #34d399 !important;
        border: 1px solid rgba(16, 185, 129, 0.2) !important;
    }
    body.dark-mode .alert-custom-success h6,
    body.dark-mode .alert-custom-success strong,
    body.dark-mode .alert-custom-success p,
    body.dark-mode .alert-custom-success div,
    body.dark-mode .alert-custom-success span,
    body.dark-mode .alert-custom-success i {
        color: #34d399 !important;
    }

    .alert-custom-info {
        background: #eff6ff !important;
        color: #1e40af !important;
        border: none !important;
        border-radius: 12px !important;
    }
    .alert-custom-info h6,
    .alert-custom-info strong,
    .alert-custom-info p,
    .alert-custom-info div,
    .alert-custom-info span,
    .alert-custom-info i {
        color: #1e40af !important;
    }

    body.dark-mode .alert-custom-info {
        background: rgba(59, 130, 246, 0.1) !important;
        color: #60a5fa !important;
        border: 1px solid rgba(59, 130, 246, 0.2) !important;
    }
    body.dark-mode .alert-custom-info h6,
    body.dark-mode .alert-custom-info strong,
    body.dark-mode .alert-custom-info p,
    body.dark-mode .alert-custom-info div,
    body.dark-mode .alert-custom-info span,
    body.dark-mode .alert-custom-info i {
        color: #60a5fa !important;
    }
</style>

<script>
    function switchTab(tab, el) {
        document.querySelectorAll('.settings-section').forEach(s => {
            s.classList.remove('active');
            s.style.display = 'none';
        });
        document.querySelectorAll('.settings-nav-item').forEach(b => b.classList.remove('active'));
        const sect = document.getElementById('section-' + tab);
        if(sect) {
            sect.classList.add('active');
            sect.style.display = 'block';
        }
        if(el) el.classList.add('active');
        history.replaceState(null, '', 'sistem-ayarlari?tab=' + tab);

        if (tab === 'desktop_notif') {
            const savedSound = localStorage.getItem('eaprimus_sound') || 'chime';
            const sel = document.getElementById('soundToneSelect');
            if (sel) sel.value = savedSound;
        }

        const updateLangLink = (id) => {
            const link = document.getElementById(id);
            if (link) {
                let href = link.getAttribute('href');
                if (href) {
                    if (href.includes('tab=')) {
                        href = href.replace(/([?&])tab=[^&]*/, '$1tab=' + tab);
                    } else {
                        href += (href.includes('?') ? '&' : '?') + 'tab=' + tab;
                    }
                    link.setAttribute('href', href);
                }
            }
        };
        updateLangLink('lang-link-tr');
        updateLangLink('lang-link-en');
    }

    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const isControlAdmin = <?= (int)($_SESSION['role'] ?? 0) === 1 ? 'true' : 'false' ?>;
        const defaultTab = isControlAdmin ? 'site' : 'canned';
        let tab = urlParams.get('tab') || defaultTab;
        if (!isControlAdmin && tab !== 'canned') {
            tab = 'canned';
        }
        const tabEl = document.querySelector(`.settings-nav-item[onclick*="'${tab}'"]`);
        if (tabEl) {
            switchTab(tab, tabEl);
        } else {
            const fallbackEl = document.querySelector(`.settings-nav-item[onclick*="'${defaultTab}'"]`);
            switchTab(defaultTab, fallbackEl);
        }
    });
</script>

<!-- Quill CSS -->
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<div class="content-header">
    <div class="container-fluid">
        <div class="settings-page-header">
            <h1 class="mb-1" style="color:#fff; font-size:22px;"><i class="fas fa-cog mr-2"></i><?= __("settings") ?>
            </h1>
            <p class="mb-0" style="opacity:.75; font-size:14px;"><?= __("system_settings_desc") ?></p>
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
        <?php if ($hata): ?>
            <div class="alert alert-danger shadow-sm"><i
                    class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($hata) ?></div>
        <?php endif; ?>

        <div class="row">
            <!-- SOL: NAV -->
            <div class="col-md-3">
                <div class="card shadow-sm" style="border-radius:12px; border:none; position:sticky; top:80px;">
                    <div class="card-body py-3 px-3">
                        <nav class="settings-nav">
                            <?php if ((int)($_SESSION['role'] ?? 0) === 1): ?>
                            <button type="button" class="settings-nav-item <?= $active_tab === 'site' ? 'active' : '' ?>" onclick="switchTab('site', this)">
                                <i class="fas fa-globe"></i> <?= __("site_brand") ?>
                            </button>
                            <button type="button" class="settings-nav-item <?= $active_tab === 'mail' ? 'active' : '' ?>" onclick="switchTab('mail', this)">
                                <i class="fas fa-envelope"></i> <?= __("email_smtp") ?>
                            </button>
                            <button type="button" class="settings-nav-item <?= $active_tab === 'telegram' ? 'active' : '' ?>" onclick="switchTab('telegram', this)">
                                <i class="fas fa-bell"></i> <?= $isTr ? 'Anlık Bildirimler (Webhook)' : 'Instant Alerts (Webhook)' ?>
                            </button>
                            <?php endif; ?>

                            <button type="button" class="settings-nav-item <?= $active_tab === 'canned' ? 'active' : '' ?>" onclick="switchTab('canned', this)">
                                <i class="fas fa-bolt text-warning"></i> <?= $isTr ? 'Hazır Yanıt Şablonları' : 'Canned Responses' ?>
                            </button>

                            <?php if ((int)($_SESSION['role'] ?? 0) === 1): ?>
                            <button type="button" class="settings-nav-item <?= $active_tab === 'desktop_notif' ? 'active' : '' ?>" onclick="switchTab('desktop_notif', this)">
                                <i class="fas fa-desktop text-info"></i> <?= $isTr ? 'Masaüstü & Ses Ayarları' : 'Desktop & Audio' ?>
                            </button>
                            <button type="button" class="settings-nav-item <?= $active_tab === 'status' ? 'active' : '' ?>" onclick="switchTab('status', this)">
                                <i class="fas fa-tags"></i> <?= __("ticket_statuses") ?>
                            </button>
                            <button type="button" class="settings-nav-item <?= $active_tab === 'mailtemplates' ? 'active' : '' ?>" onclick="switchTab('mailtemplates', this)">
                                <i class="fas fa-file-code"></i> <?= __("mail_templates") ?>
                            </button>
                            <button type="button" class="settings-nav-item <?= $active_tab === 'personel' ? 'active' : '' ?>" onclick="switchTab('personel', this)">
                                <i class="fas fa-users-cog"></i> <?= __("personnel_assign_settings") ?>
                            </button>
                            <button type="button" class="settings-nav-item <?= $active_tab === 'inventory' ? 'active' : '' ?>" onclick="switchTab('inventory', this)">
                                <i class="fas fa-boxes"></i> <?= __("inventory_settings") ?>
                            </button>
                            <button type="button" class="settings-nav-item <?= $active_tab === 'automations' ? 'active' : '' ?>" onclick="switchTab('automations', this)">
                                <i class="fas fa-robot"></i> <?= $isTr ? 'Otomasyon Kuralları' : 'Automation Rules' ?>
                            </button>
                            <button type="button" class="settings-nav-item <?= $active_tab === 'ldap' ? 'active' : '' ?>" onclick="switchTab('ldap', this)">
                                <i class="fas fa-network-wired"></i> <?= $isTr ? 'LDAP / Active Directory' : 'LDAP / Active Directory' ?>
                            </button>
                            <button type="button" class="settings-nav-item <?= $active_tab === 'api' ? 'active' : '' ?>" onclick="switchTab('api', this)">
                                <i class="fas fa-code"></i> <?= $isTr ? 'API Entegrasyonu' : 'API Integration' ?>
                            </button>
                            <?php endif; ?>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- SAĞ: İÇERİK -->
            <div class="col-md-9">

                <!-- --- SITE AYARLARI --- -->
                <div class="settings-section <?= $active_tab === 'site' ? 'active' : '' ?>" id="section-site">
                    <form method="POST" action="anasayfa?route=sistem-ayarlari" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="section" value="site">
                        <div class="card shadow-sm" style="border-radius:12px; border:none;">
                            <div class="card-header"
                                style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                                <h4 class="mb-0 font-weight-bold"><i
                                        class="fas fa-paint-brush mr-2 text-primary"></i><?= __("site_brand_settings") ?>
                                </h4>
                            </div>
                            <div class="card-body px-4 py-4">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("browser_tab_title") ?></label>
                                            <input type="text" name="site_title" class="form-control"
                                                value="<?= s('site_title', __("site_title_default")) ?>"
                                                placeholder="<?= __("browser_tab_title_placeholder") ?>">
                                            <small class="text-muted"><?= __("browser_tab_title_hint") ?></small>
                                        </div>
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("tab_subtitle_slogan") ?></label>
                                            <input type="text" name="site_description" class="form-control"
                                                value="<?= s('site_description') ?>"
                                                placeholder="">
                                            <small class="text-muted"><?= __("browser_tab") ?>:
                                                <strong><?= s('site_title', __("site_title_default")) ?></strong> |
                                                <em><?= __("tab_subtitle") ?></em></small>
                                        </div>
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("company") ?></label>
                                            <input type="text" id="company_name" name="company_name" class="form-control"
                                                value="<?= s('company_name') ?>"
                                                placeholder="<?= __("company_name_placeholder") ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("site_url") ?></label>
                                            <input type="url" name="site_url" class="form-control"
                                                value="<?= s('site_url') ?>" placeholder="https://destek.sirket.com">
                                            <small class="form-text text-muted mt-1">
                                              <?= $isTr
                                              ? 'Bu adres; giden e-postalardaki (aktivasyon, şifre sıfırlama) ve Telegram bildirimlerindeki yönlendirme bağlantılarının doğru çalışması için kullanılır. Panelinizin erişilebilir olduğu URL\'yi girin. <br><small><strong>Örnek:</strong> <strong>http://192.168.1.100/</strong> veya <strong>https://domain.com</strong></small>'
                                              : 'This URL is used to generate links in outgoing emails (activation, password reset) and Telegram notifications. Enter the URL where your panel is accessible. <br><small><strong>Example:</strong> <strong>http://192.168.1.100</strong> or <strong>https://domain.com</strong></small>'
                                                  ?>
                                            </small>
                                        </div>
                                        <div class="form-group">
                                            <label class="font-weight-bold"><i class="fas fa-palette mr-1"></i> <?= __("primary_color") ?></label>
                                            <div class="d-flex align-items-center gap-3">
                                                <input type="color" name="primary_color" class="form-control" style="width: 60px; height: 38px; padding: 2px;" value="<?= s('primary_color', '#1e3c72') ?>">
                                                <input type="text" class="form-control" value="<?= s('primary_color', '#1e3c72') ?>" readonly style="width: 100px; background: #f8fafc;">
                                                 <small class="text-muted"><?= __("primary_color_hint") ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("ticket_prefix") ?></label>
                                            <input type="text" name="ticket_prefix" class="form-control"
                                                value="<?= s('ticket_prefix', 'EA-') ?>" placeholder="EA-"
                                                maxlength="5">
                                            <small class="text-muted"><?= __("ticket_prefix_hint") ?></small>
                                        </div>
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("login_screen_slogan") ?></label>
                                            <input type="text" name="site_slogan" class="form-control"
                                                value="<?= s('site_slogan', 'Hızlı ve Güvenilir Destek') ?>"
                                                placeholder="Slogan yazın...">
                                            <small class="text-muted"><?= __("login_slogan_hint") ?></small>
                                            <div class="custom-control custom-switch mt-2">
                                                <input type="checkbox" name="show_slogan" class="custom-control-input" id="sw_show_slogan" <?= s('show_slogan') == '1' ? 'checked' : '' ?>>
                                                <label class="custom-control-label font-weight-normal" for="sw_show_slogan"><?= __('show_slogan') ?></label>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __('logo_size_login') ?></label>
                                            <div class="input-group">
                                                <input type="number" name="logo_size_login" class="form-control" value="<?= s('logo_size_login', '200') ?>">
                                                <div class="input-group-append"><span class="input-group-text">px</span></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <hr>
                                <div class="row">
                                    <!-- Logo -->
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><i
                                                    class="fas fa-image mr-1"></i><?= __("site_logo") ?></label>
                                            <div class="mb-2">
                                                <img id="logo_preview" src="<?= $logo_url ?>" alt="Logo"
                                                    class="logo-preview">
                                            </div>
                                            <div class="custom-file">
                                                <input type="file" class="custom-file-input" id="logo_file"
                                                    name="logo_file" accept=".png,.jpg,.jpeg,.svg,.webp"
                                                    onchange="previewLogo(this)">
                                                <label class="custom-file-label"
                                                    for="logo_file"><?= __("select_logo_placeholder") ?></label>
                                            </div>
                                            <small class="text-muted d-block mt-1"><?= __("logo_hint") ?></small>
                                        </div>
                                    </div>
                                    <!-- Favicon -->
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><i
                                                    class="fas fa-star mr-1"></i><?= __("favicon") ?></label>
                                            <div class="mb-2 d-flex align-items-center gap-2">
                                                <img id="favicon_preview" src="<?= $favicon_url ?>" alt="Favicon"
                                                    width="32" height="32"
                                                    style="border-radius:4px; border:1px solid #eee;">
                                                <small class="text-muted"><?= __("favicon_hint") ?></small>
                                            </div>
                                            <div class="custom-file">
                                                <input type="file" class="custom-file-input" id="favicon_file"
                                                    name="favicon_file" accept=".png,.ico,.svg"
                                                    onchange="previewFavicon(this)">
                                                <label class="custom-file-label"
                                                    for="favicon_file"><?= __("select_favicon_placeholder") ?></label>
                                            </div>
                                            <small
                                                class="text-muted d-block mt-1"><?= __("favicon_size_hint") ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer text-right"
                                style="background:transparent; border-top:1px solid #eee;">
                                <button type="submit" class="btn btn-primary px-4 shadow-sm">
                                    <i class="fas fa-save mr-1"></i><?= __("save_site_settings") ?>
                                </button>
                            </div>
                        </div>
                    </form>

                    <?php if ((int)($_SESSION['role'] ?? 0) === 1): ?>
                    <!-- OPcache Kart -->
                    <div class="card shadow-sm mb-4" style="border-radius:12px; border:none;">
                        <div class="card-header bg-white" style="border-bottom:1px solid #f0f0f0; padding:20px 24px;">
                            <h5 class="mb-0 font-weight-bold ml-1">
                                <i class="fas fa-memory mr-2 text-warning"></i>
                                <?= $isTr ? 'Sunucu Önbelleği (OPcache)' : 'Server Cache (OPcache)' ?>
                            </h5>
                        </div>
                        <div class="card-body px-4 py-4">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <p class="mb-1">
                                        <?= $isTr
                                            ? 'PHP OPcache, sunucunun PHP dosyalarını derlenmiş halde bellekte tutar ve performansı artırır. Kod güncellemelerinin hemen yansıması için önbelleği temizleyebilirsiniz.'
                                            : 'PHP OPcache keeps compiled PHP files in memory for better performance. Clear the cache to immediately apply code updates.'
                                        ?>
                                    </p>
                                    <small class="text-muted">
                                        <?php if (function_exists('opcache_get_status') && opcache_get_status()): ?>
                                            <span class="badge badge-success">
                                                <i class="fas fa-check-circle mr-1"></i><?= $isTr ? 'OPcache Aktif' : 'OPcache Active' ?>
                                            </span>
                                            <?php $st = opcache_get_status(); ?>
                                            &nbsp;<?= $isTr ? 'Önbellekteki dosya:' : 'Cached files:' ?>
                                            <strong><?= number_format($st['opcache_statistics']['num_cached_scripts'] ?? 0) ?></strong>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">
                                                <i class="fas fa-times-circle mr-1"></i><?= $isTr ? 'OPcache Aktif Değil' : 'OPcache Inactive' ?>
                                            </span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="col-md-4 text-right">
                                    <form method="POST" action="anasayfa?route=sistem-ayarlari" id="opcache-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="section" value="opcache_reset">
                                        <button type="button" class="btn btn-warning px-4" onclick="confirmOpcacheReset()" <?= !function_exists('opcache_reset') ? 'disabled title="OPcache not available"' : '' ?>>
                                            <i class="fas fa-sync-alt mr-2"></i><?= $isTr ? 'Önbelleği Temizle' : 'Clear Cache' ?>
                                        </button>
                                    </form>
                                    <script>
                                        function confirmOpcacheReset() {
                                            Swal.fire({
                                                title: '<?= $isTr ? "Emin misiniz?" : "Are you sure?" ?>',
                                                text: '<?= $isTr ? "OPcache önbelleği temizlenecek. Bu işlem sunucu performansını geçici olarak etkileyebilir." : "OPcache will be cleared. This may temporarily affect server performance." ?>',
                                                icon: 'warning',
                                                showCancelButton: true,
                                                confirmButtonColor: '#f59e0b',
                                                cancelButtonColor: '#6c757d',
                                                confirmButtonText: '<?= $isTr ? "Evet, Temizle!" : "Yes, Clear it!" ?>',
                                                cancelButtonText: '<?= $isTr ? "İptal" : "Cancel" ?>',
                                                background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                                                color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
                                            }).then((result) => {
                                                if (result.isConfirmed) {
                                                    document.getElementById('opcache-form').submit();
                                                }
                                            });
                                        }
                                    </script>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
                <!-- --- MAIL AYARLARI --- -->
                <div class="settings-section <?= $active_tab === 'mail' ? 'active' : '' ?>" id="section-mail">
                    <form method="POST" action="anasayfa?route=sistem-ayarlari">
                        <?= csrf_field() ?>
                        <input type="hidden" name="section" value="mail">

                        <div class="card shadow-sm mb-4" style="border-radius:12px; border:none;">
                            <div class="card-header bg-white" style="border-bottom:1px solid #f0f0f0; padding:20px 24px;">
                                <h5 class="mb-0 font-weight-bold ml-1"><i class="fas fa-paper-plane mr-2 text-primary"></i><?= __("email_smtp_settings") ?></h5>
                            </div>
                            <div class="card-body px-4 py-4">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("smtp_host") ?></label>
                                            <input type="text" name="mail_host" class="form-control" value="<?= s('mail_host') ?>" placeholder="smtp.gmail.com">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("port") ?></label>
                                            <input type="text" name="mail_port" class="form-control" value="<?= s('mail_port', '587') ?>" placeholder="587">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("security") ?></label>
                                            <select name="mail_secure" class="form-control">
                                                <option value="tls" <?= s('mail_secure') == 'tls' ? 'selected' : '' ?>>TLS</option>
                                                <option value="ssl" <?= s('mail_secure') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                                                <option value="none" <?= s('mail_secure') == 'none' ? 'selected' : '' ?>><?= __("none") ?></option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("smtp_username") ?></label>
                                            <input type="email" name="mail_username" class="form-control" value="<?= s('mail_username') ?>" placeholder="destek@sirket.com" autocomplete="new-password">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= $isTr ? 'Şifre' : 'Password' ?></label>
                                            <div class="input-group font-password-toggle">
                                                <?php $has_mail_pass = s('mail_password') !== ''; ?>
                                                <input type="password" name="mail_password" class="form-control" value="<?= htmlspecialchars((string)s('mail_password')) ?>" placeholder="" autocomplete="new-password">
                                                <div class="input-group-append">
                                                    <span class="input-group-text" style="cursor:pointer;" onclick="togglePassword(this, <?= $isTr ? 'true' : 'false' ?>)">
                                                        <i class="fas fa-eye"></i>
                                                        <?php if ($has_mail_pass): ?>
                                                            <span class="ms-1 ml-1 password-toggle-text"><?= $isTr ? 'Göster' : 'Show' ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("sender_name") ?></label>
                                            <input type="text" name="mail_from_name" class="form-control" value="<?= s('mail_from_name') ?>" placeholder="Eaprimus Destek">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("sender_email") ?></label>
                                            <input type="email" name="mail_from_address" class="form-control" value="<?= s('mail_from_address') ?>" placeholder="destek@sirket.com">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card shadow-sm" style="border-radius:12px; border:none;">
                            <div class="card-header bg-white" style="border-bottom:1px solid #f0f0f0; padding:20px 24px;">
                                <h5 class="mb-0 font-weight-bold ml-1"><i class="fas fa-inbox mr-2 text-primary"></i><?= __("imap_forward_address") ?></h5>
                            </div>
                            <div class="card-body px-4 py-4">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("imap_forward_address") ?></label>
                                            <input type="email" name="mail_forward_address" class="form-control" value="<?= s('mail_forward_address') ?>" placeholder="imap@sirket.com">
                                            <small class="text-muted d-block mt-1"><?= __("imap_forward_hint") ?></small>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group mt-3">
                                    <label class="font-weight-bold">İzin Verilen Mail Alan Adları</label>
                                    <input type="text" name="mail_allowed_domains" class="form-control" value="<?= s('mail_allowed_domains') ?>" placeholder="@sirket.com, @gmail.com">
                                    <small class="text-muted">Sadece bu alan adlarından gelen mailler bilet olarak açılır. Boş bırakırsanız her yerden kabul edilir. (Örn: @sirket.com, @gmail.com)</small>
                                </div>
                                <div class="alert alert-info mt-4 mb-0" style="border-radius:8px; font-size:13.5px; border:none; background: #eef2f7; color: #475569;">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-info-circle mr-3 fa-2x text-primary"></i>
                                        <div>
                                            <strong><?= __("test") ?>:</strong> <?= __("mail_test_hint") ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12 text-muted small">
                                        <i class="fas fa-signature mr-1 text-primary"></i>
                                        <strong>Mail İmzası:</strong>
                                        Mail gönderilirken her personelin kendi profilindeki imzası kullanılır.
                                        <a href="<?= $base_url ?>anasayfa?route=profil_duzenle" target="_blank"
                                            style="font-weight:600;">Profil Ayarları</a>
                                        sekmesinden imzanızı düzenleyebilirsiniz.
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer text-right bg-white" style="border-top:1px solid #f0f0f0;">
                                <button type="submit" class="btn btn-primary px-5 py-2 shadow-sm font-weight-bold">
                                    <i class="fas fa-save mr-1"></i><?= __("save_mail_settings") ?>
                                </button>
                            </div>
                        </div>

                        <!-- SPAM KORUMASI -->
                        <div class="card shadow-sm mt-4" id="spam-protection-section" style="border-radius:12px; border:none;">
                            <div class="card-header bg-white" style="border-bottom:1px solid #f0f0f0; padding:20px 24px;">
                                <h5 class="mb-0 font-weight-bold ml-1"><i class="fas fa-shield-alt mr-2 text-danger"></i><?= __("spam_protection") ?></h5>
                            </div>
                            <div class="card-body px-4 py-4">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("mail_block_list") ?></label>
                                            <textarea name="mail_block_list" class="form-control" rows="3" placeholder="spam@example.com, @spammer.com"><?= s('mail_block_list') ?></textarea>
                                            <small class="text-muted"><?= __("mail_block_list_hint") ?></small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("mail_spam_keywords") ?></label>
                                            <textarea name="mail_spam_keywords" class="form-control" rows="3" placeholder="viagra, casino, jackpot"><?= s('mail_spam_keywords') ?></textarea>
                                            <small class="text-muted"><?= __("mail_spam_keywords_hint") ?></small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("max_tickets_per_user_hour") ?></label>
                                            <input type="number" name="mail_max_tickets_per_user_hour" class="form-control" value="<?= s('mail_max_tickets_per_user_hour', '5') ?>">
                                            <small class="text-muted"><?= __("max_tickets_per_user_hour_hint") ?></small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold"><?= __("max_tickets_total_hour") ?></label>
                                            <input type="number" name="mail_max_tickets_total_hour" class="form-control" value="<?= s('mail_max_tickets_total_hour', '100') ?>">
                                            <small class="text-muted"><?= __("max_tickets_total_hour_hint") ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer d-flex justify-content-between align-items-center" style="background:transparent; border-top:1px solid #f0f0f0;">
                                <a href="anasayfa?route=mail-spam-logs" class="btn btn-outline-danger btn-sm px-3 shadow-sm" style="border-radius:10px;">
                                    <i class="fas fa-history mr-1"></i> <?= __("blocked_mail_logs") ?>
                                </a>
                                <button type="submit" class="btn btn-primary px-4 shadow-sm">
                                    <i class="fas fa-save mr-1"></i><?= __("save_mail_settings") ?>
                                </button>
                            </div>
                        </div>


                    </form>
                </div>
                <!-- --- TELEGRAM AYARLARI --- -->
                <div class="settings-section <?= $active_tab === 'telegram' ? 'active' : '' ?>" id="section-telegram">
                    <form method="POST" action="anasayfa?route=sistem-ayarlari">
                        <?= csrf_field() ?>
                        <input type="hidden" name="section" value="telegram">
                        <div class="card shadow-sm" style="border-radius:12px; border:none;">
                            <div class="card-header" style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                                <h4 class="mb-0 font-weight-bold"><i class="fas fa-bell mr-2 text-primary"></i><?= $isTr ? 'Anlık Bildirim ve Webhook Ayarları' : 'Instant Alerts & Webhooks' ?></h4>
                            </div>
                <div class="card-body px-4 py-4">
                                <p class="text-muted small mb-4"><?= __('inventory_mail_settings_hint') ?></p>
                                <h5 class="mb-3 font-weight-bold"><i class="fab fa-telegram-plane mr-2 text-primary"></i> Telegram Bot Ayarları</h5>
                                <div class="form-group">
                                    <label class="font-weight-bold"><?= __("bot_token") ?></label>
                                    <input type="text" name="telegram_bot_token" class="form-control" value="<?= s('telegram_bot_token') ?>" placeholder="123456789:ABCDefGhIklmNoPQRstUvWxYz">
                                    <small class="text-muted"><?= __("tg_bot_token_hint") ?></small>
                                </div>
                                <div class="form-group">
                                    <label class="font-weight-bold"><?= __("admin_chat_id") ?></label>
                                    <input type="text" name="telegram_admin_chat_id" class="form-control" value="<?= s('telegram_admin_chat_id') ?>" placeholder="123456789">
                                    <small class="text-muted"><?= __("tg_admin_chat_id_hint") ?></small>
                                </div>

                                <hr class="my-4">
                                <h5 class="mb-3 font-weight-bold"><i class="fas fa-link mr-2 text-primary"></i> <?= $isTr ? 'Slack, Discord ve Teams Webhook Kanalları' : 'Slack, Discord & Teams Webhook Channels' ?></h5>

                                <div class="form-group">
                                    <label class="font-weight-bold">Slack Webhook URL</label>
                                    <input type="text" name="webhook_slack_url" class="form-control" value="<?= s('webhook_slack_url') ?>" placeholder="https://hooks.slack.com/services/...">
                                    <small class="text-muted"><?= $isTr ? 'Destek talepleri oluştukça bu Slack kanalına anlık bildirim gider.' : 'Instant alerts are sent to this Slack channel when new tickets are created.' ?></small>
                                </div>
                                <div class="form-group">
                                    <label class="font-weight-bold">Discord Webhook URL</label>
                                    <input type="text" name="webhook_discord_url" class="form-control" value="<?= s('webhook_discord_url') ?>" placeholder="https://discord.com/api/webhooks/...">
                                    <small class="text-muted"><?= $isTr ? 'Destek talepleri oluştukça bu Discord kanalına anlık bildirim gider.' : 'Instant alerts are sent to this Discord channel when new tickets are created.' ?></small>
                                </div>
                                <div class="form-group">
                                    <label class="font-weight-bold">Microsoft Teams Webhook URL</label>
                                    <input type="text" name="webhook_teams_url" class="form-control" value="<?= s('webhook_teams_url') ?>" placeholder="https://outlook.office.com/webhook/...">
                                    <small class="text-muted"><?= $isTr ? 'Destek talepleri oluştukça bu Teams kanalına anlık bildirim gider.' : 'Instant alerts are sent to this Teams channel when new tickets are created.' ?></small>
                                </div>


                                <hr class="my-4">
                                <h5 class="mb-3 font-weight-bold"><i class="fas fa-file-code mr-2 text-primary"></i> Telegram Bildirim Şablonları</h5>

                                <!-- Template 1: Yeni Destek Talebi -->
                                <div class="card bg-light border-0 mb-4" style="border-radius:10px;">
                                    <div class="card-body p-3">
                                        <h6 class="font-weight-bold mb-2 text-dark"><i class="fas fa-plus-circle mr-1 text-primary"></i> 1. Yeni Destek Talebi Şablonu (HTML)</h6>
                                        <div class="row">
                                            <div class="col-md-6 form-group mb-2">
                                                <label class="small font-weight-bold text-muted mb-1">Türkçe Şablon (TR)</label>
                                                <textarea name="tg_new_ticket_tr_tpl" class="form-control form-control-sm" rows="6" placeholder="TR şablonu..."><?= s('tg_new_ticket_tr_tpl', s('tg_new_ticket_tpl', "🔔 <b>YENİ DESTEK TALEBİ</b>\n\n📌 <b>Konu:</b> {{subject}}\n🔖 <b>Bilet No:</b> <code>{{ticket_no}}</code>\n⚡ <b>Öncelik:</b> {{priority}}\n📂 <b>Kuyruk:</b> {{queue}}\n👤 <b>Talep Eden:</b> {{user_name}}\n🧑‍💻 <b>Atanan:</b> {{agent_name}}\n\n📝 <b>Mesaj:</b>\n{{message}}\n\n🔗 {{link}}")) ?></textarea>
                                                <div class="tg-preview-container mt-2">
                                                    <div class="tg-preview-title"><i class="fab fa-telegram-plane"></i> Telegram Önizleme (TR)</div>
                                                    <div class="tg-message-bubble"></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6 form-group mb-2">
                                                <label class="small font-weight-bold text-muted mb-1">İngilizce Şablon (EN)</label>
                                                <textarea name="tg_new_ticket_en_tpl" class="form-control form-control-sm" rows="6" placeholder="EN şablonu..."><?= s('tg_new_ticket_en_tpl', "🔔 <b>NEW SUPPORT TICKET</b>\n\n📌 <b>Subject:</b> {{subject}}\n🔖 <b>Ticket No:</b> <code>{{ticket_no}}</code>\n⚡ <b>Priority:</b> {{priority}}\n📂 <b>Queue:</b> {{queue}}\n👤 <b>Requested By:</b> {{user_name}}\n🧑‍💻 <b>Assigned To:</b> {{agent_name}}\n\n📝 <b>Message:</b>\n{{message}}\n\n🔗 {{link}}") ?></textarea>
                                                <div class="tg-preview-container mt-2">
                                                    <div class="tg-preview-title"><i class="fab fa-telegram-plane"></i> Telegram Önizleme (EN)</div>
                                                    <div class="tg-message-bubble"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <small class="text-muted d-block mt-1">Değişkenler: <code>{{subject}}</code>, <code>{{ticket_no}}</code>, <code>{{priority}}</code>, <code>{{queue}}</code>, <code>{{user_name}}</code>, <code>{{agent_name}}</code>, <code>{{message}}</code>, <code>{{link}}</code></small>
                                    </div>
                                </div>

                                <!-- Template 2: Temsilci Atama -->
                                <div class="card bg-light border-0 mb-4" style="border-radius:10px;">
                                    <div class="card-body p-3">
                                        <h6 class="font-weight-bold mb-2 text-dark"><i class="fas fa-user-tag mr-1 text-primary"></i> 2. Temsilci Atama Şablonu (HTML)</h6>
                                        <div class="row">
                                            <div class="col-md-6 form-group mb-2">
                                                <label class="small font-weight-bold text-muted mb-1">Türkçe Şablon (TR)</label>
                                                <textarea name="tg_assigned_tr_tpl" class="form-control form-control-sm" rows="6" placeholder="TR şablonu..."><?= s('tg_assigned_tr_tpl', "🧑‍💻 <b>BİLET ATANDI</b>\n\n🔖 <b>Bilet No:</b> <code>{{ticket_no}}</code>\n📌 <b>Konu:</b> {{subject}}\n🧑‍💻 <b>Yeni Atanan Temsilci:</b> {{agent_name}}\n👤 <b>Atamayı Yapan:</b> {{performer_name}}\n\n🔗 {{link}}") ?></textarea>
                                                <div class="tg-preview-container mt-2">
                                                    <div class="tg-preview-title"><i class="fab fa-telegram-plane"></i> Telegram Önizleme (TR)</div>
                                                    <div class="tg-message-bubble"></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6 form-group mb-2">
                                                <label class="small font-weight-bold text-muted mb-1">İngilizce Şablon (EN)</label>
                                                <textarea name="tg_assigned_en_tpl" class="form-control form-control-sm" rows="6" placeholder="EN şablonu..."><?= s('tg_assigned_en_tpl', "🧑‍💻 <b>TICKET ASSIGNED</b>\n\n🔖 <b>Ticket No:</b> <code>{{ticket_no}}</code>\n📌 <b>Subject:</b> {{subject}}\n🧑‍💻 <b>Newly Assigned Agent:</b> {{agent_name}}\n👤 <b>Assigned By:</b> {{performer_name}}\n\n🔗 {{link}}") ?></textarea>
                                                <div class="tg-preview-container mt-2">
                                                    <div class="tg-preview-title"><i class="fab fa-telegram-plane"></i> Telegram Önizleme (EN)</div>
                                                    <div class="tg-message-bubble"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <small class="text-muted d-block mt-1">Değişkenler: <code>{{ticket_no}}</code>, <code>{{subject}}</code>, <code>{{agent_name}}</code>, <code>{{performer_name}}</code>, <code>{{link}}</code></small>
                                    </div>
                                </div>

                                <!-- Template 3: Durum Güncellendi -->
                                <div class="card bg-light border-0 mb-4" style="border-radius:10px;">
                                    <div class="card-body p-3">
                                        <h6 class="font-weight-bold mb-2 text-dark"><i class="fas fa-sync-alt mr-1 text-primary"></i> 3. Durum Güncellendi Şablonu (HTML)</h6>
                                        <div class="row">
                                            <div class="col-md-6 form-group mb-2">
                                                <label class="small font-weight-bold text-muted mb-1">Türkçe Şablon (TR)</label>
                                                <textarea name="tg_status_update_tr_tpl" class="form-control form-control-sm" rows="6" placeholder="TR şablonu..."><?= s('tg_status_update_tr_tpl', "🔄 <b>DURUM GÜNCELLENDİ</b>\n\n🔖 <b>Bilet No:</b> <code>{{ticket_no}}</code>\n📌 <b>Konu:</b> {{subject}}\n🔄 <b>Eski Durum:</b> {{old_status}}\n➡️ <b>Yeni Durum:</b> {{status}}\n🧑‍💻 <b>İşlemi Yapan:</b> {{performer_name}}\n\n🔗 {{link}}") ?></textarea>
                                                <div class="tg-preview-container mt-2">
                                                    <div class="tg-preview-title"><i class="fab fa-telegram-plane"></i> Telegram Önizleme (TR)</div>
                                                    <div class="tg-message-bubble"></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6 form-group mb-2">
                                                <label class="small font-weight-bold text-muted mb-1">İngilizce Şablon (EN)</label>
                                                <textarea name="tg_status_update_en_tpl" class="form-control form-control-sm" rows="6" placeholder="EN şablonu..."><?= s('tg_status_update_en_tpl', "🔄 <b>STATUS UPDATED</b>\n\n🔖 <b>Ticket No:</b> <code>{{ticket_no}}</code>\n📌 <b>Subject:</b> {{subject}}\n🔄 <b>Old Status:</b> {{old_status}}\n➡️ <b>New Status:</b> {{status}}\n🧑‍💻 <b>Updated By:</b> {{performer_name}}\n\n🔗 {{link}}") ?></textarea>
                                                <div class="tg-preview-container mt-2">
                                                    <div class="tg-preview-title"><i class="fab fa-telegram-plane"></i> Telegram Önizleme (EN)</div>
                                                    <div class="tg-message-bubble"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <small class="text-muted d-block mt-1">Değişkenler: <code>{{ticket_no}}</code>, <code>{{subject}}</code>, <code>{{old_status}}</code>, <code>{{status}}</code>, <code>{{performer_name}}</code>, <code>{{link}}</code></small>
                                    </div>
                                </div>

                                <!-- Template 4: Bilete Yanıt -->
                                <div class="card bg-light border-0 mb-4" style="border-radius:10px;">
                                    <div class="card-body p-3">
                                        <h6 class="font-weight-bold mb-2 text-dark"><i class="fas fa-comment-dots mr-1 text-primary"></i> 4. Bilete Yanıt Şablonu (HTML)</h6>
                                        <div class="row">
                                            <div class="col-md-6 form-group mb-2">
                                                <label class="small font-weight-bold text-muted mb-1">Türkçe Şablon (TR)</label>
                                                <textarea name="tg_reply_ticket_tr_tpl" class="form-control form-control-sm" rows="6" placeholder="TR şablonu..."><?= s('tg_reply_ticket_tr_tpl', s('tg_reply_ticket_tpl', "💬 <b>BİLETE YANIT GELDİ</b>\n\n🔖 <b>Bilet No:</b> <code>{{ticket_no}}</code>\n👤 <b>Kimden:</b> {{user_name}}\n\n📝 <b>Mesaj:</b>\n{{message}}\n\n🔗 {{link}}")) ?></textarea>
                                                <div class="tg-preview-container mt-2">
                                                    <div class="tg-preview-title"><i class="fab fa-telegram-plane"></i> Telegram Önizleme (TR)</div>
                                                    <div class="tg-message-bubble"></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6 form-group mb-2">
                                                <label class="small font-weight-bold text-muted mb-1">İngilizce Şablon (EN)</label>
                                                <textarea name="tg_reply_ticket_en_tpl" class="form-control form-control-sm" rows="6" placeholder="EN şablonu..."><?= s('tg_reply_ticket_en_tpl', "💬 <b>NEW REPLY ON TICKET</b>\n\n🔖 <b>Ticket No:</b> <code>{{ticket_no}}</code>\n👤 <b>From:</b> {{user_name}}\n\n📝 <b>Message:</b>\n{{message}}\n\n🔗 {{link}}") ?></textarea>
                                                <div class="tg-preview-container mt-2">
                                                    <div class="tg-preview-title"><i class="fab fa-telegram-plane"></i> Telegram Önizleme (EN)</div>
                                                    <div class="tg-message-bubble"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <small class="text-muted d-block mt-1">Değişkenler: <code>{{ticket_no}}</code>, <code>{{user_name}}</code>, <code>{{message}}</code>, <code>{{link}}</code></small>
                                    </div>
                                </div>

                                <!-- Template 5: Bilet Çözüldü -->
                                <div class="card bg-light border-0 mb-2" style="border-radius:10px;">
                                    <div class="card-body p-3">
                                        <h6 class="font-weight-bold mb-2 text-dark"><i class="fas fa-check-double mr-1 text-primary"></i> 5. Talep Çözüldü Şablonu (HTML)</h6>
                                        <div class="row">
                                            <div class="col-md-6 form-group mb-2">
                                                <label class="small font-weight-bold text-muted mb-1">Türkçe Şablon (TR)</label>
                                                <textarea name="tg_resolved_ticket_tr_tpl" class="form-control form-control-sm" rows="6" placeholder="TR şablonu..."><?= s('tg_resolved_ticket_tr_tpl', s('tg_resolved_ticket_tpl', "✅ <b>TALEP TAMAMLANDI</b>\n\n🔖 <b>Takip No:</b> <code>{{ticket_no}}</code>\n📌 <b>Konu:</b> {{subject}}\n✅ <b>Durum:</b> {{status}}\n🧑‍💻 <b>İşlemi Yapan:</b> {{agent_name}}\n\n📝 <b>Mesaj:</b>\n{{message}}\n\n🔗 {{link}}")) ?></textarea>
                                                <div class="tg-preview-container mt-2">
                                                    <div class="tg-preview-title"><i class="fab fa-telegram-plane"></i> Telegram Önizleme (TR)</div>
                                                    <div class="tg-message-bubble"></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6 form-group mb-2">
                                                <label class="small font-weight-bold text-muted mb-1">İngilizce Şablon (EN)</label>
                                                <textarea name="tg_resolved_ticket_en_tpl" class="form-control form-control-sm" rows="6" placeholder="EN şablonu..."><?= s('tg_resolved_ticket_en_tpl', "✅ <b>TICKET RESOLVED</b>\n\n🔖 <b>Ticket No:</b> <code>{{ticket_no}}</code>\n📌 <b>Subject:</b> {{subject}}\n✅ <b>Status:</b> {{status}}\n🧑‍💻 <b>Resolved By:</b> {{agent_name}}\n\n📝 <b>Message:</b>\n{{message}}\n\n🔗 {{link}}") ?></textarea>
                                                <div class="tg-preview-container mt-2">
                                                    <div class="tg-preview-title"><i class="fab fa-telegram-plane"></i> Telegram Önizleme (EN)</div>
                                                    <div class="tg-message-bubble"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <small class="text-muted d-block mt-1">Değişkenler: <code>{{ticket_no}}</code>, <code>{{subject}}</code>, <code>{{status}}</code>, <code>{{agent_name}}</code>, <code>{{message}}</code>, <code>{{link}}</code></small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer text-right" style="background:transparent; border-top:1px solid #eee;">
                                <button type="submit" class="btn btn-primary px-4 shadow-sm">
                                    <i class="fas fa-save mr-1"></i><?= __("save") ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>



                <!-- SECTION: MASAÜSTÜ & SES AYARLARI -->
                <div class="settings-section <?= $active_tab === 'desktop_notif' ? 'active' : '' ?>" id="section-desktop_notif" style="<?= $active_tab === 'desktop_notif' ? '' : 'display:none;' ?>">
                    <div class="card shadow-sm border-0" style="border-radius:12px;">
                        <div class="card-header bg-white p-3">
                            <h5 class="card-title font-weight-bold m-0 text-dark"><i class="fas fa-desktop text-info mr-2"></i><?= $isTr ? 'Masaüstü Bildirimleri & Ses Ayarları' : 'Desktop Notifications & Audio Settings' ?></h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="mb-4">
                                <label class="font-weight-bold text-dark mb-1"><i class="fas fa-bell text-warning mr-2"></i><?= $isTr ? 'Windows / Tarayıcı Masaüstü Bildirimi Test Et' : 'Test Desktop Notification' ?></label>
                                <p class="text-muted small mb-2"><?= $isTr ? 'Tarayıcınız simge durumunda veya arka planda olsa dahi Windows sağ alt köşesinde sesli pop-up bildirimi çıkar.' : 'Outputs Windows desktop pop-up notification even when browser is minimized.' ?></p>
                                <button type="button" class="btn btn-info btn-sm px-4" onclick="testDesktopNotifPermission()" style="border-radius:8px;">
                                    <i class="fas fa-paper-plane mr-2"></i><?= $isTr ? 'Masaüstü Bildirim İznini Test Et' : 'Test Desktop Notification Permission' ?>
                                </button>
                                <script>
                                function testDesktopNotifPermission() {
                                    var isTr = <?= json_encode($isTr) ?>;
                                    if (!('Notification' in window)) {
                                        Swal.fire({
                                            icon: 'warning',
                                            title: isTr ? 'Desteklenmiyor' : 'Not Supported',
                                            text: isTr ? 'Tarayıcınız masaüstü bildirim özelliğini desteklemiyor.' : 'Your browser does not support desktop notifications.'
                                        });
                                        return;
                                    }
                                    Notification.requestPermission().then(p => {
                                        if (p === 'granted') {
                                            if (typeof EaprimusRealtime !== 'undefined') {
                                                EaprimusRealtime.playChime();
                                                EaprimusRealtime.sendDesktopNotification(
                                                    isTr ? 'Eaprimus Test Bildirimi' : 'Eaprimus Test Notification',
                                                    isTr ? 'Masaüstü bildirim izni başarıyla doğrulandı!' : 'Desktop notification permission successfully verified!',
                                                    window.location.href
                                                );
                                            }
                                            Swal.fire({
                                                icon: 'success',
                                                title: isTr ? 'İzin Verildi!' : 'Permission Granted!',
                                                text: isTr ? 'Masaüstü bildirim izniniz aktif! Test bildirimi sağ alt köşeye gönderildi.' : 'Desktop notification permission is active! Test notification sent to the bottom right corner.'
                                            });
                                        } else {
                                            Swal.fire({
                                                icon: 'warning',
                                                title: isTr ? 'Tarayıcı Bildirim İzni Engellendi' : 'Browser Notification Permission Blocked',
                                                html: isTr ? ('<div class="text-left small" style="line-height:1.6;">' +
                                                    '<p class="mb-2"><strong>Bildirim izni kapalı görünüyor. İzni açmak için:</strong></p>' +
                                                    '<ol class="pl-3 mb-0">' +
                                                    '<li>Tarayıcınızın adres çubuğundaki (URL) <strong>Kilit 🔒 veya Ayarlar</strong> simgesine tıklayın.</li>' +
                                                    '<li><strong>Bildirimler (Notifications)</strong> ayarını <em>"Engelle"</em> yerine <strong>"İzin Ver (Allow)"</strong> yapın.</li>' +
                                                    '<li>Sayfayı yenileyip tekrar bu teste tıklayın.</li>' +
                                                    '</ol></div>') :
                                                    ('<div class="text-left small" style="line-height:1.6;">' +
                                                    '<p class="mb-2"><strong>Notification permission appears to be disabled. To enable:</strong></p>' +
                                                    '<ol class="pl-3 mb-0">' +
                                                    '<li>Click the <strong>Lock 🔒 or Settings</strong> icon in your browser\'s address bar (URL).</li>' +
                                                    '<li>Change the <strong>Notifications</strong> setting from <em>"Block"</em> to <strong>"Allow"</strong>.</li>' +
                                                    '<li>Refresh the page and click this test again.</li>' +
                                                    '</ol></div>'),
                                                confirmButtonText: isTr ? 'Anladım' : 'Got it',
                                                confirmButtonColor: '#3b82f6'
                                            });
                                        }
                                    });
                                }
                                </script>
                            </div>

                            <div class="mb-4 border-top pt-4">
                                <label class="font-weight-bold text-dark mb-1"><i class="fas fa-music text-success mr-2"></i><?= $isTr ? 'Bildirim Ses Tonu Seçimi (20 Farklı Ton)' : 'Notification Sound Tone Selection (20 Options)' ?></label>
                                <select id="soundToneSelect" class="form-control custom-select col-md-6" onchange="localStorage.setItem('eaprimus_sound', this.value); EaprimusRealtime.soundChoice = this.value; EaprimusRealtime.playChime();">
                                    <option value="chime" selected>1. Tatlı Çift Ton (Chime - Varsayılan)</option>
                                    <option value="pop">2. Pop Tone (Hızlı Pop)</option>
                                    <option value="crystal">3. Crystal Melodic Tone (Kristal Melodi)</option>
                                    <option value="radar">4. Radar Pulse Tone (Radar Sinyali)</option>
                                    <option value="marimba">5. Soft Marimba Tone (Yumuşak Marimba)</option>
                                    <option value="bell">6. Classic Service Bell (Klasik Servis Zili)</option>
                                    <option value="echo">7. Cosmic Echo Tone (Kozmik Eko)</option>
                                    <option value="harp">8. Ascending Harp Tone (Yükselen Arp)</option>
                                    <option value="breeze">9. Warm Breeze Chime (Ilık Rüzgar Çanı)</option>
                                    <option value="synth">10. Digital Synth Tone (Dijital Modern Sentezör)</option>
                                    <option value="cyber">11. Cyberpunk Neon Pulse (Siber Neonsel Darbe)</option>
                                    <option value="glass">12. Glass Reflection (Cam Çınlaması)</option>
                                    <option value="fanfare">13. Brass Fanfare (Pirinç Korosu Fanfar)</option>
                                    <option value="drop">14. Gentle Water Drop (Yumuşak Su Damlası)</option>
                                    <option value="flute">15. Soft Wooden Flute (Yumuşak Ahşap Flüt)</option>
                                    <option value="arcade">16. 8-Bit Retro Arcade (Retro Oyun Zıplama Melodisi)</option>
                                    <option value="lotus">17. Lotus Calm Zen Bell (Lotus Meditasyon Zili)</option>
                                    <option value="pulsar">18. Deep Space Pulsar (Derin Uzay Pulsarı)</option>
                                    <option value="electro">19. Electric Spark (Elektrikli Sivilce)</option>
                                    <option value="symphony">20. Symphonic Triad Chord (Senfonik Üçlü Akor)</option>
                                </select>
                                <script>
                                (function syncSoundSelect() {
                                    const savedSound = localStorage.getItem('eaprimus_sound') || 'chime';
                                    const sel = document.getElementById('soundToneSelect');
                                    if (sel) sel.value = savedSound;
                                })();
                                document.addEventListener('DOMContentLoaded', () => {
                                    const savedSound = localStorage.getItem('eaprimus_sound') || 'chime';
                                    const sel = document.getElementById('soundToneSelect');
                                    if (sel) sel.value = savedSound;
                                });
                                </script>
                            </div>
                        </div>
                    </div>
                </div>



                            

                <div class="settings-section <?= $active_tab === 'status' ? 'active' : '' ?>" id="section-status">
                    <form method="POST" action="anasayfa?route=sistem-ayarlari">
                        <?= csrf_field() ?>
                        <input type="hidden" name="section" value="status">
                        <div class="card shadow-sm" style="border-radius:12px; border:none;">
                            <div class="card-header d-flex justify-content-between align-items-center" style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                                <h4 class="mb-0 font-weight-bold"><i class="fas fa-tags mr-2 text-primary"></i><?= __("ticket_status_settings") ?></h4>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addStatusRow()"><i class="fas fa-plus mr-1"></i><?= __("add_status") ?></button>
                            </div>
                            <div class="card-body px-4 py-4">
                                <p class="text-muted small mb-4"><?= __('inventory_mail_settings_hint') ?></p>
                                <div id="status-container">
                                    <div class="row align-items-center mb-2 font-weight-bold small text-muted">
                                        <div class="col-md-3"><?= __("status_key") ?></div>
                                        <div class="col-md-3"><?= __("status_label") ?></div>
                                        <div class="col-md-2 text-center"><?= __("color") ?></div>
                                        <div class="col-md-2 text-center"><?= __("dashboard_show") ?></div>
                                        <div class="col-md-2 text-right"><?= __("action") ?></div>
                                    </div>
                                    <?php
                                    $stEntries = $pdo->query("SELECT * FROM ticket_statuses ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
                                    if (empty($stEntries)) {
                                        // Fallback to settings or defaults
                                        $config = json_decode($allSettings['ticket_statuses_config'] ?? '', true);
                                        if (empty($config)) $config = ['open' => ['label' => 'Açık', 'color' => '#3b82f6'], 'resolved' => ['label' => 'Çözüldü', 'color' => '#10b981']];
                                        foreach($config as $k => $v) $stEntries[] = ['id_name' => $k, 'label' => $v['label'], 'color' => $v['color'], 'show_on_dashboard' => 1];
                                    }

                                    foreach ($stEntries as $idx => $st):
                                    ?>
                                    <div class="row align-items-center mb-3 status-row">
                                        <div class="col-md-3">
                                            <input type="text" name="status_key[]" class="form-control form-control-sm" value="<?= htmlspecialchars($st['id_name'] ?? '') ?>" placeholder="<?= __("status_key") ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="text" name="status_label[]" class="form-control form-control-sm" value="<?= htmlspecialchars($st['label'] ?? '') ?>" placeholder="<?= __("status_label") ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <input type="color" name="status_color[]" class="form-control form-control-sm mx-auto" value="<?= $st['color'] ?? '#64748b' ?>" style="width:40px; padding:2px;">
                                        </div>
                                        <div class="col-md-2 text-center">
                                            <div class="custom-control custom-switch">
                                                <input type="checkbox" name="status_show[]" value="<?= htmlspecialchars($st['id_name'] ?? '') ?>" class="custom-control-input" id="sw_<?= $idx ?>" <?= (!isset($st['show_on_dashboard']) || (int)$st['show_on_dashboard'] === 1) ? 'checked' : '' ?>>
                                                <label class="custom-control-label" for="sw_<?= $idx ?>"></label>
                                            </div>
                                        </div>
                                        <div class="col-md-2 text-right">
                                            <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="this.closest('.status-row').remove(); updateStatusIndices();"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="card-footer text-right" style="background:transparent; border-top:1px solid #eee;">
                                <button type="submit" class="btn btn-primary px-4 shadow-sm">
                                    <i class="fas fa-save mr-1"></i><?= __("save") ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- --- MAIL SABLONLARI --- -->
                <div class="settings-section <?= $active_tab === 'mailtemplates' ? 'active' : '' ?>" id="section-mailtemplates">
                    <div class="card shadow-sm" style="border-radius:12px; border:none;">
                        <div class="card-header" style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                            <h4 class="mb-0 font-weight-bold"><i class="fas fa-file-code mr-2 text-primary"></i><?= __("mail_templates") ?></h4>
                        </div>
                        <div class="card-body px-4 py-4">
                            <p class="text-muted small mb-4"><?= __('inventory_mail_settings_hint') ?></p>

                            <div class="row align-items-center mb-4 p-3 bg-light rounded border">
                                <div class="col-md-6">
                                    <h6 class="mb-1 font-weight-bold"><i class="fas fa-globe-americas mr-1"></i> <?= __("default_email_lang") ?></h6>
                                    <small class="text-muted"><?= __("default_email_lang_hint") ?></small>
                                </div>
                                <div class="col-md-6">
                                    <?php $currentMailLang = s('mail_default_lang') ?: s('system_lang', 'tr'); ?>
                                    <select name="mail_default_lang" id="mail_default_lang_select" class="form-control" onchange="confirmMailLangChange(this)">
                                        <option value="tr" <?= $currentMailLang == 'tr' ? 'selected' : '' ?>>Türkçe (TR)</option>
                                        <option value="en" <?= $currentMailLang == 'en' ? 'selected' : '' ?>>English (EN)</option>
                                    </select>

                                    <!-- Mail Dili Değişiklik Uyarısı Modal -->
                                    <div class="modal fade" id="mailLangConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered" role="document">
                                            <div class="modal-content" style="border-radius:12px; border:none; box-shadow:0 20px 60px rgba(0,0,0,0.15);">
                                                <div class="modal-header border-0 pb-0">
                                                    <div class="w-100 text-center pt-3">
                                                        <div style="width:60px;height:60px;background:#fff3cd;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                                                            <i class="fas fa-language" style="font-size:24px;color:#f59e0b;"></i>
                                                        </div>
                                                        <h5 class="font-weight-bold mb-1"><?= $isTr ? 'Gönderim Dili Değiştiriliyor' : 'Changing Send Language' ?></h5>
                                                    </div>
                                                </div>
                                                <div class="modal-body text-center px-4">
                                                    <p id="mailLangConfirmMsg" class="text-muted mb-3" style="font-size:14px;line-height:1.6;"></p>
                                                    <div class="alert alert-warning border-0 text-left" style="border-radius:8px;font-size:13px;">
                                                        <i class="fas fa-info-circle mr-1"></i>
                                                        <span id="mailLangResetMsg"></span>
                                                    </div>
                                                </div>
                                                <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
                                                    <button type="button" class="btn btn-secondary btn-sm px-4" onclick="cancelMailLangChange()"><?= $isTr ? 'İptal' : 'Cancel' ?></button>
                                                    <button type="button" class="btn btn-warning btn-sm px-4" onclick="applyMailLangChange(false)">
                                                        <i class="fas fa-check mr-1"></i><?= $isTr ? 'Sadece Dili Değiştir' : 'Change Language Only' ?>
                                                    </button>
                                                    <button type="button" class="btn btn-primary btn-sm px-4" onclick="applyMailLangChange(true)">
                                                        <i class="fas fa-sync-alt mr-1"></i><?= $isTr ? 'Dil + Şablonları Sıfırla' : 'Reset Language + Templates' ?>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <?php
                                $templates = [
                                    ['key' => 'new_ticket_cust', 'label' => __("tpl_new_ticket_cust"), 'desc' => __("tpl_new_ticket_cust_desc"), 'icon' => 'fa-user-plus'],
                                    ['key' => 'new_ticket_agent', 'label' => __("tpl_new_ticket_agent"), 'desc' => __("tpl_new_ticket_agent_desc"), 'icon' => 'fa-user-tie'],
                                    ['key' => 'reply_cust', 'label' => __("tpl_reply_cust"), 'desc' => __("tpl_reply_cust_desc"), 'icon' => 'fa-reply'],
                                    ['key' => 'reply_agent', 'label' => __("tpl_reply_agent"), 'desc' => __("tpl_reply_agent_desc"), 'icon' => 'fa-envelope-open-text'],
                                    ['key' => 'resolved', 'label' => __("tpl_resolved"), 'desc' => __("tpl_resolved_desc"), 'icon' => 'fa-check-double'],
                                    ['key' => 'imap_forward', 'label' => __("tpl_imap_forward"), 'desc' => __("tpl_imap_forward_desc"), 'icon' => 'fa-share-square']
                                ];
                                foreach($templates as $t):
                                    $sysL = $isTr ? 'tr' : 'en';
                                    $subj = s('mail_' . $t['key'] . '_' . $defL . '_subject') ?: (s('mail_' . $t['key'] . '_subject') ?: s('mail_' . $t['key'] . '_tr_subject'));
                                    $status = s('mail_' . $t['key'] . '_status', 'active');
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="template-card p-3 border rounded shadow-sm h-100 position-relative" style="cursor:pointer; transition:all .2s;" onclick="openTemplateDrawer('<?= $t['key'] ?>', '<?= htmlspecialchars($t['label'] ?: $t['key']) ?>')">
                                        <div class="status-badge position-absolute" style="top:10px; right:12px;">
                                            <?php if($status == 'active'): ?>
                                                <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> <?= __("active") ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary px-2 py-1"><i class="fas fa-pause-circle mr-1"></i> <?= __("passive") ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex align-items-center mb-2">
                                            <div class="icon-circle bg-light text-primary mr-3" style="width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                                <i class="fas <?= $t['icon'] ?>"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-0 font-weight-bold"><?= $t['label'] ?: $t['key'] ?></h6>
                                                <small class="text-muted"><?= $t['desc'] ?></small>
                                            </div>
                                        </div>
                                        <div class="text-truncate small opacity-75 mt-2">
                                            <i class="fas fa-heading mr-1"></i> <?= $subj ?: __("default_template") ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- --- ENVANTER AYARLARI --- -->
                <div class="settings-section <?= $active_tab === 'inventory' ? 'active' : '' ?>" id="section-inventory">
                    <div class="row mb-4">
                        <?php
                        $inv_nav = [
                            ['icon' => 'fas fa-tasks', 'label' => __("custom_fields"), 'hint' => $isTr ? 'Varlıklar için özel alan grupları' : 'Custom field groups for assets', 'link' => 'varliklar?view=predefined&type=custom_fields'],
                            ['icon' => 'fas fa-tags', 'label' => __("status_labels"), 'hint' => $isTr ? 'Varlık durumlarını özelleştirin' : 'Customize asset status labels', 'link' => 'varliklar?view=predefined&type=status_labels'],
                            ['icon' => 'fas fa-laptop-code', 'label' => __("asset_models"), 'hint' => $isTr ? 'Cihaz modellerini yönetin' : 'Manage device models', 'link' => 'varliklar?view=predefined&type=models'],
                            ['icon' => 'fas fa-list', 'label' => __("categories"), 'hint' => $isTr ? 'Cihaz, sarf ve aksesuar kategorileri' : 'Device, consumable and accessory categories', 'link' => 'varliklar?view=predefined&type=categories'],
                            ['icon' => 'fas fa-industry', 'label' => __("manufacturers"), 'hint' => $isTr ? 'Cihaz üretici listesi' : 'Device manufacturer list', 'link' => 'varliklar?view=predefined&type=manufacturers'],
                            ['icon' => 'fas fa-truck', 'label' => __("suppliers"), 'hint' => $isTr ? 'Varlık tedarikçi listesi' : 'Asset supplier list', 'link' => 'varliklar?view=predefined&type=suppliers'],
                            ['icon' => 'fas fa-building', 'label' => __("departments"), 'hint' => $isTr ? 'Şirket içi departmanlar' : 'Internal company departments', 'link' => 'varliklar?view=predefined&type=departments'],
                            ['icon' => 'fas fa-city', 'label' => __("companies"), 'hint' => $isTr ? 'Varlıkların ait olduğu şirketler' : 'Companies that own assets', 'link' => 'varliklar?view=predefined&type=companies'],
                        ];
                        foreach($inv_nav as $n):
                        ?>
                        <div class="col-md-4 col-sm-6 mb-3">
                            <a href="<?= $n['link'] ?>" class="card h-100 shadow-sm border-0 text-decoration-none bg-white p-3 d-flex flex-row align-items-center" style="border-radius:12px; transition: 0.3s; border: 1px solid #eee !important;">
                                <div class="icon-box mr-3 bg-light text-primary d-flex align-items-center justify-content-center" style="width:50px; height:50px; border-radius:10px; font-size:1.2rem;">
                                    <i class="<?= $n['icon'] ?>"></i>
                                </div>
                                <div style="flex:1;">
                                    <h6 class="mb-0 font-weight-bold text-dark"><?= $n['label'] ?></h6>
                                    <small class="text-muted d-block" style="line-height:1.2; font-size:11px;"><?= $n['hint'] ?></small>
                                </div>
                                <i class="fas fa-chevron-right text-muted small ml-2"></i>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <form method="POST" action="anasayfa?route=sistem-ayarlari" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="section" value="inventory">

                        <div class="card shadow-sm border-0 mb-4" style="border-radius:12px; border:none;">
                            <div class="card-header bg-white" style="border-bottom:1px solid #f0f0f0; padding:20px 24px;">
                                <h5 class="mb-0 font-weight-bold ml-1"><i class="fas fa-cog mr-2 text-primary"></i> <?= __("inventory_general_settings") ?></h5>
                            </div>
                            <div class="card-body px-4 py-4">
                                <div class="row mb-4">
                                     <div class="col-md-6 mb-3">
                                         <div class="form-group">
                                             <label class="font-weight-bold"><?= __("inventory_dashboard_title") ?></label>
                                             <input type="text" name="inv_title" class="form-control" value="<?= s('inv_title', $isTr ? 'Envanter Panosu' : 'Inventory Dashboard') ?>" placeholder="<?= $isTr ? 'Örn: Envanter Panosu' : 'Ex: Inventory Dashboard' ?>">
                                         </div>
                                     </div>
                                     <div class="col-md-6 mb-3">
                                         <div class="form-group">
                                             <label class="font-weight-bold"><?= __("inventory_dashboard_slogan") ?></label>
                                             <input type="text" name="inv_slogan" class="form-control" value="<?= s('inv_slogan') ?>" placeholder="<?= $isTr ? 'Örn: Kurumsal Envanter ve Varlık Yönetimi' : 'Ex: Corporate Inventory & Asset Management' ?>">
                                         </div>
                                     </div>
                                     <div class="col-md-6">
                                         <div class="form-group">
                                             <label class="font-weight-bold"><?= __("support_system_title") ?></label>
                                             <input type="text" name="ticket_title" class="form-control" value="<?= s('ticket_title', $isTr ? 'Destek Sistemi' : 'Support System') ?>" placeholder="<?= $isTr ? 'Örn: Destek Sistemi' : 'Ex: Support System' ?>">
                                         </div>
                                     </div>
                                     <div class="col-md-6">
                                         <div class="form-group">
                                             <label class="font-weight-bold"><?= __("support_dashboard_slogan") ?></label>
                                             <input type="text" name="ticket_slogan" class="form-control" value="<?= s('ticket_slogan') ?>" placeholder="<?= $isTr ? 'Örn: Profesyonel Destek ve Çözüm Merkezi' : 'Ex: Professional Support & Solution Center' ?>">
                                         </div>
                                     </div>
                                </div>

                                <hr class="my-4">

                                 <h6 class="mb-3 font-weight-bold"><i class="fas fa-barcode mr-2"></i> <?= __("id_policy_settings") ?></h6>
                                 <div class="row">
                                     <div class="col-md-4">
                                         <div class="form-group">
                                             <label class="font-weight-bold"><?= __("asset_prefix") ?></label>
                                             <input type="text" name="inventory_asset_prefix" class="form-control" value="<?= s('inventory_asset_prefix', 'AST') ?>" maxlength="10" placeholder="AST">
                                             <small class="text-muted d-block mt-1"><?= $isTr ? 'Otomatik oluşturulan varlık demirbaş kodlarının (Tag) başına eklenir (Örn: AST-0001).' : 'Prepended to auto-generated asset tag codes (Ex: AST-0001).' ?></small>
                                         </div>
                                     </div>
                                     <div class="col-md-4">
                                         <div class="form-group">
                                             <label class="font-weight-bold"><?= __("license_prefix") ?></label>
                                             <input type="text" name="inventory_license_prefix" class="form-control" value="<?= s('inventory_license_prefix', 'LIC') ?>" maxlength="10" placeholder="LIC">
                                             <small class="text-muted d-block mt-1"><?= $isTr ? 'Yeni lisans kayıtlarında otomatik demirbaş kodunun başına eklenir (Örn: LIC-0001).' : 'Prepended to auto-generated license tag codes (Ex: LIC-0001).' ?></small>
                                         </div>
                                     </div>
                                     <div class="col-md-4">
                                         <div class="form-group">
                                             <label class="font-weight-bold"><?= __("accessory_prefix") ?></label>
                                             <input type="text" name="inventory_accessory_prefix" class="form-control" value="<?= s('inventory_accessory_prefix', 'ACC') ?>" maxlength="10" placeholder="ACC">
                                             <small class="text-muted d-block mt-1"><?= $isTr ? 'Aksesuar kayıtlarında otomatik demirbaş kodunun başına eklenir (Örn: ACC-0001).' : 'Prepended to auto-generated accessory tag codes (Ex: ACC-0001).' ?></small>
                                         </div>
                                     </div>
                                     <div class="col-md-4">
                                         <div class="form-group">
                                             <label class="font-weight-bold"><?= __("consumable_prefix") ?></label>
                                             <input type="text" name="inventory_consumable_prefix" class="form-control" value="<?= s('inventory_consumable_prefix', 'CON') ?>" maxlength="10" placeholder="CON">
                                             <small class="text-muted d-block mt-1"><?= $isTr ? 'Sarf malzeme kayıtlarında otomatik stok/kod numarasının başına eklenir (Örn: CON-0001).' : 'Prepended to auto-generated consumable codes (Ex: CON-0001).' ?></small>
                                         </div>
                                     </div>
                                     <div class="col-md-4">
                                         <div class="form-group">
                                             <label class="font-weight-bold"><?= __("component_prefix") ?></label>
                                             <input type="text" name="inventory_component_prefix" class="form-control" value="<?= s('inventory_component_prefix', 'CMP') ?>" maxlength="10" placeholder="CMP">
                                         </div>
                                     </div>
                                 </div>
                                 <div class="row mb-2 mt-3">
                                     <div class="col-md-6">
                                         <div class="custom-control custom-switch mb-2">
                                             <input type="checkbox" name="inventory_checkout_requires_acceptance" class="custom-control-input" id="sw_inventory_acceptance" value="1" <?= s('inventory_checkout_requires_acceptance') === '1' ? 'checked' : '' ?>>
                                             <label class="custom-control-label font-weight-bold" for="sw_inventory_acceptance"><?= __("require_user_acceptance_for_checkout") ?></label>
                                         </div>
                                         <div class="custom-control custom-switch mb-2">
                                             <input type="checkbox" name="inventory_enforce_unique_asset_tag" class="custom-control-input" id="sw_unique_asset_tag" value="1" <?= s('inventory_enforce_unique_asset_tag') === '1' ? 'checked' : '' ?>>
                                             <label class="custom-control-label font-weight-bold" for="sw_unique_asset_tag"><?= __("enforce_unique_asset_tag") ?></label>
                                         </div>
                                         <div class="custom-control custom-switch mb-2">
                                             <input type="checkbox" name="inventory_auto_assign_consumables" class="custom-control-input" id="sw_inventory_consumable_auto" value="1" <?= s('inventory_auto_assign_consumables') === '1' ? 'checked' : '' ?>>
                                             <label class="custom-control-label font-weight-bold" for="sw_inventory_consumable_auto"><?= __("auto_approve_consumable_checkout") ?></label>
                                         </div>
                                         <div class="custom-control custom-switch mb-2">
                                             <input type="checkbox" name="inventory_enable_qr_labels" class="custom-control-input" id="sw_inventory_qr" value="1" <?= s('inventory_enable_qr_labels') === '1' ? 'checked' : '' ?>>
                                             <label class="custom-control-label font-weight-bold" for="sw_inventory_qr"><?= __("enable_qr_barcode_labels") ?></label>
                                         </div>
                                     </div>
                                 </div>

                                  <hr class="my-4">
                                  <div class="row">
                                      <div class="col-md-6 form-group">
                                          <label class="font-weight-bold"><i class="fas fa-file-contract mr-1 text-primary"></i> <?= $isTr ? 'Zimmet Sözleşmesi Metni (Türkçe)' : 'Assignment Agreement Text (Turkish)' ?></label>
                                          <textarea name="inv_signature_agreement_tr" class="form-control shadow-sm" rows="8" style="border-radius:10px; border:1px solid #cbd5e1;" placeholder="Zimmet sırasında onaylanacak Türkçe yasal metni buraya yazın..."><?= s('inv_signature_agreement_tr', s('inv_signature_agreement', 'Aşağıda donanımsal detayları belirtilerek tarafıma teslim edilen envanteri, sağlam ve çalışır durumda teslim aldığımı, kullanımına ve temizliğine özen göstereceğimi, arıza veya hata durumunda Bilgi İşlem Personeli\'ni bilgilendireceğimi ve kendi başıma müdahale etmeyeceğimi, iş amacı dışında kullanmayacağımı ve başkasına kullandırmayacağımı, Bilgi İşlem Personeli\'nin izni olmadan donanımsal veya yazılımsal değişiklik yapmayacağımı, yazılım kurmayacağımı ve şifre belirlemeyeceğimi, her türlü aktivitenin (e-posta kayıtları, anlık mesajlaşma yazılımları, ziyaret edilen web siteleri, kopyalanan ve silinen dosyalar, telefon görüşmeleri, donanım kimlikleri, kullanıcı hesapları vb. gibi) Bilgi İşlem birimi tarafından uygun teknik yöntemlerle kayıt altında tutulduğunu ve ihtiyaç durumunda bu kayıtlara bakılabildiğini, bu teslim tutanağı ile birlikte ekte tarafıma teslim edilen "Donanım Kullanma Talimatı"na uyacağımı beyan ve taahhüt ederim.')) ?></textarea>
                                          <small class="text-muted"><?= $isTr ? 'Türkçe zimmet veya PDF çıktılarında bu metin kullanılır.' : 'This text is used for Turkish signature and PDF outputs.' ?></small>
                                      </div>
                                      <div class="col-md-6 form-group">
                                          <label class="font-weight-bold"><i class="fas fa-file-contract mr-1 text-primary"></i> <?= $isTr ? 'Zimmet Sözleşmesi Metni (İngilizce)' : 'Assignment Agreement Text (English)' ?></label>
                                          <textarea name="inv_signature_agreement_en" class="form-control shadow-sm" rows="8" style="border-radius:10px; border:1px solid #cbd5e1;" placeholder="Enter the English legal text to be approved..."><?= s('inv_signature_agreement_en', 'I hereby acknowledge that I have received the assets specified below in good working condition, agree to use them carefully and keep them clean. I agree to notify IT staff in case of any failure or error and will not attempt to repair it myself. I agree not to use the assets for non-work purposes or allow others to use them. I will not make any hardware or software changes, install software, or set passwords without IT authorization. I am aware that all activities (email records, instant messaging, web browsing, file operations, hardware IDs, user accounts, etc.) are monitored and recorded by the IT department for security and audit purposes.') ?></textarea>
                                          <small class="text-muted"><?= $isTr ? 'İngilizce zimmet veya PDF çıktılarında bu metin kullanılır.' : 'This text is used for English signature and PDF outputs.' ?></small>
                                      </div>
                                  </div>
                            </div>
                            <div class="card-footer text-right bg-white" style="border-top:1px solid #f0f0f0;">
                                <button type="submit" class="btn btn-primary px-5 py-2 shadow-sm font-weight-bold">
                                    <i class="fas fa-save mr-1"></i> <?= __("save_settings") ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- --- PERSONEL & ZİMMET AYARLARI --- -->
                <div class="settings-section <?= $active_tab === 'personel' ? 'active' : '' ?>" id="section-personel">
                    <div class="card shadow-sm" style="border-radius:12px; border:none;">
                        <div class="card-header" style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                            <h4 class="mb-0 font-weight-bold"><i class="fas fa-user-shield mr-2 text-primary"></i><?= __("personnel_assign_settings") ?></h4>
                        </div>
                        <div class="card-body px-4 py-4">
                            <p class="text-muted small mb-4"><?= $isTr ? 'Bu alandan personele gidecek olan otomatize mail şablonlarını düzenleyebilirsiniz.' : 'You can edit the automated email templates sent to staff here.' ?></p>
                            <div class="row">
                                <?php
                                $personelTemplates = [
                                    ['key' => 'user_invitation', 'label' => __("user_invitation"), 'desc' => __("user_invitation_desc"), 'icon' => 'fa-paper-plane'],
                                    ['key' => 'user_registration', 'label' => __("user_registration"), 'desc' => __("user_registration_desc"), 'icon' => 'fa-user-plus'],
                                    ['key' => 'asset_assigned', 'label' => __("asset_assigned"), 'desc' => __("asset_assigned_desc"), 'icon' => 'fa-laptop-medical'],
                                    ['key' => 'asset_returned', 'label' => __("asset_returned"), 'desc' => __("asset_returned_desc"), 'icon' => 'fa-undo-alt'],
                                    ['key' => 'password_reset', 'label' => __("password_reset_mail"), 'desc' => __("password_reset_mail_desc"), 'icon' => 'fa-key']
                                ];
                                foreach($personelTemplates as $t):
                                    $sysL = $isTr ? 'tr' : 'en';
                                    $subj = s('mail_' . $t['key'] . '_' . $defL . '_subject') ?: (s('mail_' . $t['key'] . '_subject') ?: s('mail_' . $t['key'] . '_tr_subject'));
                                    $status = s('mail_' . $t['key'] . '_status', 'active');
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="template-card p-3 border rounded shadow-sm h-100 position-relative" style="cursor:pointer; transition:all .2s;" onclick="openTemplateDrawer('<?= $t['key'] ?>', '<?= htmlspecialchars($t['label']) ?>')">
                                        <div class="status-badge position-absolute" style="top:10px; right:12px;">
                                            <?php if($status == 'active'): ?>
                                                <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> <?= __("active") ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary px-2 py-1"><i class="fas fa-pause-circle mr-1"></i> <?= __("passive") ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex align-items-center mb-2">
                                            <div class="icon-circle bg-light text-primary mr-3" style="width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                                <i class="fas <?= $t['icon'] ?>"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-0 font-weight-bold"><?= $t['label'] ?></h6>
                                                <small class="text-muted"><?= $t['desc'] ?></small>
                                            </div>
                                        </div>
                                        <div class="text-truncate small opacity-75 mt-2">
                                            <i class="fas fa-heading mr-1"></i> <?= $subj ?: ($isTr ? 'Varsayılan Şablon' : 'Default Template') ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- --- LDAP / ACTIVE DIRECTORY --- -->
                <?php include __DIR__ . '/automations_ui.php'; ?>
                <div class="settings-section <?= $active_tab === 'ldap' ? 'active' : '' ?>" id="section-ldap">
                    <form method="POST" action="anasayfa?route=sistem-ayarlari">
                        <?= csrf_field() ?>
                        <input type="hidden" name="section" value="ldap">
                        <div class="card shadow-sm" style="border-radius:12px; border:none;">
                            <div class="card-header" style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                                <h4 class="mb-0 font-weight-bold"><i class="fas fa-network-wired mr-2 text-primary"></i><?= $isTr ? 'LDAP / Active Directory' : 'LDAP / Active Directory' ?></h4>
                            </div>
                            <div class="card-body px-4 py-4">
                                <div class="custom-control custom-switch mb-4">
                                    <input type="checkbox" name="ldap_enabled" class="custom-control-input" id="sw_ldap_enabled" <?= s('ldap_enabled') == '1' ? 'checked' : '' ?> onchange="toggleLdapFields(); this.closest('form').submit();">
                                    <label class="custom-control-label font-weight-bold text-primary" for="sw_ldap_enabled"><?= $isTr ? 'LDAP Girişini Aktifleştir' : 'Enable LDAP Login' ?></label>
                                    <small class="d-block text-muted mt-1"><?= $isTr ? 'Kullanıcılar mevcut AD hesapları ile sisteme giriş yapabilirler.' : 'Users can login to the system using their AD accounts.' ?></small>
                                </div>

                                <div id="ldap_config_fields" style="<?= s('ldap_enabled') == '1' ? '' : 'opacity: 0.55; pointer-events: none; user-select: none;' ?>">
                                    <div class="row">
                                        <div class="col-md-12 mb-2">
                                            <h6 class="font-weight-bold text-primary border-bottom pb-2"><i class="fas fa-server mr-2"></i><?= $isTr ? 'LDAP Sunucu Ayarları' : 'LDAP Server Settings' ?></h6>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label class="font-weight-bold"><?= $isTr ? 'Sunucu (Host)' : 'Server (Host)' ?></label>
                                                <input type="text" name="ldap_host" class="form-control" value="<?= s('ldap_host') ?>" placeholder="ldap://192.168.1.100" <?= s('ldap_enabled') == '1' ? '' : 'disabled' ?>>
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label class="font-weight-bold"><?= $isTr ? 'Port' : 'Port' ?></label>
                                                <input type="text" name="ldap_port" class="form-control" value="<?= s('ldap_port', '389') ?>" placeholder="389" <?= s('ldap_enabled') == '1' ? '' : 'disabled' ?>>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-3">
                                        <div class="col-md-12 mb-2">
                                            <h6 class="font-weight-bold text-primary border-bottom pb-2"><i class="fab fa-windows mr-2"></i><?= $isTr ? 'Active Directory (AD) Yapılandırması' : 'Active Directory (AD) Configuration' ?></h6>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label class="font-weight-bold"><?= $isTr ? 'Domain (Örn: sirket.local)' : 'Domain (e.g. sirket.local)' ?></label>
                                                <input type="text" name="ldap_domain" class="form-control" value="<?= s('ldap_domain') ?>" placeholder="sirket.local" <?= s('ldap_enabled') == '1' ? '' : 'disabled' ?>>
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label class="font-weight-bold"><?= $isTr ? 'Base DN' : 'Base DN' ?></label>
                                                <input type="text" name="ldap_base_dn" class="form-control" value="<?= s('ldap_base_dn') ?>" placeholder="DC=sirket,DC=local" <?= s('ldap_enabled') == '1' ? '' : 'disabled' ?>>
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label class="font-weight-bold"><?= $isTr ? 'Servis Hesabı (Kullanıcı Adı)' : 'Service Account (Username)' ?></label>
                                                <input type="text" name="ldap_admin_user" class="form-control" value="<?= s('ldap_admin_user') ?>" placeholder="administrator@sirket.local" autocomplete="new-password" <?= s('ldap_enabled') == '1' ? '' : 'disabled' ?>>
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label class="font-weight-bold"><?= $isTr ? 'Servis Hesabı (Şifre)' : 'Service Account (Password)' ?></label>
                                                <input type="password" name="ldap_admin_pass" class="form-control" value="" placeholder="<?= $isTr ? '(Değiştirmek istemiyorsanız boş bırakın)' : '(Leave empty to keep current)' ?>" autocomplete="new-password" readonly onfocus="this.removeAttribute('readonly');" <?= s('ldap_enabled') == '1' ? '' : 'disabled' ?>>
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label class="font-weight-bold"><?= $isTr ? 'Yeni Kullanıcılar İçin Varsayılan Rol' : 'Default Role for New Users' ?></label>
                                                <select name="ldap_default_role" class="form-control" <?= s('ldap_enabled') == '1' ? '' : 'disabled' ?>>
                                                    <option value="2" <?= s('ldap_default_role', '2') == '2' ? 'selected' : '' ?>><?= $isTr ? 'Personel (Varsayılan)' : 'Personnel (Default)' ?></option>
                                                    <option value="3" <?= s('ldap_default_role') == '3' ? 'selected' : '' ?>><?= $isTr ? 'Teknik Destek' : 'Technical Support' ?></option>
                                                    <option value="1" <?= s('ldap_default_role') == '1' ? 'selected' : '' ?>><?= $isTr ? 'Süper Admin' : 'Super Admin' ?></option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- LDAP Users Table -->
                                <div class="row mt-5">
                                    <div class="col-md-12 mb-3">
                                        <h6 class="font-weight-bold text-primary border-bottom pb-2"><i class="fas fa-users mr-2"></i><?= $isTr ? 'Active Directory / LDAP Üzerinden Gelen Kullanıcılar' : 'Users synced from AD / LDAP' ?></h6>
                                        <small class="text-muted"><?= $isTr ? 'Bu listedeki kullanıcılar, sisteme Active Directory üzerinden oturum açmış kullanıcılardır (Yerel şifreleri bulunmaz).' : 'These users logged in via Active Directory (They do not have local passwords).' ?></small>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-striped table-sm text-sm">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th>ID</th>
                                                        <th><?= $isTr ? 'Kullanıcı Adı' : 'Username' ?></th>
                                                        <th><?= $isTr ? 'Ad Soyad' : 'Full Name' ?></th>
                                                        <th><?= $isTr ? 'E-posta' : 'Email' ?></th>
                                                        <th><?= $isTr ? 'Oluşturulma Tarihi' : 'Created At' ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $stmtLdapUsers = $pdo->query("SELECT id, username, fullname, mail, created_at FROM users WHERE password IS NULL OR password = '' ORDER BY created_at DESC");
                                                    $ldapUsers = $stmtLdapUsers->fetchAll(PDO::FETCH_ASSOC);
                                                    if (empty($ldapUsers)):
                                                    ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted"><?= $isTr ? 'Henüz LDAP üzerinden sisteme giriş yapan kullanıcı bulunmuyor.' : 'No users have logged in via LDAP yet.' ?></td>
                                                    </tr>
                                                    <?php else: foreach($ldapUsers as $lu): ?>
                                                    <tr>
                                                        <td><?= $lu['id'] ?></td>
                                                        <td><?= htmlspecialchars($lu['username']) ?></td>
                                                        <td><?= htmlspecialchars($lu['fullname']) ?></td>
                                                        <td><?= htmlspecialchars($lu['mail'] ?? '-') ?></td>
                                                        <td><?= htmlspecialchars($lu['created_at']) ?></td>
                                                    </tr>
                                                    <?php endforeach; endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer text-right" style="background:transparent; border-top:1px solid #eee;">
                                <button type="submit" id="btn_ldap_save" class="btn btn-primary px-4 shadow-sm" <?= s('ldap_enabled') == '1' ? '' : 'disabled' ?>>
                                    <i class="fas fa-save mr-1"></i><?= __("save") ?>
                                </button>
                            </div>
                        </div>
                    </form>

                    <script>
                    function toggleLdapFields() {
                        const sw = document.getElementById('sw_ldap_enabled');
                        if (!sw) return;
                        const isEnabled = sw.checked;
                        const container = document.getElementById('ldap_config_fields');
                        const saveBtn = document.getElementById('btn_ldap_save');

                        if (container) {
                            container.style.opacity = isEnabled ? '1' : '0.55';
                            container.style.pointerEvents = isEnabled ? 'auto' : 'none';
                            container.style.userSelect = isEnabled ? 'auto' : 'none';

                            const inputs = container.querySelectorAll('input, select');
                            inputs.forEach(input => {
                                if (isEnabled) {
                                    input.removeAttribute('disabled');
                                } else {
                                    input.setAttribute('disabled', 'disabled');
                                }
                            });
                        }

                        if (saveBtn) {
                            if (isEnabled) {
                                saveBtn.removeAttribute('disabled');
                            } else {
                                saveBtn.setAttribute('disabled', 'disabled');
                            }
                        }
                    }

                    document.addEventListener('DOMContentLoaded', function() {
                        toggleLdapFields();
                    });
                    if (document.readyState !== 'loading') {
                        toggleLdapFields();
                    }
                    </script>
                </div>

                <!-- --- API AYARLARI --- -->
                    <div class="settings-section <?= $active_tab === 'api' ? 'active' : '' ?>" id="section-api">
                    <?php
                    $apiKey = '';
                    $apiSecret = '';
                    $siteUrl = '';
                    $isHttps = false;
                    $syncedAssets = [];
                    $allUsers = [];
                    $userDevices = [];

                    if ((int)($_SESSION['role'] ?? 0) === 1) {
                        $apiKey = s('api_client_id', '-');
                        $apiSecret = s('api_client_secret', '-');
                        $siteUrl = s('site_url', '');
                        $isHttps = (strpos(strtolower($siteUrl), 'https://') === 0);

                        try {
                            $syncedAssets = $pdo->query("SELECT a.id, a.name, a.ip_address, a.mac_address, a.os, a.cpu, a.ram, a.last_api_sync, a.assigned_user_id, u.fullname as assigned_user_name,
                                   (SELECT COUNT(*) FROM agent_keys ak WHERE ak.mac_address = a.mac_address) as has_agent_key,
                                   (SELECT COUNT(*) FROM agent_keys ak WHERE ak.mac_address = a.mac_address AND ak.revoked_at IS NULL) as active_agent_key_count
                            FROM assets a LEFT JOIN users u ON u.id = a.assigned_user_id WHERE a.deleted_at IS NULL ORDER BY a.last_api_sync DESC, a.id DESC")->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($syncedAssets as $sa) {
                                if ($sa['assigned_user_id'] > 0) {
                                    $userId = (int)$sa['assigned_user_id'];
                                    if (!isset($userDevices[$userId])) {
                                        $userDevices[$userId] = [];
                                    }
                                    $userDevices[$userId][] = $sa['name'];
                                    $userKey = ensureUserApiKey($pdo, $userId);
                                    if (!empty($sa['mac_address']) && !empty($userKey)) {
                                        $pdo->prepare("UPDATE agent_keys SET registered_by_client_id = ? WHERE mac_address = ? AND (registered_by_client_id IS NULL OR registered_by_client_id != ?)")
                                            ->execute([$userKey, $sa['mac_address'], $userKey]);
                                    }
                                }
                            }

                            $allUsers = $pdo->query("SELECT u.id, u.fullname, u.mail, u.role, ak.client_id, ak.client_secret_plain FROM users u LEFT JOIN api_keys ak ON ak.user_id = u.id AND ak.revoked_at IS NULL WHERE u.deleted_at IS NULL AND u.username != 'customer_gateway' AND u.mail != 'system_customer_gateway@eaprimus.local' ORDER BY u.fullname ASC")->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {}
                    }
                    ?>
                    <form method="POST" action="anasayfa?route=sistem-ayarlari">
                        <?= csrf_field() ?>
                        <input type="hidden" name="section" value="api">
                        <div class="card shadow-sm" style="border-radius:12px; border:none;">
                            <div class="card-header" style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                                <h4 class="mb-0 font-weight-bold"><i class="fas fa-code mr-2 text-primary"></i><?= $isTr ? 'RESTful API Entegrasyonu' : 'RESTful API Integration' ?></h4>
                            </div>
                            <div class="card-body px-4 py-4">
                                <div class="custom-control custom-switch mb-4">
                                    <input type="checkbox" name="api_enabled" class="custom-control-input" id="sw_api_enabled" <?= s('api_enabled') == '1' ? 'checked' : '' ?> onchange="this.closest('form').submit()">
                                    <label class="custom-control-label font-weight-bold text-primary" for="sw_api_enabled"><?= $isTr ? 'API Girişini Aktifleştir' : 'Enable API Access' ?></label>
                                    <small class="d-block text-muted mt-1"><?= $isTr ? 'Dış sistemler ve envanter ajanlarının sisteme bağlanabilmesi için bu ayarın açık olması gerekir.' : 'This setting must be enabled for external systems and inventory agents to connect to the system.' ?></small>
                                </div>

                                <?php if (s('api_enabled') !== '1'): ?>
                                 <div class="alert alert-warning d-flex align-items-center mb-4" style="border-radius:10px;">
                                     <i class="fas fa-exclamation-triangle mr-3 fa-lg"></i>
                                     <div>
                                         <strong><?= $isTr ? 'API Kapalı!' : 'API Disabled!' ?></strong><br>
                                         <span class="small"><?= $isTr ? 'Uzak bağlantı ve genel API entegrasyonu (SAP, HR vb. dış sistemlerin veri alışverişi) için API\'nin açık olması gerekir.' : 'API must be enabled for remote connections and general API integrations (SAP, HR, etc.).' ?></span>
                                     </div>
                                 </div>
                                 <?php endif; ?>

                                 <div id="api_credentials_section" <?= s('api_enabled') !== '1' ? 'style="opacity: 0.55; pointer-events: none; user-select: none;"' : '' ?>>
                                     <div class="row">
                                         <div class="col-md-12 form-group">
                                             <label class="font-weight-bold">Eaprimus Key / API Key (Client ID)</label>
                                             <div class="input-group">
                                                 <input type="text" name="custom_api_client_id" class="form-control font-mono bg-white" value="<?= htmlspecialchars($apiKey) ?>" style="font-family: monospace;" <?= s('api_enabled') !== '1' ? 'disabled' : '' ?>>
                                                 <div class="input-group-append">
                                                     <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard(this.closest('.input-group').querySelector('input').value);"><i class="fas fa-copy"></i></button>
                                                     <button class="btn btn-primary font-weight-bold" type="submit"><i class="fas fa-save mr-1"></i><?= $isTr ? 'Kaydet' : 'Save' ?></button>
                                                 </div>
                                             </div>
                                             
                                             <!-- Eaprimus Key Ne İşe Yarar Bilgilendirme Kutusu (TR & EN) -->
                                             <div class="alert alert-info border-0 shadow-sm mt-3 mb-2" style="border-radius:12px; background: rgba(59, 130, 246, 0.08); color: #1e40af; font-size:13px; padding:16px;">
                                                 <h6 class="font-weight-bold mb-2" style="color: #1e3a8a;"><i class="fas fa-key mr-2 text-primary"></i><?= $isTr ? 'Eaprimus Key (API İstemci Anahtarı) Ne İşe Yarar?' : 'What is the Eaprimus Key (API Client Key) Used For?' ?></h6>
                                                 <p class="mb-2" style="line-height:1.5;">
                                                     <strong>TR:</strong> Eaprimus Key, sisteminizin dış yazılımlar (SAP, İK yazılımları, özel entegrasyonlar), PowerShell/Bash masaüstü donanım ajanları ve web servisleri ile güvenli haberleşmesini sağlayan ana kimlik doğrulama anahtarıdır. Ajanlar ve uzak sistemler sunucuya bağlanırken bu anahtarı (Client ID) ve API Secret bilgisini kullanarak yetkilendirilir.
                                                 </p>
                                                 <hr style="border-top: 1px dashed rgba(30, 64, 175, 0.2); margin: 10px 0;">
                                                 <p class="mb-0" style="line-height:1.5; opacity: 0.9;">
                                                     <strong>EN:</strong> The Eaprimus Key (API Client ID) is the primary authentication key that enables your system to securely communicate with external software (SAP, HR systems, custom integrations), PowerShell/Bash desktop inventory agents, and web services. External agents and API clients use this Key along with the API Secret to authenticate their requests to the server.
                                                 </p>
                                             </div>
                                         </div>
                                         <div class="col-md-12 form-group">
                                             <label class="font-weight-bold">API Secret (Client Secret)</label>
                                             <div class="input-group">
                                                 <input type="password" id="api_secret_field" class="form-control bg-light font-mono" value="<?= htmlspecialchars($apiSecret) ?>" readonly style="font-family: monospace;" <?= s('api_enabled') !== '1' ? 'disabled' : '' ?>>
                                                 <div class="input-group-append">
                                                     <button class="btn btn-outline-secondary" type="button" onclick="const f=document.getElementById('api_secret_field'); f.type = f.type==='password'?'text':'password';" <?= s('api_enabled') !== '1' ? 'disabled' : '' ?>><i class="fas fa-eye"></i></button>
                                                     <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('<?= htmlspecialchars($apiSecret) ?>');" <?= s('api_enabled') !== '1' ? 'disabled' : '' ?>><i class="fas fa-copy"></i></button>
                                                 </div>
                                             </div>
                                             <small class="text-danger font-weight-bold mt-1 d-block"><i class="fas fa-exclamation-triangle mr-1"></i> <?= $isTr ? 'Dikkat: API Secret anahtarını kimseyle paylaşmayınız!' : 'Attention: Never share your API Secret with anyone!' ?></small>
                                         </div>
                                     </div>
                                    </div>

                                    <!-- Global Ajan İndirme Butonu -->
                                    <?php if (s('api_enabled') == '1'): ?>
                                    <div class="alert alert-custom-success mb-2 mt-4" style="background-color: rgba(46, 204, 113, 0.08); border-color: rgba(46, 204, 113, 0.2); color: #27ae60; border-radius: 12px; padding: 20px;">
                                        <h6 class="font-weight-bold mb-2"><i class="fas fa-microchip mr-2"></i> <?= $isTr ? "Genel Endpoint Agent (Ajan) İndir" : "Download Global Endpoint Agent" ?></h6>
                                        <p class="small mb-3" style="color: #2c3e50; opacity: 0.85;"><?= $isTr ? "Cihazlarınızdaki yazılım ve donanım envanterini otomatik toplamak için hazırlanan PowerShell (Windows) veya Bash (Linux) ajanını indirebilirsiniz." : "Download the PowerShell (Windows) or Bash (Linux) agent to automatically collect software and hardware inventory from your devices." ?></p>
                                        <a href="<?= rtrim($base_url, '/') ?>/ajax/download_agent.php" class="btn btn-sm btn-success font-weight-bold mr-2"><i class="fab fa-windows mr-1"></i> Windows (Eaprimus-Ajan.bat)</a>
                                        <a href="<?= rtrim($base_url, '/') ?>/ajax/download_agent_linux.php" class="btn btn-sm btn-info font-weight-bold"><i class="fab fa-linux mr-1"></i> Linux (Eaprimus-Ajan.sh)</a>
                                    </div>
                                    <?php endif; ?>

                                </div>
                            <div class="card-footer text-right" style="background:transparent; border-top:1px solid #eee;">
                                <button type="button" class="btn btn-danger font-weight-bold px-4" id="btnRegenerateKeys" <?= s('api_enabled') !== '1' ? 'disabled style="opacity: 0.55; pointer-events: none;"' : '' ?>>
                                    <i class="fas fa-sync-alt mr-1"></i><?= $isTr ? 'Anahtarları Yeniden Üret' : 'Regenerate Keys' ?>
                                </button>
                            </div>
                        </div>

                    <!-- --- DOSYA YÜKLEME & FORMAT GÜVENLİK AYARLARI --- -->
                    <div class="card shadow-sm mt-4" style="border-radius:12px; border:none;">
                        <div class="card-header" style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                            <h4 class="mb-0 font-weight-bold"><i class="fas fa-file-upload mr-2 text-warning"></i><?= $isTr ? 'Bilet & Dosya Yüklenebilir Format Yönetimi' : 'Allowed File Upload Extensions' ?></h4>
                        </div>
                        <div class="card-body px-4 py-4">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= $isTr ? 'İzin Verilen Dosya Uzantıları (Virgül ile ayırarak yazınız)' : 'Allowed File Extensions (Comma separated)' ?></label>
                                <div class="input-group">
                                    <input type="text" name="allowed_upload_extensions" class="form-control bg-white font-mono" value="<?= htmlspecialchars(s('allowed_upload_extensions', 'pdf, png, jpg, jpeg, webp, gif, doc, docx, xls, xlsx, txt, zip, rar, 7z, csv')) ?>">
                                    <div class="input-group-append">
                                        <button class="btn btn-primary font-weight-bold" type="submit"><i class="fas fa-save mr-1"></i><?= $isTr ? 'Kaydet' : 'Save' ?></button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Bilgilendirme Kutusu (TR & EN) -->
                            <div class="alert alert-warning border-0 shadow-sm mt-3 mb-0" style="border-radius:12px; background: rgba(245, 158, 11, 0.08); color: #b45309; font-size:13px; padding:16px;">
                                <h6 class="font-weight-bold mb-2" style="color: #92400e;"><i class="fas fa-shield-alt mr-2 text-warning"></i><?= $isTr ? 'Dosya Yükleme Format Yönetimi Ne İşe Yarar?' : 'What is File Extension Management Used For?' ?></h6>
                                <p class="mb-2" style="line-height:1.5;">
                                    <strong>TR:</strong> Kullanıcıların ve teknik personelin bilet açarken veya yanıt yazarken sisteme yükleyebileceği güvenli dosya uzantılarını buradan yönetebilirsiniz. <u>.php, .exe, .sh, .bat, .py, .js</u> gibi sunucu tarafında çalıştırılabilir tüm tehlikeli script dosyaları güvenlik nedeniyle otomatik olarak engellenir.
                                </p>
                                <hr style="border-top: 1px dashed rgba(180, 83, 9, 0.2); margin: 10px 0;">
                                <p class="mb-0" style="line-height:1.5; opacity: 0.9;">
                                    <strong>EN:</strong> Manage the safe file formats users and agents can upload when opening or replying to tickets. Dangerous executable script files like <u>.php, .exe, .sh, .bat, .py, .js</u> are automatically blocked by the server security layer even if listed.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- --- VERİTABANI ŞİFRELEME ANAHTARI (EAPRIMUS_KEY) --- -->
                    <div class="card shadow-sm mt-4" style="border-radius:12px; border:none;">
                        <div class="card-header" style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                            <h4 class="mb-0 font-weight-bold"><i class="fas fa-lock mr-2 text-danger"></i><?= $isTr ? 'Veritabanı Şifreleme Anahtarı (EAPRIMUS_KEY)' : 'Database Master Encryption Key (EAPRIMUS_KEY)' ?></h4>
                        </div>
                        <div class="card-body px-4 py-4">
                            <div class="form-group">
                                <label class="font-weight-bold"><?= $isTr ? 'Eaprimus Şifreleme Anahtarı (Master Security Key)' : 'Eaprimus Encryption Key (Master Security Key)' ?></label>
                                <div class="input-group">
                                    <input type="password" id="eaprimus_key_field" name="eaprimus_key" class="form-control font-mono bg-white" value="<?= htmlspecialchars(s('eaprimus_key', defined('EAPRIMUS_KEY') ? EAPRIMUS_KEY : 'sbABk64ppqN2Uuy-Eaprimus')) ?>" style="font-family: monospace;">
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary" type="button" onclick="const f=document.getElementById('eaprimus_key_field'); f.type = f.type==='password'?'text':'password';"><i class="fas fa-eye"></i></button>
                                        <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard(document.getElementById('eaprimus_key_field').value);"><i class="fas fa-copy"></i></button>
                                        <button class="btn btn-primary font-weight-bold" type="submit"><i class="fas fa-save mr-1"></i><?= $isTr ? 'Kaydet' : 'Save' ?></button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- EAPRIMUS_KEY Ne İşe Yarar Bilgilendirme Kutusu (TR & EN) -->
                            <div class="alert alert-danger border-0 shadow-sm mt-3 mb-0" style="border-radius:12px; background: rgba(239, 68, 68, 0.08); color: #991b1b; font-size:13px; padding:16px;">
                                <h6 class="font-weight-bold mb-2" style="color: #7f1d1d;"><i class="fas fa-key mr-2 text-danger"></i><?= $isTr ? 'EAPRIMUS_KEY Ne İşe Yarar ve Nasıl Çalışır?' : 'What is EAPRIMUS_KEY and How Does it Work?' ?></h6>
                                <p class="mb-2" style="line-height:1.5;">
                                    <strong>TR:</strong> EAPRIMUS_KEY, veritabanında saklanan hassas kullanıcı verilerinin (T.C. Kimlik No vb.) AES-256 algoritmasıyla veritabanında şifrelenerek saklanmasında ve geri okunmasında kullanılan master anahtardır. Müşteriler kendi özel güvenlik politikalarına göre bu anahtarı değiştirebilir.
                                </p>
                                <div class="p-2 my-2 rounded font-weight-bold" style="background: rgba(239, 68, 68, 0.15); color: #7f1d1d; font-size: 12px;">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> <?= $isTr ? 'ÖNEMLİ UYARI: Veritabanında hali hazırda kayıtlı şifreli veriler var iken bu anahtar değiştirilirse, eski kayıtlar yeni anahtarla çözülemez ve okunamaz hale gelir!' : 'IMPORTANT WARNING: If this key is changed after data is encrypted in the database, existing encrypted records cannot be decrypted with the new key!' ?>
                                </div>
                                <hr style="border-top: 1px dashed rgba(153, 27, 27, 0.2); margin: 10px 0;">
                                <p class="mb-0" style="line-height:1.5; opacity: 0.9;">
                                    <strong>EN:</strong> EAPRIMUS_KEY is the master security key used by MySQL AES-256 functions to encrypt and decrypt sensitive user data (such as National Identity Numbers) in the database. System administrators can customize this key according to corporate security standards.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="card shadow-sm mt-4" style="border-radius:12px; border:none;">
                        <div class="card-header" style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                            <h4 class="mb-0 font-weight-bold"><i class="fas fa-desktop mr-2 text-success"></i><?= $isTr ? 'Ajan Yüklü Cihazlar (Otomatik Senkronize)' : 'Agent Synced Devices' ?></h4>
                        </div>
                        <div class="card-body px-4 py-4">
                            <div class="alert alert-warning border-0 shadow-sm mb-4" style="border-radius:12px; background: rgba(245, 158, 11, 0.08); color: #d97706; font-size:13px; padding:15px;">
                                <h6 class="font-weight-bold mb-1"><i class="fas fa-info-circle mr-2"></i><?= $isTr ? 'Görevi Arka Planda (Servis Olarak) Çalıştırma' : 'Running the Task in the Background' ?></h6>
                                <p class="mb-0"><?= $isTr ? 'İndirdiğiniz <b>Eaprimus-Ajan.bat</b> dosyasına sağ tıklayıp "Yönetici Olarak Çalıştır" derseniz, ajan bilgisayara otomatik kurulur. Sistem açılışında bir defa çalışır ve sonrasında yönetici panelinden tetikleme aldığında veya donanım değişikliği algılandığında arka planda otomatik senkronizasyon yapar.' : 'Right-click <b>Eaprimus-Ajan.bat</b> and select "Run as Administrator" to install the agent. It runs once at system boot, and then syncs in the background when triggered from the admin panel or if a hardware change is detected.' ?></p>
                            </div>

                            <?php if (empty($syncedAssets)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-network-wired fa-2x mb-2 text-light"></i>
                                    <p class="mb-0"><?= $isTr ? 'Henüz hiçbir cihaz API üzerinden senkronize olmadı.' : 'No devices synced via API yet.' ?></p>
                                </div>
                            <?php else: ?>
                                <style>
                                .selectable-text {
                                    user-select: text !important;
                                    -webkit-user-select: text !important;
                                    -moz-user-select: text !important;
                                    -ms-user-select: text !important;
                                }
                                @keyframes rowFlash {
                                    0% { background-color: rgba(23, 162, 184, 0.25); }
                                    100% { background-color: transparent; }
                                }
                                .row-flash-highlight {
                                    animation: rowFlash 3s ease-out;
                                }
                                </style>
                                <div class="table-responsive selectable-text" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                                        <thead class="bg-light text-muted">
                                            <tr>
                                                <th><?= $isTr ? 'Cihaz Adı' : 'Device Name' ?></th>
                                                <th>IP & MAC</th>
                                                <th><?= $isTr ? 'Sistem & Donanım' : 'OS & Hardware' ?></th>
                                                <th><?= $isTr ? 'Son Senkronizasyon' : 'Last Sync' ?></th>
                                                <th class="text-center"><?= $isTr ? 'İşlem' : 'Action' ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($syncedAssets as $sa):
                                                $lastSync = $sa['last_api_sync'];
                                                $statusBadge = '';
                                                if (empty($lastSync)) {
                                                    if ($sa['assigned_user_id'] > 0) {
                                                        $statusBadge = '<span class="badge badge-danger font-weight-bold" style="padding: 5px 8px; border-radius: 6px;" title="' . ($isTr ? 'Zimmetli ama hiç veri göndermedi' : 'Assigned but never synced') . '"><i class="fas fa-exclamation-circle mr-1"></i> ' . ($isTr ? 'İlk Senkronizasyon Bekleniyor' : 'Waiting for Initial Sync') . '</span>';
                                                    } else {
                                                        $statusBadge = '<span class="badge badge-secondary font-weight-bold" style="padding: 5px 8px; border-radius: 6px;"><i class="fas fa-question-circle mr-1"></i> ' . ($isTr ? 'Senkronizasyon Yok' : 'No Sync') . '</span>';
                                                    }
                                                } else {
                                                    $diffSeconds = time() - strtotime($lastSync);
                                                    $diffDays = $diffSeconds / 86400;
                                                    if ($diffDays >= 2) {
                                                        $statusBadge = '<span class="badge badge-danger font-weight-bold" style="padding: 5px 8px; border-radius: 6px;" title="' . ($isTr ? 'Son güncelleme: ' : 'Last update: ') . date('d.m.Y H:i', strtotime($lastSync)) . '"><i class="fas fa-clock mr-1"></i> ' . ($isTr ? '2 Gündür Senkronize Olmadı' : 'No sync for 2 days') . ' (' . date('d.m.Y', strtotime($lastSync)) . ')</span>';
                                                    } else {
                                                        $statusBadge = '<span class="badge badge-success font-weight-bold" style="padding: 5px 8px; border-radius: 6px;"><i class="fas fa-check-circle mr-1"></i> ' . date('d.m.Y H:i', strtotime($lastSync)) . '</span>';
                                                    }
                                                }
                                            ?>
                                                <tr data-device-name="<?= htmlspecialchars($sa['name']) ?>">
                                                    <td>
                                                        <a href="varlik-detay/<?= (int)$sa['id'] ?>" class="font-weight-bold text-dark"><i class="fas fa-laptop mr-2 text-muted"></i><?= htmlspecialchars($sa['name']) ?></a>
                                                        <?php if ($sa['assigned_user_name']): ?>
                                                            <span class="d-block text-muted" style="font-size:11px; padding-left: 20px;"><i class="fas fa-user mr-1 text-info"></i><?= htmlspecialchars($sa['assigned_user_name']) ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="d-block font-mono" style="font-size:11px;"><?= htmlspecialchars($sa['ip_address'] ?: '-') ?></span>
                                                        <span class="text-muted d-block" style="font-size:10px;"><?= htmlspecialchars($sa['mac_address'] ?: '-') ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="d-block font-weight-bold" style="font-size:11px;"><?= htmlspecialchars($sa['os'] ?: '-') ?></span>
                                                        <span class="text-muted d-block" style="font-size:10px;"><?= htmlspecialchars($sa['cpu'] ?: '-') ?> (<?= htmlspecialchars($sa['ram'] ?: '-') ?>)</span>
                                                    </td>
                                                    <td>
                                                        <?= $statusBadge ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php 
                                                        $isAgentRevoked = ((int)($sa['has_agent_key'] ?? 0) > 0 && (int)($sa['active_agent_key_count'] ?? 0) === 0);
                                                        if ($isAgentRevoked):
                                                        ?>
                                                            <button type="button" class="btn btn-sm btn-outline-danger font-weight-bold" style="border-radius: 8px; opacity: 0.65; cursor: not-allowed;" disabled title="<?= $isTr ? 'Bu cihazın ajan yetkisi iptal edilmiştir.' : 'This device agent authorization has been revoked.' ?>">
                                                                <i class="fas fa-ban mr-1"></i><?= $isTr ? 'Yetki İptal' : 'Auth Revoked' ?>
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold" style="border-radius: 8px;" onclick="triggerAgentSync('<?= $sa['id'] ?>', '<?= htmlspecialchars($sa['name'], ENT_QUOTES) ?>', '<?= $sa['last_api_sync'] ?? '' ?>')">
                                                                <i class="fas fa-sync-alt mr-1"></i><?= $isTr ? 'Şimdi Tetikle' : 'Sync Now' ?>
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Kullanıcı API Anahtarları ve Ajan Dağıtım Kartı -->
                    <div class="card shadow-sm mt-4" style="border-radius:12px; border:none;">
                        <div class="card-header" style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                            <h4 class="mb-0 font-weight-bold"><i class="fas fa-users-cog mr-2 text-info"></i><?= $isTr ? 'Kullanıcı API Anahtarları ve Ajan Dağıtımı' : 'User API Keys & Agent Deployment' ?></h4>
                        </div>
                        <div class="card-body px-4 py-4">
                            <p class="small text-muted mb-4">
                                <?= $isTr
                                    ? 'Personellerin sisteme giriş yapmasına gerek kalmadan, yöneticiler buradan kullanıcıya özel ajan dosyalarını (BAT) indirebilir ve cihazlara kurabilir.'
                                    : 'Admins can generate API credentials and download personalized agent files (BAT) here without requiring employees to log in.' ?>
                            </p>

                            <!-- Switches (Benzersiz Anahtar, SSL Doğrulama) -->
                            <div class="custom-control custom-switch mb-3" id="api_auto_register_switch_container">
                                <input type="checkbox" name="api_agent_auto_register" class="custom-control-input" id="sw_api_agent_auto_register" <?= s('api_agent_auto_register') == '1' ? 'checked' : '' ?> onchange="this.closest('form').submit()">
                                <label class="custom-control-label font-weight-bold text-primary" for="sw_api_agent_auto_register"><?= $isTr ? 'Ajanlar için Benzersiz Anahtar (Otomatik Kayıt)' : 'Unique Key for Agents (Auto-Registration)' ?></label>
                                <small class="d-block text-muted mt-1"><?= $isTr ? 'Aktif edildiğinde, her ajan ilk senkronizasyonda geçici bir aktivasyon tokenı ile kayıt olur ve kendine özel benzersiz API anahtarları alır. Donanım anahtarları Windows DPAPI ile diskte şifreli saklanır.' : 'When enabled, each agent registers on the first sync using a temporary activation token and receives its own unique API keys. Hardware credentials are encrypted on disk via Windows DPAPI.' ?></small>
                            </div>

                            <div class="custom-control custom-switch mb-4" id="api_verify_ssl_switch_container" <?= !$isHttps ? 'style="opacity: 0.55; cursor: not-allowed;"' : '' ?>>
                                <input type="checkbox" name="api_verify_ssl" class="custom-control-input" id="sw_api_verify_ssl" <?= ($isHttps && s('api_verify_ssl') == '1') ? 'checked' : '' ?> <?= !$isHttps ? 'disabled' : '' ?> onchange="this.closest('form').submit()">
                                <label class="custom-control-label font-weight-bold text-primary" for="sw_api_verify_ssl">
                                    <?= $isTr ? 'Ajan SSL Sertifikasını Doğrulasın (HTTPS Güvenliği)' : 'Verify Agent SSL Certificate (HTTPS Security)' ?>
                                    <?= !$isHttps ? ' <span class="badge badge-secondary ml-1" style="font-size:10px; font-weight: normal; background-color: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0;"><i class="fas fa-lock mr-1"></i> ' . ($isTr ? 'HTTPS Gerekli' : 'HTTPS Required') . '</span>' : '' ?>
                                </label>
                                <small class="d-block text-muted mt-1">
                                    <?= $isTr ? 'Aktif edildiğinde, ajan sadece geçerli bir SSL sertifikasına sahip HTTPS bağlantılarını kabul eder. Ortadaki Adam (MitM) saldırılarını engeller.' : 'When enabled, the agent only accepts HTTPS connections with valid SSL certificates. Prevents Man-in-the-Middle (MitM) attacks.' ?>
                                    <?php if (!$isHttps): ?>
                                        <span class="text-danger d-block mt-1 font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i> <?= $isTr ? 'Sistem URL adresi HTTP (güvensiz) olduğu için bu ayar etkinleştirilemez. Lütfen Genel Ayarlar altından Site URL\'ini HTTPS yapın.' : 'This setting cannot be enabled because the System URL is HTTP (insecure). Please change Site URL to HTTPS under General Settings.' ?></span>
                                    <?php endif; ?>
                                </small>
                            </div>

                            <!-- Personel ve Zimmet Değişiklikleri Hakkında Bilgilendirme -->
                            <div class="alert alert-info border-0 shadow-sm p-3 mb-4" style="border-radius:10px; background-color: #f0f7ff; color: #1e3a8a; border-left: 4px solid #3b82f6 !important;">
                                <div class="d-flex">
                                    <div class="mr-3">
                                        <i class="fas fa-info-circle fa-lg text-primary mt-1"></i>
                                    </div>
                                    <div>
                                        <h6 class="font-weight-bold mb-2 text-primary" style="font-size: 14px;"><?= $isTr ? 'Personel ve Zimmet Değişiklikleri Hakkında' : 'About Personnel and Custody Changes' ?></h6>
                                        <p class="small mb-2" style="line-height: 1.5;">
                                            <strong><?= $isTr ? 'Ajan cihaz odaklı çalışır:' : 'Agent works device-oriented:' ?></strong> <?= $isTr ? 'Ajan, bilgisayarı MAC Adresi ve Seri Numarası ile tanır. Bilgisayarı kullanan kişi değiştiğinde veya personel işten ayrıldığında BAT dosyasını yeniden çalıştırmanıza veya değiştirmenize gerek yoktur.' : 'The agent recognizes the computer by MAC Address and Serial Number. When the person using the computer changes or the personnel leaves, you do not need to run or change the BAT file again.' ?>
                                        </p>
                                        <p class="small mb-0" style="line-height: 1.5; color: #4b5563;">
                                            <?= $isTr ? 'Zimmet yönetimi tamamen Eaprimus panelinden yönetilir. Personel ayrıldığında ilgili varlıktan zimmeti düşüp yeni personele zimmetlemeniz yeterlidir; arka plandaki ajan donanım bilgilerini otomatik güncellemeye devam eder.' : 'Custody management is entirely managed from the Eaprimus panel. When the personnel leaves, simply de-allocate the custody from the relevant asset and assign it to the new personnel; the agent in the background continues to update hardware information automatically.' ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                                    <thead class="bg-light text-muted">
                                        <tr>
                                            <th><?= $isTr ? 'Ad Soyad' : 'Full Name' ?></th>
                                            <th><?= $isTr ? 'E-Posta' : 'Email' ?></th>
                                            <th>API Key (Client ID)</th>
                                            <th class="text-right"><?= $isTr ? 'İşlemler' : 'Actions' ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($allUsers as $usr): ?>
                                            <tr data-user-key="<?= htmlspecialchars($usr['client_id'] ?? '') ?>">
                                                <td>
                                                    <span class="font-weight-bold text-dark"><?= htmlspecialchars($usr['fullname']) ?></span>
                                                    <?php
                                                    $userId = (int)$usr['id'];
                                                    if (!empty($userDevices[$userId])):
                                                        foreach ($userDevices[$userId] as $devName):
                                                    ?>
                                                        <a href="javascript:void(0);" onclick="highlightDevice('<?= htmlspecialchars($devName, ENT_QUOTES) ?>')" class="badge ml-2" style="font-size: 10px; padding: 3px 6px; border-radius: 4px; background-color: rgba(23, 162, 184, 0.1); color: #17a2b8; border: 1px solid rgba(23, 162, 184, 0.2); cursor: pointer; text-decoration: none;" title="<?= $isTr ? 'Cihazı Yukarıda Vurgula' : 'Highlight Device Above' ?>">
                                                            <i class="fas fa-desktop mr-1"></i><?= htmlspecialchars($devName) ?>
                                                        </a>
                                                    <?php
                                                        endforeach;
                                                    endif;
                                                    ?>
                                                </td>
                                                <td><?= htmlspecialchars($usr['mail']) ?></td>
                                                <td>
                                                    <?php if ($usr['client_id']): ?>
                                                        <a href="javascript:void(0)" onclick="highlightDeviceByKey('<?= htmlspecialchars($usr['client_id']) ?>')" title="<?= $isTr ? 'Bu anahtar ile kayıt edilen cihazı aşağıda vurgula' : 'Highlight the device registered with this key below' ?>"><code class="text-primary"><?= htmlspecialchars($usr['client_id']) ?></code></a>
                                                    <?php else: ?>
                                                        <span class="text-muted"><i class="fas fa-times-circle mr-1"></i> <?= $isTr ? 'Anahtar Yok' : 'No Key' ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-right">
                                                    <?php if ($usr['client_id']): ?>
                                                        <a href="<?= rtrim($base_url, '/') ?>/ajax/download_agent.php?personal=1&user_id=<?= $usr['id'] ?>" class="btn btn-xs btn-success font-weight-bold mr-1" title="<?= $isTr ? 'Windows için kişisel ajan indir' : 'Download personal agent for Windows' ?>">
                                                            <i class="fab fa-windows mr-1"></i> Windows (BAT)
                                                        </a>
                                                        <a href="<?= rtrim($base_url, '/') ?>/ajax/download_agent_linux.php?personal=1&user_id=<?= $usr['id'] ?>" class="btn btn-xs btn-info font-weight-bold mr-1" title="<?= $isTr ? 'Linux için kişisel ajan indir' : 'Download personal agent for Linux' ?>">
                                                            <i class="fab fa-linux mr-1"></i> Linux (SH)
                                                        </a>
                                                        <button type="button" class="btn btn-xs btn-secondary font-weight-bold mr-1" onclick="copyLinuxCmd('<?= $usr['id'] ?>', '<?= htmlspecialchars($usr['fullname'], ENT_QUOTES) ?>', '<?= htmlspecialchars($usr['client_id']) ?>')" title="<?= $isTr ? 'Linux terminalinden direkt kurmak için tek satırlık kodu kopyala' : 'Copy single-line command to install directly from Linux CLI' ?>">
                                                            <i class="fas fa-terminal mr-1"></i> Linux Kurulum Kodu
                                                        </button>
                                                        <button type="button" onclick="revokeUserKey(<?= $usr['id'] ?>, '<?= htmlspecialchars($usr['fullname']) ?>')" class="btn btn-xs btn-outline-danger font-weight-bold">
                                                            <i class="fas fa-ban"></i> <?= $isTr ? 'İptal Et' : 'Revoke' ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" onclick="generateUserKey(<?= $usr['id'] ?>, '<?= htmlspecialchars($usr['fullname']) ?>')" class="btn btn-xs btn-info font-weight-bold">
                                                            <i class="fas fa-plus mr-1"></i> <?= $isTr ? 'Anahtar Üret' : 'Generate Key' ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if (s('api_agent_auto_register') === '1'): ?>
                            <div class="card shadow-none border mt-4" style="border-radius:12px; overflow: hidden;">
                                <div class="card-header bg-light py-3 border-bottom">
                                    <h6 class="mb-0 font-weight-bold"><i class="fas fa-key mr-2 text-warning"></i><?= $isTr ? 'Kayıtlı Cihaz Ajanları ve Yetkileri' : 'Registered Device Agents & Keys' ?></h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0" style="font-size:13px; vertical-align: middle;">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th><?= $isTr ? 'Cihaz Adı' : 'Device Name' ?></th>
                                                    <th><?= $isTr ? 'MAC Adresi' : 'MAC Address' ?></th>
                                                    <th><?= $isTr ? 'API Key (Client ID)' : 'API Key (Client ID)' ?></th>
                                                    <th><?= $isTr ? 'Kayıt Tarihi' : 'Registration Date' ?></th>
                                                    <th><?= $isTr ? 'Durum / Yetki' : 'Status / Auth' ?></th>
                                                    <th class="text-right"><?= $isTr ? 'İşlemler' : 'Actions' ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                // Fetch agent keys from DB
                                                $agentKeys = [];
                                                try {
                                                    $agentKeys = $pdo->query("SELECT ak.*, a.deleted_at as asset_deleted_at, a.id as asset_id, a.assigned_user_id,
                                                         u_asset.fullname as assigned_user_fullname,
                                                         u1.fullname as installer_user_fullname,
                                                         u2.fullname as token_installer_fullname,
                                                         COALESCE(u_asset.fullname, u1.fullname, u2.fullname) as registering_user_fullname,
                                                         COALESCE(apk_asset.revoked_at, apk1.revoked_at, apk2.revoked_at) as user_key_revoked_at,
                                                         COALESCE(apk_asset.client_id, apk1.client_id, apk2.client_id) as owner_user_key
                                                         FROM agent_keys ak 
                                                         LEFT JOIN assets a ON ak.mac_address = a.mac_address 
                                                         LEFT JOIN users u_asset ON a.assigned_user_id = u_asset.id
                                                         LEFT JOIN api_keys apk_asset ON u_asset.id = apk_asset.user_id AND apk_asset.revoked_at IS NULL
                                                         LEFT JOIN api_keys apk1 ON ak.registered_by_client_id = apk1.client_id
                                                         LEFT JOIN users u1 ON apk1.user_id = u1.id
                                                         LEFT JOIN agent_activation_tokens act ON ak.registered_by_client_id = act.token
                                                         LEFT JOIN users u2 ON act.created_by = u2.id
                                                         LEFT JOIN api_keys apk2 ON act.created_by = apk2.user_id AND apk2.revoked_at IS NULL
                                                         WHERE (a.deleted_at IS NULL OR a.id IS NULL) 
                                                         ORDER BY ak.id DESC")->fetchAll(PDO::FETCH_ASSOC);
                                                } catch (Exception $e) {}

                                                if (empty($agentKeys)):
                                                ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-4">
                                                            <i class="fas fa-network-wired fa-lg mb-2 d-block text-muted" style="opacity:0.4;"></i>
                                                            <?= $isTr ? 'Henüz kayıtlı bir cihaz ajanı bulunmuyor.' : 'No registered device agents found yet.' ?>
                                                        </td>
                                                    </tr>
                                                <?php
                                                else:
                                                    foreach ($agentKeys as $ak):
                                                        $isRevoked = !empty($ak['revoked_at']);
                                                ?>
                                                    <tr data-registered-by="<?= htmlspecialchars($ak['registered_by_client_id'] ?? '') ?>" data-owner-user-key="<?= htmlspecialchars($ak['owner_user_key'] ?? '') ?>">
                                                        <td class="font-weight-bold text-dark"><?= htmlspecialchars($ak['computer_name']) ?></td>
                                                        <td class="font-mono text-secondary"><?= htmlspecialchars($ak['mac_address']) ?></td>
                                                        <td class="font-mono text-secondary">
                                                            <?= htmlspecialchars($ak['client_id']) ?>
                                                            <?php if (!empty($ak['assigned_user_id'])): ?>
                                                                <br>
                                                                <a href="javascript:void(0)" onclick="highlightUserByKey('<?= htmlspecialchars($ak['owner_user_key'] ?? '') ?>')" class="badge badge-info text-white border mt-1" style="font-size:10px; background-color: #0284c7;" title="<?= $isTr ? 'Cihaz Zimmetli Kullanıcı' : 'Assigned Device Owner' ?>">
                                                                    <i class="fas fa-user-check mr-1"></i><?= htmlspecialchars($ak['assigned_user_fullname'] ?? $ak['registering_user_fullname']) ?>
                                                                </a>
                                                            <?php elseif (!empty($ak['registered_by_client_id'])): ?>
                                                                <br>
                                                                <?php if ($ak['user_key_revoked_at']): ?>
                                                                     <span class="badge badge-warning text-white border mt-1" style="font-size:10px; background-color: #ffc107;" title="<?= $isTr ? 'Bu anahtar iptal edildi' : 'This key is revoked' ?>">
                                                                         <i class="fas fa-exclamation-triangle mr-1"></i><?= $isTr ? 'İptal Edildi' : 'Revoked' ?> (<?= htmlspecialchars($ak['registering_user_fullname']) ?>)
                                                                     </span>
                                                                 <?php else: ?>
                                                                     <a href="javascript:void(0)" onclick="highlightUserByKey('<?= htmlspecialchars($ak['owner_user_key'] ?? '') ?>')" class="badge badge-light text-muted border mt-1" style="font-size:10px;" title="<?= $isTr ? 'Kurulum Yapan Yetkili (Cihaz Henüz Zimmetsiz/Boşta)' : 'Installation Admin (Device Currently Unassigned)' ?>">
                                                                         <i class="fas fa-user-cog mr-1"></i><?= htmlspecialchars($ak['registering_user_fullname'] ?? substr($ak['registered_by_client_id'], 0, 10)) ?> (<?= $isTr ? 'Zimmetsiz' : 'Unassigned' ?>)
                                                                     </a>
                                                                 <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= date('d.m.Y H:i', strtotime($ak['created_at'])) ?></td>
                                                        <td>
                                                            <?php if ($isRevoked): ?>
                                                                <span class="badge badge-danger px-2 py-1" style="border-radius:6px;"><?= $isTr ? 'Yetki İptal Edildi' : 'Revoked' ?></span>
                                                            <?php else: ?>
                                                                <span class="badge badge-success px-2 py-1" style="border-radius:6px;"><?= $isTr ? 'Aktif' : 'Active' ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-right">
                                                            <?php if (!$isRevoked): ?>
                                                                <button type="button" onclick="changeAgentOwner(<?= $ak['id'] ?>, '<?= htmlspecialchars($ak['computer_name'], ENT_QUOTES) ?>')" class="btn btn-xs btn-outline-primary font-weight-bold mr-1" style="border-radius: 6px; padding: 2px 8px;">
                                                                     <i class="fas fa-user-edit mr-1"></i> <?= $isTr ? 'Sahibi Değiştir' : 'Change Owner' ?>
                                                                 </button>
                                                                <button type="button" class="btn btn-xs btn-outline-danger btn-revoke-agent font-weight-bold" data-id="<?= $ak['id'] ?>" data-name="<?= htmlspecialchars($ak['computer_name']) ?>" style="border-radius: 6px; padding: 2px 8px;">
                                                                    <i class="fas fa-ban mr-1"></i> <?= $isTr ? 'İptal Et' : 'Revoke' ?>
                                                                </button>
                                                            <?php else: ?>
                                                                <button type="button" class="btn btn-xs btn-outline-secondary" disabled style="border-radius: 6px; padding: 2px 8px;">
                                                                    <i class="fas fa-ban mr-1"></i> <?= $isTr ? 'İptal Edildi' : 'Revoked' ?>
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php
                                                    endforeach;
                                                endif;
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                    </form>

                    <script>
                    var isTr = <?= $isTr ? 'true' : 'false' ?>;
                    function triggerAgentSync(assetId, deviceName, baselineSyncTime) {
                        Swal.fire({
                            title: isTr ? 'Senkronizasyon Sinyali Gönderiliyor...' : 'Sending Sync Signal...',
                            allowOutsideClick: false,
                            didOpen: () => { Swal.showLoading(); }
                         });

                         $.ajax({
                             url: 'anasayfa?route=sistem-ayarlari',
                             type: 'POST',
                             data: {
                                 action: 'trigger_agent_sync',
                                 asset_id: assetId,
                                 csrf_token: '<?= csrf_token() ?>'
                             },
                             dataType: 'json',
                             success: function(response) {
                                 if (response.status === 'success') {
                                     let timeLeft = 120;

                                     Swal.fire({
                                         title: isTr ? 'Ajan Yanıtı Bekleniyor...' : 'Waiting for Agent...',
                                         html: (isTr
                                             ? `<div class="text-left" style="font-size:13px; line-height:1.6;">
                                                    <div class="alert alert-info border-0 mb-3" style="border-radius:8px; font-size:12.5px;">
                                                        <i class="fas fa-info-circle mr-1"></i> <strong>Sinyal gönderildi!</strong><br>
                                                        Hedef bilgisayar arka planda çalışıyorsa <b>otomatik alacaktır</b>.<br>
                                                        Ajan kurulu ama arka planda çalışmıyorsa, o bilgisayarda
                                                        <b>BAT dosyasını Yönetici olarak çalıştırın</b> — sync otomatik gerçekleşir.
                                                    </div>
                                                    <div class="text-center mb-2">
                                                        <b id="sync-countdown-text" style="font-size:13px; color:#6c757d;">Yanıt bekleniyor... (<span id="sync-secs">${timeLeft}</span>s)</b>
                                                    </div>
                                                    <div class="progress shadow-sm" style="height: 18px; border-radius: 9px; background-color: #e2e8f0;">
                                                        <div id="sync-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%; background-color: #17a2b8 !important; color:#fff; font-size:11px; line-height:18px;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                                                    </div>
                                                </div>`
                                             : `<div class="text-left" style="font-size:13px; line-height:1.6;">
                                                    <div class="alert alert-info border-0 mb-3" style="border-radius:8px; font-size:12.5px;">
                                                        <i class="fas fa-info-circle mr-1"></i> <strong>Signal sent!</strong><br>
                                                        If the target computer is running the agent in the background, it will <b>pick this up automatically</b>.<br>
                                                        If the agent is not running, go to that computer and <b>run the BAT file as Administrator</b> — sync will happen automatically.
                                                    </div>
                                                    <div class="text-center mb-2">
                                                        <b id="sync-countdown-text" style="font-size:13px; color:#6c757d;">Waiting for response... (<span id="sync-secs">${timeLeft}</span>s)</b>
                                                    </div>
                                                    <div class="progress shadow-sm" style="height: 18px; border-radius: 9px; background-color: #e2e8f0;">
                                                        <div id="sync-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%; background-color: #17a2b8 !important; color:#fff; font-size:11px; line-height:18px;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                                                    </div>
                                                </div>`),
                                         icon: 'info',
                                         allowOutsideClick: false,
                                         showConfirmButton: false,
                                         didOpen: () => {
                                             Swal.showLoading();
                                         }
                                     });

                                     let checkInterval = setInterval(function() {
                                         timeLeft -= 2;
                                         const pct = Math.min(100, Math.round(((120 - timeLeft) / 120) * 100));
                                         const secsEl = document.getElementById('sync-secs');
                                         if (secsEl) secsEl.innerText = Math.max(0, timeLeft);

                                         const progressBar = document.getElementById('sync-progress-bar');
                                         if (progressBar) {
                                             progressBar.style.width = pct + '%';
                                             progressBar.setAttribute('aria-valuenow', pct);
                                             progressBar.innerText = pct + '%';
                                         }

                                         // Query server for sync status
                                         $.ajax({
                                             url: 'anasayfa?route=sistem-ayarlari',
                                             type: 'POST',
                                             data: {
                                                 action: 'check_sync_status',
                                                 asset_id: assetId,
                                                 csrf_token: '<?= csrf_token() ?>'
                                             },
                                             dataType: 'json',
                                             success: function(statusRes) {
                                                 if (statusRes.status === 'success') {
                                                     const isCompleted = (statusRes.sync_requested === 0);
                                                     const isUpdated = (statusRes.last_api_sync && statusRes.last_api_sync !== baselineSyncTime);

                                                     if (isCompleted && (isUpdated || !baselineSyncTime)) {
                                                         clearInterval(checkInterval);

                                                          const progressBar = document.getElementById('sync-progress-bar');
                                                          if (progressBar) {
                                                              progressBar.style.width = '100%';
                                                              progressBar.setAttribute('aria-valuenow', 100);
                                                              progressBar.innerText = '100%';
                                                          }

                                                          setTimeout(function() {
                                                              Swal.fire({
                                                                  icon: 'success',
                                                                  title: isTr ? 'Senkronizasyon Başarılı!' : 'Sync Successful!',
                                                                  text: isTr ? 'Cihaz donanım ve yazılım bilgileri başarıyla güncellendi.' : 'Device hardware and software specifications updated successfully.',
                                                                  confirmButtonText: isTr ? 'Tamam' : 'OK'
                                                              }).then(() => {
                                                                  location.reload();
                                                              });
                                                          }, 600);
                                                      }
                                                 }
                                             }
                                         });

                                         if (timeLeft <= 0) {
                                             clearInterval(checkInterval);
                                             Swal.fire({
                                                 icon: 'warning',
                                                 title: isTr ? 'Yanıt Alınamadı' : 'No Response',
                                                 html: `
                                                     <div class="text-left" style="font-size: 13.5px; line-height: 1.6;">
                                                         <p class="text-warning font-weight-bold mb-3"><i class="fas fa-exclamation-triangle mr-1"></i> ${isTr ? 'Cihaz 120 saniye içinde yanıt vermedi.' : 'Device did not respond within 120 seconds.'}</p>

                                                         <p class="font-weight-bold mb-1"><i class="fas fa-laptop mr-1 text-primary"></i> ${isTr ? 'Ajan arka planda çalışmıyorsa:' : 'If the agent is not running in background:'}</p>
                                                         <p class="mb-2 text-muted" style="font-size:12.5px;">${isTr ? 'Hedef bilgisayarda <b>Eaprimus-Ajan.bat</b> dosyasına sağ tıklayıp <b>"Yönetici Olarak Çalıştır"</b> seçin. Ajan çalışır çalışmaz senkronizasyon sinyalini otomatik alacaktır.' : 'On the target computer, right-click <b>Eaprimus-Ajan.bat</b> and select <b>"Run as Administrator"</b>. It will automatically pick up the sync signal.'}</p>

                                                         <p class="font-weight-bold mb-1 mt-3"><i class="fas fa-terminal mr-1 text-secondary"></i> ${isTr ? 'Zamanlanmış görev varsa komut satırından:' : 'If scheduled task exists, from command line:'}</p>
                                                         <pre class="bg-dark text-white p-2 rounded mb-2" style="font-size: 11px; word-break: break-all; white-space: pre-wrap;">schtasks /run /tn "EaprimusAgentSync"</pre>
                                                         <button class="btn btn-xs btn-secondary w-100 font-weight-bold mb-3" onclick="copyToClipboard('schtasks /run /tn \\'EaprimusAgentSync\\'');">
                                                             <i class="fas fa-copy mr-1"></i> ${isTr ? 'Komutu Kopyala' : 'Copy Command'}
                                                         </button>

                                                         <hr>
                                                         <p class="font-weight-bold mt-2 mb-1"><i class="fas fa-wrench mr-1 text-warning"></i> ${isTr ? 'API bağlantı sorunu mu var?' : 'API connection issue?'}</p>
                                                         <button class="btn btn-warning btn-sm w-100 font-weight-bold" id="swal_btn_fix_api">
                                                             <i class="fas fa-wrench mr-1"></i> ${isTr ? 'API Bağlantısını Düzelt ve Tekrar Dene' : 'Fix API Connection & Retry'}
                                                         </button>
                                                     </div>
                                                 `,
                                                 showCloseButton: true,
                                                 confirmButtonText: isTr ? 'Kapat' : 'Close',
                                                 didOpen: () => {
                                                     const fixBtn = document.getElementById('swal_btn_fix_api');
                                                     if (fixBtn) {
                                                         fixBtn.addEventListener('click', function() {
                                                             Swal.showLoading();
                                                             $.ajax({
                                                                 url: 'anasayfa?route=sistem-ayarlari',
                                                                 type: 'POST',
                                                                 data: { action: 'fix_agent_api', csrf_token: '<?= csrf_token() ?>' },
                                                                 dataType: 'json',
                                                                 success: function(res) {
                                                                     if (res.status === 'success') {
                                                                         Swal.fire({ icon: 'success', title: isTr ? 'Düzeltildi!' : 'Fixed!', text: isTr ? 'API etkinleştirildi. Şimdi tekrar "Tetikle" butonuna basabilirsiniz.' : 'API enabled. You can now press "Sync Now" again.', confirmButtonText: 'OK' });
                                                                     } else {
                                                                         Swal.fire({ icon: 'error', title: 'Hata', text: res.message });
                                                                     }
                                                                 },
                                                                 error: function() { Swal.fire({ icon: 'error', title: 'Hata', text: isTr ? 'Bağlantı hatası.' : 'Connection error.' }); }
                                                             });
                                                         });
                                                     }
                                                 }
                                             });
                                         }
                                     }, 2000);

                                 } else {
                                     Swal.fire({
                                         icon: 'error',
                                         title: isTr ? 'Hata!' : 'Error!',
                                         text: response.message
                                     });
                                 }
                             },
                             error: function() {
                                 Swal.fire({
                                     icon: 'error',
                                     title: isTr ? 'Hata!' : 'Error!',
                                     text: isTr ? 'Bağlantı hatası.' : 'Connection error.'
                                 });
                             }
                         });
                    }

                    // Fix API + Agent Key bağlantı butonu
                    const btnFixAgentApi = document.getElementById('btn_fix_agent_api');
                    if (btnFixAgentApi) {
                        btnFixAgentApi.addEventListener('click', function() {
                            Swal.fire({
                                title: isTr ? 'API Düzeltiliyor...' : 'Fixing API...',
                                allowOutsideClick: false,
                                didOpen: () => { Swal.showLoading(); }
                            });
                            $.ajax({
                                url: 'anasayfa?route=sistem-ayarlari',
                                type: 'POST',
                                data: {
                                    action: 'fix_agent_api',
                                    csrf_token: '<?= csrf_token() ?>'
                                },
                                dataType: 'json',
                                success: function(res) {
                                    if (res.status === 'success') {
                                        Swal.fire({
                                            icon: 'success',
                                            title: isTr ? 'Başarılı!' : 'Success!',
                                            text: res.message || (isTr
                                                ? 'API etkinleştirildi. Sayfa yenileniyor...'
                                                : 'API enabled. Reloading...'),
                                            timer: 2500,
                                            showConfirmButton: false
                                        }).then(() => { location.reload(); });
                                    } else {
                                        Swal.fire({ icon: 'error', title: 'Hata', text: res.message });
                                    }
                                },
                                error: function() {
                                    Swal.fire({ icon: 'error', title: 'Hata', text: isTr ? 'Bağlantı hatası.' : 'Connection error.' });
                                }
                            });
                        });
                    }

                    function highlightDevice(deviceName) {

                        const targetRow = $('tr[data-device-name="' + deviceName + '"]');
                        if (targetRow.length) {
                            $('html, body').animate({
                                scrollTop: targetRow.offset().top - 120
                            }, 500);

                            targetRow.removeClass('row-flash-highlight');
                            // trigger reflow
                            void targetRow[0].offsetWidth;
                            targetRow.addClass('row-flash-highlight');
                        }
                    }

                    function highlightDeviceByKey(key) {
                        if (!key) return;
                        const targetRows = $('tr[data-owner-user-key="' + key + '"]');
                        if (targetRows.length) {
                            $('html, body').animate({
                                scrollTop: targetRows.first().offset().top - 120
                            }, 500);

                            targetRows.removeClass('row-flash-highlight');
                            // trigger reflow for all matched rows to run CSS transition animations correctly
                            targetRows.each(function() {
                                void this.offsetWidth;
                            });
                            targetRows.addClass('row-flash-highlight');
                        } else {
                            Swal.fire({
                                icon: 'info',
                                title: isTr ? 'Cihaz Bulunamadı' : 'Device Not Found',
                                text: isTr ? 'Bu API anahtarı ile henüz kaydedilmiş aktif bir cihaz bulunmuyor.' : 'No active device registered with this API key yet.',
                                confirmButtonText: 'Tamam'
                            });
                        }
                    }

                    function highlightUserByKey(key) {
                        if (!key) return;
                        const targetRow = $('tr[data-user-key="' + key + '"]');
                        if (targetRow.length) {
                            $('html, body').animate({
                                scrollTop: targetRow.offset().top - 120
                            }, 500);

                            targetRow.removeClass('row-flash-highlight');
                            // trigger reflow
                            void targetRow[0].offsetWidth;
                            targetRow.addClass('row-flash-highlight');
                        } else {
                            Swal.fire({
                                icon: 'info',
                                title: isTr ? 'Kullanıcı Bulunamadı' : 'User Not Found',
                                text: isTr ? 'Bu API anahtarının sahibi olan kullanıcı listede bulunamadı.' : 'The user owning this API key was not found in the list.',
                                confirmButtonText: 'Tamam'
                            });
                        }
                    }

                    const activeUsers = <?php
                        $usersWithKeys = [];
                        foreach ($allUsers as $usr) {
                            if (!empty($usr['client_id'])) {
                                $usersWithKeys[] = [
                                    'fullname' => $usr['fullname'],
                                    'client_id' => $usr['client_id']
                                ];
                            }
                        }
                        echo json_encode($usersWithKeys);
                    ?>;

                    function changeAgentOwner(agentId, agentName) {
                        if (!activeUsers.length) {
                            Swal.fire({
                                icon: 'warning',
                                title: isTr ? 'Aktif Kullanıcı Anahtarı Yok' : 'No Active User Keys',
                                text: isTr ? 'Cihazı atayabileceğiniz aktif API anahtarına sahip bir kullanıcı bulunamadı.' : 'No users with active API keys found to assign this device to.'
                            });
                            return;
                        }

                        let options = {};
                        activeUsers.forEach(function(u) {
                            options[u.client_id] = u.fullname + ' (' + u.client_id.substring(0, 10) + '...)';
                        });

                        Swal.fire({
                            title: isTr ? 'Cihaz Sahibini Değiştir' : 'Change Device Owner',
                            text: isTr ? agentName + ' cihazını hangi kullanıcıya atamak istiyorsunuz?' : 'Which user do you want to assign ' + agentName + ' to?',
                            input: 'select',
                            inputOptions: options,
                            inputPlaceholder: isTr ? 'Kullanıcı seçin' : 'Select a user',
                            showCancelButton: true,
                            confirmButtonText: isTr ? 'Ata' : 'Assign',
                            cancelButtonText: isTr ? 'İptal' : 'Cancel',
                            confirmButtonColor: '#3085d6',
                            cancelButtonColor: '#d33'
                        }).then((result) => {
                            if (result.isConfirmed && result.value) {
                                $.ajax({
                                    url: '<?= $base_url ?>ajax/update_agent_owner.php',
                                    type: 'POST',
                                    data: {
                                        agent_id: agentId,
                                        user_api_key: result.value
                                    },
                                    success: function(resp) {
                                        if (resp.success) {
                                            Swal.fire({
                                                icon: 'success',
                                                title: isTr ? 'Başarılı' : 'Success',
                                                text: resp.message
                                            }).then(() => {
                                                location.reload();
                                            });
                                        } else {
                                            Swal.fire({
                                                icon: 'error',
                                                title: isTr ? 'Hata' : 'Error',
                                                text: resp.message
                                            });
                                        }
                                    },
                                    error: function() {
                                        Swal.fire({
                                            icon: 'error',
                                            title: isTr ? 'Sistem Hatası' : 'System Error',
                                            text: isTr ? 'Sahip güncellemesi yapılırken bir hata oluştu.' : 'An error occurred while updating the owner.'
                                        });
                                    }
                                });
                            }
                        });
                    }

                    function copyLinuxCmd(userId, fullname, apiKey) {
                        const baseUrl = '<?= rtrim($base_url, '/') ?>';
                        const cmd = `curl -s -k -L "${baseUrl}/ajax/download_agent_linux.php?personal=1&user_id=${userId}&api_key=${apiKey}" > Eaprimus-Ajan.sh && chmod +x Eaprimus-Ajan.sh && sudo ./Eaprimus-Ajan.sh`;
                        
                        // Copy to clipboard
                        const el = document.createElement('textarea');
                        el.value = cmd;
                        document.body.appendChild(el);
                        el.select();
                        document.execCommand('copy');
                        document.body.removeChild(el);
                        
                        Swal.fire({
                            icon: 'success',
                            title: isTr ? "Kopyalandı!" : "Copied!",
                            text: (isTr ? fullname + ' adlı kullanıcının kişisel Linux kurulum kodu kopyalandı. Sunucunuzda root olarak terminale yapıştırıp çalıştırabilirsiniz.' : fullname + ' personal Linux installation command has been copied. Paste and run as root on your server terminal.'),
                            confirmButtonText: 'Tamam',
                            confirmButtonColor: '#3085d6'
                        });
                    }

                    function generateUserKey(userId, fullname) {
                        Swal.fire({
                            title: isTr ? 'Emin misiniz?' : 'Are you sure?',
                            text: (isTr ? fullname + ' için yeni bir API anahtarı üretilecek. Varsa eski anahtar iptal edilecektir.' : 'A new API key will be generated for ' + fullname + '. Existing key, if any, will be revoked.'),
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#3085d6',
                            cancelButtonColor: '#d33',
                            confirmButtonText: isTr ? 'Evet, Üret' : 'Yes, Generate',
                            cancelButtonText: isTr ? 'İptal' : 'Cancel'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                Swal.fire({
                                    title: isTr ? 'Üretiliyor...' : 'Generating...',
                                    allowOutsideClick: false,
                                    didOpen: () => { Swal.showLoading(); }
                                });
                                $.ajax({
                                    url: 'anasayfa?route=sistem-ayarlari',
                                    type: 'POST',
                                    data: {
                                        action: 'generate_user_api_key_admin',
                                        user_id: userId,
                                        csrf_token: '<?= csrf_token() ?>'
                                    },
                                    dataType: 'json',
                                    success: function(response) {
                                        if (response.status === 'success') {
                                            Swal.fire({
                                                icon: 'success',
                                                title: isTr ? 'Başarılı!' : 'Success!',
                                                text: response.message
                                            }).then(() => {
                                                location.reload();
                                            });
                                        } else {
                                            Swal.fire({
                                                icon: 'error',
                                                title: isTr ? 'Hata!' : 'Error!',
                                                text: response.message
                                            });
                                        }
                                    },
                                    error: function() {
                                        Swal.fire({
                                            icon: 'error',
                                            title: isTr ? 'Hata!' : 'Error!',
                                            text: isTr ? 'İşlem sırasında bir hata oluştu.' : 'An error occurred during the process.'
                                        });
                                    }
                                });
                            }
                        });
                    }

                    function revokeUserKey(userId, fullname) {
                        Swal.fire({
                            title: isTr ? 'Emin misiniz?' : 'Are you sure?',
                            text: (isTr ? fullname + ' kullanıcısının API anahtarı iptal edilecek ve bu anahtarı kullanan ajanlar artık çalışmayacaktır.' : 'The API key for ' + fullname + ' will be revoked and agents using it will no longer work.'),
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#d33',
                            cancelButtonColor: '#3085d6',
                            confirmButtonText: isTr ? 'Evet, İptal Et' : 'Yes, Revoke',
                            cancelButtonText: isTr ? 'Vazgeç' : 'Cancel'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                Swal.fire({
                                    title: isTr ? 'İptal Ediliyor...' : 'Revoking...',
                                    allowOutsideClick: false,
                                    didOpen: () => { Swal.showLoading(); }
                                });
                                $.ajax({
                                    url: 'anasayfa?route=sistem-ayarlari',
                                    type: 'POST',
                                    data: {
                                        action: 'revoke_user_api_key_admin',
                                        user_id: userId,
                                        csrf_token: '<?= csrf_token() ?>'
                                    },
                                    dataType: 'json',
                                    success: function(response) {
                                        if (response.status === 'success') {
                                            Swal.fire({
                                                icon: 'success',
                                                title: isTr ? 'İptal Edildi!' : 'Revoked!',
                                                text: response.message
                                            }).then(() => {
                                                location.reload();
                                            });
                                        } else {
                                            Swal.fire({
                                                icon: 'error',
                                                title: isTr ? 'Hata!' : 'Error!',
                                                text: response.message
                                            });
                                        }
                                    },
                                    error: function() {
                                        Swal.fire({
                                            icon: 'error',
                                            title: isTr ? 'Hata!' : 'Error!',
                                            text: isTr ? 'İşlem sırasında bir hata oluştu.' : 'An error occurred during the process.'
                                        });
                                    }
                                });
                            }
                        });
                    }
                    </script>
                </div>

                <!-- --- HAZIR YANIT ŞABLONLARI --- -->
                <div class="settings-section <?= $active_tab === 'canned' ? 'active' : '' ?>" id="section-canned" style="<?= $active_tab === 'canned' ? 'display:block;' : 'display:none;' ?>">
                    <div class="card shadow-sm" style="border-radius:12px; border:none;">
                        <div class="card-header d-flex justify-content-between align-items-center"
                            style="background:transparent; border-bottom:1px solid #eee; padding:20px 24px;">
                            <h4 class="mb-0 font-weight-bold">
                                <i class="fas fa-bolt mr-2 text-warning"></i><?= $isTr ? 'Hazır Yanıt Şablonları Yönetimi' : 'Canned Responses Management' ?>
                            </h4>
                            <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm font-weight-bold" onclick="openCannedModal()">
                                <i class="fas fa-plus mr-1"></i><?= $isTr ? 'Yeni Hazır Yanıt Ekle' : 'Add New Canned Response' ?>
                            </button>
                        </div>
                        <div class="card-body px-4 py-4">
                            <div class="alert alert-info border-0 shadow-sm mb-4" style="border-radius:10px; background: rgba(59, 130, 246, 0.08); color: #1e40af; font-size:13px;">
                                <i class="fas fa-info-circle mr-2 text-primary"></i>
                                <?= $isTr 
                                    ? 'Burada tanımlanan hazır yanıt şablonları, teknik personelin bilet detay sayfasında <b>"Hazır Yanıtlar"</b> butonuna basarak tek tıkla mesaj kutusuna aktarmasını sağlar.'
                                    : 'Canned responses configured here can be inserted into the reply box by agents with a single click on the ticket detail page.' ?>
                            </div>

                            <?php
                            $cannedList = [];
                            try {
                                $current_user_id = $_SESSION['user_id'] ?? 0;
                                $role = $_SESSION['role'] ?? 3;
                                
                                // User's teams
                                $myTeams = [];
                                if ($current_user_id) {
                                    $stmtT = $pdo->prepare("SELECT team_id FROM teams_users WHERE user_id = ?");
                                    $stmtT->execute([$current_user_id]);
                                    $myTeams = $stmtT->fetchAll(PDO::FETCH_COLUMN);
                                }
                                
                                $sqlCanned = "
                                    SELECT c.*, t.name as team_name 
                                    FROM canned_responses c 
                                    LEFT JOIN teams t ON c.team_id = t.id 
                                ";

                                if ($role == 1) {
                                    // Admin tüm hazır yanıtları yönetmek için görebilir
                                    $cannedList = $pdo->query($sqlCanned . " ORDER BY c.category ASC, c.title ASC")->fetchAll(PDO::FETCH_ASSOC);
                                } else {
                                    // Personel / Teknik Ekip sadece izinli olduğu şablonları görür
                                    $teamClause = "";
                                    if (!empty($myTeams)) {
                                        $inClause = implode(',', array_map('intval', $myTeams));
                                        $teamClause = " OR (c.sharing_type = 'team' AND c.team_id IN ($inClause))";
                                    }
                                    $stmtC = $pdo->prepare($sqlCanned . " WHERE (c.user_id = ? AND c.sharing_type = 'personal')" . $teamClause . " OR c.sharing_type = 'global' ORDER BY c.category ASC, c.title ASC");
                                    $stmtC->execute([$current_user_id]);
                                    $cannedList = $stmtC->fetchAll(PDO::FETCH_ASSOC);
                                }
                            } catch (Exception $e) {}
                            ?>

                            <?php if (empty($cannedList)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-bolt fa-3x mb-3 text-warning" style="opacity: 0.5;"></i>
                                    <h5 class="font-weight-bold"><?= $isTr ? 'Henüz Hazır Yanıt Şablonu Yok' : 'No Canned Responses Yet' ?></h5>
                                    <p class="small text-muted mb-3"><?= $isTr ? 'Sık kullanılan yanıtlarınızı şablon olarak eklemek için aşağıdaki butonu kullanın.' : 'Click below to create reusable response templates.' ?></p>
                                    <button type="button" class="btn btn-primary btn-sm px-4 shadow-sm font-weight-bold" onclick="openCannedModal()">
                                        <i class="fas fa-plus mr-1"></i><?= $isTr ? 'İlk Şablonu Ekle' : 'Add First Template' ?>
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" style="font-size:13.5px;">
                                        <thead class="bg-light text-muted">
                                            <tr>
                                                <th style="width: 180px;"><?= $isTr ? 'Kategori' : 'Category' ?></th>
                                                <th style="width: 220px;"><?= $isTr ? 'Şablon Başlığı' : 'Template Title' ?></th>
                                                <th><?= $isTr ? 'Yanıt İçeriği' : 'Response Content' ?></th>
                                                <th class="text-right" style="width: 160px;"><?= $isTr ? 'İşlemler' : 'Actions' ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($cannedList as $cItem): ?>
                                                <?php
                                                $dispCategory = (!$isTr && !empty($cItem['category_en'])) ? $cItem['category_en'] : ($cItem['category'] ?: 'Genel');
                                                $dispTitle = (!$isTr && !empty($cItem['title_en'])) ? $cItem['title_en'] : $cItem['title'];
                                                $dispContent = (!$isTr && !empty($cItem['content_en'])) ? $cItem['content_en'] : $cItem['content'];
                                                $isMine = ($cItem['user_id'] == ($user_id ?? 0));
                                                $isGlobal = (!empty($cItem['is_global']) || empty($cItem['user_id']));
                                                ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge badge-warning text-dark font-weight-bold px-2 py-1" style="border-radius: 6px;">
                                                            <i class="fas fa-folder mr-1"></i><?= htmlspecialchars($dispCategory) ?>
                                                        </span>
                                                        <?php
                                                        $sType = $cItem['sharing_type'] ?? ($cItem['is_global'] == 1 ? 'global' : 'personal');
                                                        if ($sType === 'team') {
                                                            $tmLabel = !empty($cItem['team_name']) ? (' (' . htmlspecialchars($cItem['team_name']) . ')') : '';
                                                            echo '<span class="badge badge-info px-2 py-1 ml-1" style="border-radius: 6px;" title="Takıma Özel Şablon"><i class="fas fa-users mr-1"></i>' . ($isTr ? 'Takıma Özel' : 'Team Only') . $tmLabel . '</span>';
                                                        } elseif ($sType === 'global') {
                                                            echo '<span class="badge badge-success px-2 py-1 ml-1" style="border-radius: 6px;" title="Genel Sistem Şablonu"><i class="fas fa-globe mr-1"></i>' . ($isTr ? 'Genel' : 'Global') . '</span>';
                                                        } else {
                                                            echo '<span class="badge badge-secondary px-2 py-1 ml-1" style="border-radius: 6px;" title="Kişisel Şablonum"><i class="fas fa-user mr-1"></i>' . ($isTr ? 'Kişisel' : 'Personal') . '</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td class="font-weight-bold text-dark">
                                                        <?= htmlspecialchars($dispTitle) ?>
                                                        <?php if (!empty($cItem['title_en']) && $isTr): ?>
                                                            <div class="small text-muted font-weight-normal">EN: <?= htmlspecialchars($cItem['title_en']) ?></div>
                                                        <?php elseif (!empty($cItem['title']) && !$isTr): ?>
                                                            <div class="small text-muted font-weight-normal">TR: <?= htmlspecialchars($cItem['title']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-muted" style="max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                        <?= htmlspecialchars(mb_strimwidth(strip_tags($dispContent), 0, 100, '...')) ?>
                                                    </td>
                                                    <td class="text-right">
                                                        <button type="button" class="btn btn-xs btn-outline-primary font-weight-bold mr-1" 
                                                            onclick='editCanned(<?= json_encode($cItem, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                                            <i class="fas fa-edit mr-1"></i><?= $isTr ? 'Düzenle' : 'Edit' ?>
                                                        </button>
                                                        <button type="button" class="btn btn-xs btn-outline-danger font-weight-bold" 
                                                            onclick="deleteCanned(<?= $cItem['id'] ?>, '<?= htmlspecialchars($cItem['title'], ENT_QUOTES) ?>')">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div><!-- /col-md-9 -->

<!-- Modal: Add / Edit Canned Response -->
<div class="modal fade" id="cannedModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content" style="border-radius:12px; border:none; overflow:hidden;">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title font-weight-bold" id="cannedModalTitle">
                    <i class="fas fa-bolt text-warning mr-2"></i><?= $isTr ? 'Hazır Yanıt Şablonu' : 'Canned Response Template' ?>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="sistem-ayarlari?tab=canned" onsubmit="return validateCannedForm(this);">
                <?= csrf_field() ?>
                <input type="hidden" name="section" value="canned_save">
                <input type="hidden" name="canned_id" id="modal_canned_id" value="0">
                <div class="modal-body p-4">
                    <ul class="nav nav-tabs nav-justified mb-3" id="cannedModalTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active font-weight-bold" id="tab-tr-link" data-toggle="tab" href="#canned-tab-tr" role="tab">🇹🇷 Türkçe (TR)</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold" id="tab-en-link" data-toggle="tab" href="#canned-tab-en" role="tab">🇬🇧 English (EN)</a>
                        </li>
                    </ul>
                    <div class="tab-content" id="cannedModalTabContent">
                        <!-- TR TAB -->
                        <div class="tab-pane fade show active" id="canned-tab-tr" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Kategori (TR) <span class="text-danger">*</span></label>
                                        <input type="text" name="category" id="modal_canned_category" class="form-control" list="canned_category_list" placeholder="Örn: Genel, Donanım, Yazılım">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Şablon Başlığı (TR) <span class="text-danger">*</span></label>
                                        <input type="text" name="title" id="modal_canned_title" class="form-control" placeholder="Örn: Şifre Sıfırlama Bilgilendirmesi">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group mb-0">
                                <label class="font-weight-bold">Yanıt İçeriği (TR) <span class="text-danger">*</span></label>
                                <textarea name="content" id="modal_canned_content" class="form-control" rows="6" placeholder="Türkçe yanıt metnini yazın..."></textarea>
                            </div>
                        </div>
                        <!-- EN TAB -->
                        <div class="tab-pane fade" id="canned-tab-en" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Category (EN)</label>
                                        <input type="text" name="category_en" id="modal_canned_category_en" class="form-control" placeholder="e.g. Support, Hardware, Software">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Template Title (EN)</label>
                                        <input type="text" name="title_en" id="modal_canned_title_en" class="form-control" placeholder="e.g. Password Reset Notification">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group mb-0">
                                <label class="font-weight-bold">Response Content (EN)</label>
                                <textarea name="content_en" id="modal_canned_content_en" class="form-control" rows="6" placeholder="Enter English response text..."></textarea>
                            </div>
                        </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold"><i class="fas fa-eye mr-1 text-primary"></i><?= $isTr ? 'Görünürlük / Paylaşım' : 'Visibility / Sharing' ?></label>
                                <select name="sharing_type" id="modal_canned_sharing_type" class="form-control" onchange="toggleCannedTeamSelect(this.value)">
                                    <option value="personal">🔒 <?= $isTr ? 'Sadece Ben (Kişisel)' : 'Only Me (Personal)' ?></option>
                                    <option value="team">👥 <?= $isTr ? 'Takımıma / Departmanıma Özel' : 'Specific to My Team' ?></option>
                                    <?php if (($role ?? 3) == 1): ?>
                                    <option value="global">🌐 <?= $isTr ? 'Tüm Sistem (Genel)' : 'Entire System (Global)' ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6" id="canned_team_select_wrapper" style="display:none;">
                            <div class="form-group">
                                <label class="font-weight-bold"><i class="fas fa-users mr-1 text-info"></i><?= $isTr ? 'Hedef Takım' : 'Target Team' ?></label>
                                <select name="team_id" id="modal_canned_team_id" class="form-control">
                                    <option value=""><?= $isTr ? '-- Takım Seçin --' : '-- Select Team --' ?></option>
                                    <?php
                                    $allTeams = [];
                                    try {
                                        $allTeams = $pdo->query("SELECT id, name FROM teams ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
                                    } catch (Exception $e) {}
                                    foreach ($allTeams as $tm):
                                    ?>
                                        <option value="<?= $tm['id'] ?>"><?= htmlspecialchars($tm['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <datalist id="canned_category_list">
                    <option value="Genel">
                    <option value="Destek ve Arıza">
                    <option value="Hesap İşlemleri">
                    <option value="Donanım">
                    <option value="Yazılım">
                    <option value="Ağ ve Erişim">
                    <option value="Bekleme ve Takip">
                    <option value="Kapatma">
                </datalist>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-4" data-dismiss="modal"><?= $isTr ? 'İptal' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm font-weight-bold"><i class="fas fa-save mr-1"></i><?= $isTr ? 'Kaydet' : 'Save' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deleteCannedForm" method="POST" action="sistem-ayarlari?tab=canned" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="canned_delete">
    <input type="hidden" name="canned_id" id="delete_canned_id" value="0">
</form>

<script>
function toggleCannedTeamSelect(val) {
    if (val === 'team') {
        $('#canned_team_select_wrapper').slideDown();
    } else {
        $('#canned_team_select_wrapper').slideUp();
    }
}

function validateCannedForm(form) {
    const title = $('#modal_canned_title').val().trim();
    const content = $('#modal_canned_content').val().trim();
    const sType = $('#modal_canned_sharing_type').val();
    const teamId = $('#modal_canned_team_id').val();

    if (!title || !content) {
        $('#tab-tr-link').tab('show');
        Swal.fire({
            icon: 'warning',
            title: isTr ? 'Eksik Alan!' : 'Missing Field!',
            text: isTr ? 'Lütfen Türkçe Şablon Başlığı ve Yanıt İçeriği alanlarını doldurun.' : 'Please fill in Turkish Title and Response Content fields.'
        });
        return false;
    }

    if (sType === 'team' && !teamId) {
        Swal.fire({
            icon: 'warning',
            title: isTr ? 'Takım Seçilmedi!' : 'No Team Selected!',
            text: isTr ? 'Takıma özel şablon için lütfen Hedef Takım seçiniz.' : 'Please select a Target Team for team-specific template.'
        });
        return false;
    }

    return true;
}

function openCannedModal() {
    $('#modal_canned_id').val(0);
    $('#modal_canned_title').val('');
    $('#modal_canned_title_en').val('');
    $('#modal_canned_category').val('Genel');
    $('#modal_canned_category_en').val('General');
    $('#modal_canned_content').val('');
    $('#modal_canned_content_en').val('');
    $('#modal_canned_sharing_type').val('personal');
    $('#modal_canned_team_id').val('');
    toggleCannedTeamSelect('personal');
    $('#tab-tr-link').tab('show');
    $('#cannedModalTitle').html('<i class="fas fa-bolt text-warning mr-2"></i>' + (isTr ? 'Yeni Hazır Yanıt Ekle' : 'Add New Canned Response'));
    $('#cannedModal').modal('show');
}

function editCanned(item) {
    $('#modal_canned_id').val(item.id);
    $('#modal_canned_title').val(item.title || '');
    $('#modal_canned_title_en').val(item.title_en || '');
    $('#modal_canned_category').val(item.category || 'Genel');
    $('#modal_canned_category_en').val(item.category_en || '');
    $('#modal_canned_content').val(item.content || '');
    $('#modal_canned_content_en').val(item.content_en || '');
    const sType = item.sharing_type || (item.is_global == 1 ? 'global' : 'personal');
    $('#modal_canned_sharing_type').val(sType);
    $('#modal_canned_team_id').val(item.team_id || '');
    toggleCannedTeamSelect(sType);
    $('#tab-tr-link').tab('show');
    $('#cannedModalTitle').html('<i class="fas fa-edit text-primary mr-2"></i>' + (isTr ? 'Hazır Yanıtı Düzenle' : 'Edit Canned Response'));
    $('#cannedModal').modal('show');
}

function deleteCanned(id, title) {
    Swal.fire({
        title: isTr ? 'Emin misiniz?' : 'Are you sure?',
        text: '"' + title + '" ' + (isTr ? 'başlıklı hazır yanıt silinecektir.' : 'canned response will be deleted.'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: isTr ? 'Evet, Sil' : 'Yes, Delete',
        cancelButtonText: isTr ? 'İptal' : 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#delete_canned_id').val(id);
            $('#deleteCannedForm').submit();
        }
    });
}
</script>

        </div><!-- /row -->
    </div>
</section>

<!-- TEMPLATE DRAWER -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeTemplateDrawer()"></div>
<div class="side-drawer" id="templateDrawer">
    <div class="drawer-header bg-dark text-white">
        <h5 class="mb-0 font-weight-bold" id="drawerTitle"><?= __("edit_template") ?></h5>
        <button type="button" class="close text-white" onclick="closeTemplateDrawer()"><span>&times;</span></button>
    </div>
    <div class="drawer-content">
        <div class="mb-4 d-flex justify-content-between align-items-center bg-light p-3 rounded">
            <div>
                <h6 class="mb-0 font-weight-bold"><?= __("status") ?></h6>
                <small class="text-muted"><?= __("template_status_hint") ?></small>
            </div>
            <div class="custom-control custom-switch custom-switch-off-danger custom-switch-on-success">
                <input type="checkbox" class="custom-control-input" id="templateStatus" checked onchange="updateTemplateStatus(this.checked)">
                <label class="custom-control-label" for="templateStatus"></label>
            </div>
        </div>

        <div class="mb-3 d-flex justify-content-between align-items-center">
            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                <label class="btn btn-outline-primary px-4 active" id="btn-tr" onclick="switchDrawerLang('tr')">
                    <input type="radio" name="drawer_lang" id="option_tr" checked> TR
                </label>
                <label class="btn btn-outline-primary px-4" id="btn-en" onclick="switchDrawerLang('en')">
                    <input type="radio" name="drawer_lang" id="option_en"> EN
                </label>
            </div>

            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                <label class="btn btn-outline-secondary btn-sm active" id="btn-mode-visual" onclick="switchEditorMode('visual')">
                    <input type="radio" name="editor_mode" id="mode_visual" checked style="display:none;">
                    <i class="fas fa-eye mr-1"></i> <?= $isTr ? 'Önizleme' : 'Preview' ?>
                </label>
                <label class="btn btn-outline-secondary btn-sm" id="btn-mode-html" onclick="switchEditorMode('html')">
                    <input type="radio" name="editor_mode" id="mode_html" style="display:none;">
                    <i class="fas fa-code mr-1"></i> HTML
                </label>
            </div>
        </div>

        <form id="templateForm">
            <input type="hidden" name="template_key" id="templateKey">
            <div id="drawer_tr">
                <div class="form-group">
                    <label class="font-weight-bold"><?= __("email_subject") ?> (TR)</label>
                    <input type="text" id="templateSubject_tr" class="form-control">
                </div>
                <div id="template-editor-tr" style="height:350px; border:1px solid #ddd; border-radius:4px; overflow:hidden; background:#fff;">
                    <iframe id="template-preview-tr" style="width:100%; height:100%; border:none;"></iframe>
                </div>
                <textarea id="template-raw-tr" class="form-control" style="height:350px; display:none; font-family:monospace;"></textarea>
            </div>
            <div id="drawer_en" style="display:none;">
                <div class="form-group">
                    <label class="font-weight-bold"><?= __("email_subject") ?> (EN)</label>
                    <input type="text" id="templateSubject_en" class="form-control">
                </div>
                <div id="template-editor-en" style="height:350px; border:1px solid #ddd; border-radius:4px; overflow:hidden; background:#fff;">
                    <iframe id="template-preview-en" style="width:100%; height:100%; border:none;"></iframe>
                </div>
                <textarea id="template-raw-en" class="form-control" style="height:350px; display:none; font-family:monospace;"></textarea>
            </div>
        </form>
    </div>
    <div class="drawer-footer">
        <button type="button" class="btn btn-outline-secondary" onclick="closeTemplateDrawer()"><?= __("cancel") ?></button>
        <button type="button" class="btn btn-primary" onclick="saveTemplate()"><?= __("save_changes") ?></button>
    </div>
</div>

<script>
    // Logo & Favicon previews
    function previewLogo(i) {
        if (i.files[0]) {
            if (i.files[0].size > 1048576) { // 1MB
                Swal.fire({
                    icon: 'warning',
                    title: '<?= $isTr ? "Boyut Çok Büyük" : "File Too Large" ?>',
                    text: '<?= $isTr ? "Seçtiğiniz logo dosyası 1 MB\'dan büyük. Lütfen daha küçük boyutlu bir resim seçin." : "The selected logo file is larger than 1 MB. Please choose a smaller image." ?>'
                });
                i.value = '';
                return;
            }
            let r = new FileReader();
            r.onload = e => document.getElementById('logo_preview').src = e.target.result;
            r.readAsDataURL(i.files[0]);
        }
    }
    function previewFavicon(i) {
        if (i.files[0]) {
            if (i.files[0].size > 524288) { // 500KB
                Swal.fire({
                    icon: 'warning',
                    title: '<?= $isTr ? "Boyut Çok Büyük" : "File Too Large" ?>',
                    text: '<?= $isTr ? "Seçtiğiniz favicon dosyası çok büyük. Lütfen 500 KB\'dan küçük bir dosya seçin." : "The selected favicon file is too large. Please choose a file smaller than 500 KB." ?>'
                });
                i.value = '';
                return;
            }
            let r = new FileReader();
            r.onload = e => document.getElementById('favicon_preview').src = e.target.result;
            r.readAsDataURL(i.files[0]);
        }
    }

    // Color picker sync
    document.querySelectorAll('input[type=color]').forEach(p => {
        p.oninput = function(){ if(this.nextElementSibling) this.nextElementSibling.value = this.value; };
    });

    const templateData = <?= json_encode($allSettings) ?>;
    let quillTR, quillEN;
    let currentDefaultLang = '<?= s("mail_default_lang", "tr") ?>';
    let _pendingMailLang = null;
    let _prevMailLang = currentDefaultLang;

    function confirmMailLangChange(selectEl) {
        const newLang = selectEl.value;
        if (newLang === currentDefaultLang) return;
        _pendingMailLang = newLang;
        _prevMailLang = currentDefaultLang;

        const isTr = (document.documentElement.lang === 'tr' || '<?= $isTr ? 'tr' : 'en' ?>' === 'tr');
        const langLabel = newLang === 'tr' ? 'Türkçe (TR)' : 'English (EN)';
        const prevLabel = currentDefaultLang === 'tr' ? 'Türkçe (TR)' : 'English (EN)';

        if (isTr) {
            document.getElementById('mailLangConfirmMsg').innerHTML =
                'Gönderim dilini <strong>' + prevLabel + '</strong> → <strong>' + langLabel + '</strong> olarak değiştiriyorsunuz.<br>' +
                'Bundan sonra müşteriye gidecek tüm e-postalar <strong>' + langLabel + '</strong> şablonuyla gönderilecektir.';
            document.getElementById('mailLangResetMsg').innerHTML =
                'Şablon içeriklerini de <strong>' + langLabel + ' varsayılanlarına sıfırlamak</strong> ister misiniz? Bu işlem kaydedilmemiş şablon değişikliklerini etkiler.';
        } else {
            document.getElementById('mailLangConfirmMsg').innerHTML =
                'You are changing the send language from <strong>' + prevLabel + '</strong> to <strong>' + langLabel + '</strong>.<br>' +
                'All future emails to customers will be sent using the <strong>' + langLabel + '</strong> template.';
            document.getElementById('mailLangResetMsg').innerHTML =
                'Would you also like to <strong>reset template contents to ' + langLabel + ' defaults</strong>? This will affect unsaved template changes.';
        }

        $('#mailLangConfirmModal').modal('show');
    }

    function cancelMailLangChange() {
        // Seçimi eski değere döndür
        document.getElementById('mail_default_lang_select').value = _prevMailLang;
        _pendingMailLang = null;
        $('#mailLangConfirmModal').modal('hide');
    }

    function applyMailLangChange(resetTemplates) {
        $('#mailLangConfirmModal').modal('hide');
        if (!_pendingMailLang) return;

        currentDefaultLang = _pendingMailLang;
        saveGeneralSetting('mail_default_lang', _pendingMailLang);

        if (resetTemplates) {
            // Tüm template varsayılanlarını yeni dile göre DB'ye kaydet
            const tplKeys = ['new_ticket_cust','new_ticket_agent','reply_cust','reply_agent','resolved','imap_forward','user_invitation','user_registration','asset_assigned','asset_returned','password_reset'];
            const lang = _pendingMailLang;
            const isTr = (lang === 'tr');

            // defaults objesine erişmek için openTemplateDrawer içindeki defaults değişkenini dışarı almamız lazım
            // Bunun yerine direkt sıfırlama sayfasını yenile
            const toastMsg = isTr
                ? 'Dil değiştirildi. Şablonlar sıfırlamak için her şablonu açıp "Varsayılana Sıfırla" butonuna basın.'
                : 'Language changed. To reset templates, open each template and click "Reset to Default".';

            Swal.fire ? Swal.fire({icon:'info',title: isTr?'Dil Güncellendi':'Language Updated',text:toastMsg,confirmButtonColor:'#2563eb'})
                      : alert(toastMsg);
        } else {
            const isTr = (_pendingMailLang === 'tr');
            const msg = isTr ? 'Gönderim dili Türkçe olarak ayarlandı.' : 'Send language set to English.';
            if (typeof toastr !== 'undefined') {
                toastr.success(msg);
            }
        }

        _pendingMailLang = null;
    }

    function openTemplateDrawer(key, label) {
        // Remove Quill init since we use <iframe> and Raw <textarea> now

        const defaults = {
            'user_invitation': {
                'tr_subj': '{{SITE_TITLE}} Davet Edildiniz!',
                'tr_body': `<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
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
</html>`,
                'en_subj': 'Invitation to {{SITE_TITLE}}!',
                'en_body': `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
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
</html>`
            },
            'user_registration': {
                'tr_subj': 'Eaprimus Hoş Geldiniz! 🎉',
                'tr_body': `<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Kaydınız Tamamlandı! ✅</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Merhaba <b>{{NAME}}</b>,<br>Sisteme erişim bilgileriniz başarıyla oluşturulmuştur. Artık paneli kullanarak varlıklarınızı yönetebilir ve destek talepleri oluşturabilirsiniz.</p>

                            <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding-bottom:10px;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Kullanıcı Adınız</span><br>
                                            <span style="font-size:16px; color:#1e293b; font-weight:600;">{{USERNAME}}</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <div style="text-align:center; margin:35px 0;">
                                <a href="{{SITE_URL}}" style="background:#0f172a; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px;">
                                    Paneli Görüntüle
                                </a>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>`,
                'en_subj': 'Welcome to {{SITE_TITLE}}! 🎉',
                'en_body': `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Account Created! ✅</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Hello <b>{{NAME}}</b>,<br>Your account has been successfully created. You can now access the dashboard to manage your assets and tickets.</p>

                            <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding-bottom:10px;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Your Username</span><br>
                                            <span style="font-size:16px; color:#1e293b; font-weight:600;">{{USERNAME}}</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <div style="text-align:center; margin:35px 0;">
                                <a href="{{SITE_URL}}" style="background:#0f172a; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px;">
                                    View Dashboard
                                </a>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>`
            },
            'asset_assigned': {
                'tr_subj': 'Yeni Zimmet Atandı: {{ITEM_NAME}}',
                'tr_body': `<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Yeni Zimmet Atandı 📦</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Merhaba <b>{{NAME}}</b>,<br>Üzerinize yeni bir varlık/demirbaş başarıyla zimmetlenmiştir.</p>
                            <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Zimmetlenen Öğe</span><br>
                                            <span style="font-size:16px; color:#1e293b; font-weight:600;">{{ITEM_NAME}}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-top:10px;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Zimmet Tarihi</span><br>
                                            <span style="font-size:16px; color:#1e293b; font-weight:600;">{{DATE_TIME}}</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;">
                            <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                            <p style="margin:0;">Bu bilgilendirme e-postası <b>{{COMPANY_NAME}}</b> sistemi tarafından otomatik olarak gönderilmiştir.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>`,
                'en_subj': 'New Asset Assigned: {{ITEM_NAME}}',
                'en_body': `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">New Asset Assigned 📦</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Hello <b>{{NAME}}</b>,<br>A new asset has been successfully assigned to you.</p>
                            <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Assigned Item</span><br>
                                            <span style="font-size:16px; color:#1e293b; font-weight:600;">{{ITEM_NAME}}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-top:10px;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Assignment Date</span><br>
                                            <span style="font-size:16px; color:#1e293b; font-weight:600;">{{DATE_TIME}}</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;">
                            <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                            <p style="margin:0;">This notification email was automatically sent by <b>{{COMPANY_NAME}}</b> system.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>`
            },
            'asset_returned': {
                'tr_subj': 'Zimmet Geri Alındı: {{ITEM_NAME}}',
                'tr_body': `<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Zimmet Geri Alındı 🗳️</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Merhaba <b>{{NAME}}</b>,<br>Üzerinizdeki zimmet başarıyla iade alınmıştır.</p>
                            <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">İade Alınan Öğe</span><br>
                                            <span style="font-size:16px; color:#1e293b; font-weight:600;">{{ITEM_NAME}}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-top:10px;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">İade Tarihi</span><br>
                                            <span style="font-size:16px; color:#1e293b; font-weight:600;">{{DATE_TIME}}</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;">
                            <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                            <p style="margin:0;">Bu bilgilendirme e-postası <b>{{COMPANY_NAME}}</b> sistemi tarafından otomatik olarak gönderilmiştir.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>`,
                'en_subj': 'Asset Returned: {{ITEM_NAME}}',
                'en_body': `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                    <tr>
                        <td align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                            <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                            <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Asset Returned 🗳️</h1>
                            <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Hello <b>{{NAME}}</b>,<br>The asset has been successfully returned.</p>
                            <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Returned Item</span><br>
                                            <span style="font-size:16px; color:#1e293b; font-weight:600;">{{ITEM_NAME}}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-top:10px;">
                                            <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Return Date</span><br>
                                            <span style="font-size:16px; color:#1e293b; font-weight:600;">{{DATE_TIME}}</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;">
                            <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                            <p style="margin:0;">This notification email was automatically sent by <b>{{COMPANY_NAME}}</b> system.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>`
            },
            'new_ticket_cust': {
                'tr_subj': 'Destek Talebiniz Alındı [{{TICKET_NO}}]',
                'tr_body': `<!DOCTYPE html>
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
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr><td align="center">
            <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                <tr><td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                    <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                </td></tr>
                <tr><td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                    <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Destek Talebiniz Alındı 📧</h1>
                    <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Merhaba <b>{{CUSTOMER_NAME}}</b>,</p>
                    <div class="meta-box" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Bilet Numarası</span><br>
                                <span style="font-size:16px; color:#2563eb; font-weight:700;">{{TICKET_NO}}</span>
                            </td></tr>
                            <tr><td style="padding-top:10px;">
                                <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Talep Konusu</span><br>
                                <span style="font-size:15px; color:#1e293b; font-weight:600;">{{SUBJECT}}</span>
                            </td></tr>
                        </table>
                    </div>
                </td></tr>
                <tr><td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;">
                    <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                    <p style="margin:0;">Bu bilgilendirme e-postası <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>`,
                'en_subj': 'Support Request Received [{{TICKET_NO}}]',
                'en_body': `<!DOCTYPE html>
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
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr><td align="center">
            <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                <tr><td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                    <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                </td></tr>
                <tr><td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                    <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Support Request Received 📧</h1>
                    <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Hello <b>{{CUSTOMER_NAME}}</b>,</p>
                    <div class="meta-box" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Ticket Number</span><br>
                                <span style="font-size:16px; color:#2563eb; font-weight:700;">{{TICKET_NO}}</span>
                            </td></tr>
                            <tr><td style="padding-top:10px;">
                                <span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Request Subject</span><br>
                                <span style="font-size:15px; color:#1e293b; font-weight:600;">{{SUBJECT}}</span>
                            </td></tr>
                        </table>
                    </div>
                </td></tr>
                <tr><td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;">
                    <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                    <p style="margin:0;">This informational email was sent via <b>{{COMPANY_NAME}}</b>.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>`
            },
            'new_ticket_agent': {
                'tr_subj': 'Yeni Destek Talebi Atandı: {{TICKET_NO}}',
                'tr_body': `<!DOCTYPE html>
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
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr><td align="center">
            <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                <tr><td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                    <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                </td></tr>
                <tr><td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                    <h1 style="margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;">Yeni Destek Talebi Atandı 🔔</h1>
                    <p style="margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;">Merhaba <b>{{AGENT_NAME}}</b>,<br>Departmanınıza yeni bir destek talebi atanmıştır.</p>
                    <div class="meta-box" style="background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:30px;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Bilet Numarası</span><br>
                                <span style="font-size:16px; color:#1e293b; font-weight:700;">#{{TICKET_NO}}</span>
                            </td></tr>
                            <tr><td style="padding:10px 0; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Müşteri Adı</span><br>
                                <span style="font-size:15px; color:#1e293b; font-weight:600;">{{CUSTOMER_NAME}}</span>
                            </td></tr>
                            <tr><td style="padding-top:10px;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Talep Konusu</span><br>
                                <span style="font-size:15px; color:#1e293b;">{{SUBJECT}}</span>
                            </td></tr>
                        </table>
                    </div>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:14px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
                        <strong style="color:#0f172a; display:block; margin-bottom:10px;">Talep Mesajı:</strong>
                        {{MESSAGE}}
                    </div>
                </td></tr>
                <tr><td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;">
                    <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                    <p style="margin:0;">Bu bir <b>{{COMPANY_NAME}}</b> otomatik bilet bildirim sistemidir.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>`,
                'en_subj': 'New Support Ticket Assigned: {{TICKET_NO}}',
                'en_body': `<!DOCTYPE html>
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
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr><td align="center">
            <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                <tr><td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                    <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                </td></tr>
                <tr><td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                    <h1 style="margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;">New Ticket Assigned 🔔</h1>
                    <p style="margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;">Hello <b>{{AGENT_NAME}}</b>,<br>A new support ticket has been assigned to your department.</p>
                    <div class="meta-box" style="background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:30px;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Ticket No</span><br>
                                <span style="font-size:16px; color:#1e293b; font-weight:700;">#{{TICKET_NO}}</span>
                            </td></tr>
                            <tr><td style="padding:10px 0; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Customer Name</span><br>
                                <span style="font-size:15px; color:#1e293b; font-weight:600;">{{CUSTOMER_NAME}}</span>
                            </td></tr>
                            <tr><td style="padding-top:10px;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Subject</span><br>
                                <span style="font-size:15px; color:#1e293b;">{{SUBJECT}}</span>
                            </td></tr>
                        </table>
                    </div>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:14px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
                        <strong style="color:#0f172a; display:block; margin-bottom:10px;">Ticket Message:</strong>
                        {{MESSAGE}}
                    </div>
                </td></tr>
                <tr><td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;">
                    <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                    <p style="margin:0;">This is an automated ticket notification system from <b>{{COMPANY_NAME}}</b>.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>`
            },
            'reply_cust': {
                'tr_subj': 'Talebinize Yanıt Geldi [{{TICKET_NO}}]',
                'tr_body': `<!DOCTYPE html>
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
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr><td align="center">
            <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                <tr><td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                    <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                </td></tr>
                <tr><td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                    <h1 style="margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;">Talebinize Yanıt Geldi 💬</h1>
                    <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Merhaba <b>{{CUSTOMER_NAME}}</b>,<br>Açmış olduğunuz destek talebi destek uzmanımız tarafından yanıtlanmıştır.</p>
                    <div class="meta-box" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Bilet Takip No</span><br>
                                <span style="font-size:16px; color:#2563eb; font-weight:700;">#{{TICKET_NO}}</span>
                            </td></tr>
                            <tr><td style="padding-top:10px;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Yanıtlayan Uzman</span><br>
                                <span style="font-size:15px; color:#1e293b; font-weight:600;">{{AGENT_NAME}}</span>
                            </td></tr>
                        </table>
                    </div>
                    <div style="background:#fffbeb; border-left:4px solid #f59e0b; padding:25px; margin-bottom:35px; font-size:15px; color:#451a03; border-radius:4px; line-height:1.7;">
                        <strong style="color:#92400e; display:block; margin-bottom:10px; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">Cevap Mesajı:</strong>
                        {{MESSAGE}}
                    </div>
                </td></tr>
                <tr><td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;">
                    <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                    <p style="margin:0;">Bu bilgilendirme <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>`,
                'en_subj': 'New Reply to Your Ticket [{{TICKET_NO}}]',
                'en_body': `<!DOCTYPE html>
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
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr><td align="center">
            <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                <tr><td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                    <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                </td></tr>
                <tr><td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                    <h1 style="margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;">New Reply to Your Ticket 💬</h1>
                    <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Hello <b>{{CUSTOMER_NAME}}</b>,<br>The support ticket you opened has been replied to by our support agent.</p>
                    <div class="meta-box" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Ticket No</span><br>
                                <span style="font-size:16px; color:#2563eb; font-weight:700;">#{{TICKET_NO}}</span>
                            </td></tr>
                            <tr><td style="padding-top:10px;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Replied By</span><br>
                                <span style="font-size:15px; color:#1e293b; font-weight:600;">{{AGENT_NAME}}</span>
                            </td></tr>
                        </table>
                    </div>
                    <div style="background:#fffbeb; border-left:4px solid #f59e0b; padding:25px; margin-bottom:35px; font-size:15px; color:#451a03; border-radius:4px; line-height:1.7;">
                        <strong style="color:#92400e; display:block; margin-bottom:10px; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">Reply Message:</strong>
                        {{MESSAGE}}
                    </div>
                </td></tr>
                <tr><td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;">
                    <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                    <p style="margin:0;">This notification was sent via <b>{{COMPANY_NAME}}</b>.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>`
            },
            'reply_agent': {
                'tr_subj': 'Bilete Müşteri Yanıt Yazdı: {{TICKET_NO}}',
                'tr_body': `<!DOCTYPE html>
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
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr><td align="center">
            <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                <tr><td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                    <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                </td></tr>
                <tr><td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                    <h1 style="margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;">Bilete Müşteri Yanıt Yazdı 💬</h1>
                    <p style="margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;">Merhaba <b>{{AGENT_NAME}}</b>,<br>Sorumluluğunuzdaki destek biletine müşteri tarafından yeni bir yanıt eklendi.</p>
                    <div class="meta-box" style="background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:25px;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Bilet No</span><br>
                                <span style="font-size:16px; color:#1e293b; font-weight:700;">#{{TICKET_NO}}</span>
                            </td></tr>
                            <tr><td style="padding:10px 0; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Talep Eden Müşteri</span><br>
                                <span style="font-size:15px; color:#0f172a; font-weight:600;">{{CUSTOMER_NAME}}</span>
                            </td></tr>
                            <tr><td style="padding-top:10px;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Konu</span><br>
                                <span style="font-size:14px; color:#1e293b;">{{SUBJECT}}</span>
                            </td></tr>
                        </table>
                    </div>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:15px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02); line-height:1.7;">
                        <strong style="color:#0f172a; display:block; margin-bottom:10px; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">Müşterinin Mesajı:</strong>
                        {{MESSAGE}}
                    </div>
                </td></tr>
                <tr><td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;">
                    <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                    <p style="margin:0;">Bu bir <b>{{COMPANY_NAME}}</b> otomatik talep bildirim sistemidir.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>`,
                'en_subj': 'Customer Replied to Ticket: {{TICKET_NO}}',
                'en_body': `<!DOCTYPE html>
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
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr><td align="center">
            <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                <tr><td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                    <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                </td></tr>
                <tr><td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                    <h1 style="margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;">Customer Replied to Ticket 💬</h1>
                    <p style="margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;">Hello <b>{{AGENT_NAME}}</b>,<br>A new reply has been added by the customer to a support ticket assigned to you.</p>
                    <div class="meta-box" style="background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:25px;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Ticket No</span><br>
                                <span style="font-size:16px; color:#1e293b; font-weight:700;">#{{TICKET_NO}}</span>
                            </td></tr>
                            <tr><td style="padding:10px 0; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Customer Name</span><br>
                                <span style="font-size:15px; color:#0f172a; font-weight:600;">{{CUSTOMER_NAME}}</span>
                            </td></tr>
                            <tr><td style="padding-top:10px;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Subject</span><br>
                                <span style="font-size:14px; color:#1e293b;">{{SUBJECT}}</span>
                            </td></tr>
                        </table>
                    </div>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:15px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02); line-height:1.7;">
                        <strong style="color:#0f172a; display:block; margin-bottom:10px; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">Customer's Message:</strong>
                        {{MESSAGE}}
                    </div>
                </td></tr>
                <tr><td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;">
                    <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                    <p style="margin:0;">This is an automated ticket notification system from <b>{{COMPANY_NAME}}</b>.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>`
            },
            'resolved': {
                'tr_subj': 'Destek Talebiniz Çözüldü [{{TICKET_NO}}]',
                'tr_body': `<!DOCTYPE html>
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
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr><td align="center">
            <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                <tr><td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                    <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                </td></tr>
                <tr><td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                    <div style="text-align:center; margin-bottom:20px;">
                        <span style="background-color:#dcfce7; color:#16a34a; padding:10px 20px; border-radius:50px; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:1px;">✅ ÇÖZÜLDÜ</span>
                    </div>
                    <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Destek Talebiniz Çözüldü</h1>
                    <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Merhaba <b>{{CUSTOMER_NAME}}</b>,<br>İlettiğiniz destek talebi başarıyla sonuçlandırılmış ve kapatılmıştır.</p>
                    <div class="meta-box" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Bilet Takip No</span><br>
                                <span style="font-size:16px; color:#1e293b; font-weight:700;">#{{TICKET_NO}}</span>
                            </td></tr>
                            <tr><td style="padding:10px 0; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Konu</span><br>
                                <span style="font-size:15px; color:#1e293b;">{{SUBJECT}}</span>
                            </td></tr>
                            <tr><td style="padding-top:10px;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Destek Uzmanı</span><br>
                                <span style="font-size:15px; color:#1e293b; font-weight:600;">{{AGENT_NAME}}</span>
                            </td></tr>
                        </table>
                    </div>
                    <div style="background:#fff; border:1px solid #dcfce7; border-left:4px solid #16a34a; border-radius:6px; padding:20px; margin-bottom:35px; font-size:14px; color:#1e293b; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
                        <strong style="color:#16a34a; display:block; margin-bottom:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Sonuç / Çözüm Notu:</strong>
                        {{MESSAGE}}
                    </div>
                </td></tr>
                <tr><td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;">
                    <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                    <p style="margin:0;">Bu bilgilendirme <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>`,
                'en_subj': 'Your Support Ticket is Resolved [{{TICKET_NO}}]',
                'en_body': `<!DOCTYPE html>
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
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:40px 0;">
        <tr><td align="center">
            <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;">
                <tr><td class="header" align="center" style="padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;">
                    <img src="{{LOGO_SRC}}" alt="Logo" width="160" style="max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;">
                </td></tr>
                <tr><td class="content" style="padding:45px 50px; color:#1e293b; line-height:1.6;">
                    <div style="text-align:center; margin-bottom:20px;">
                        <span style="background-color:#dcfce7; color:#16a34a; padding:10px 20px; border-radius:50px; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:1px;">✅ RESOLVED</span>
                    </div>
                    <h1 style="margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;">Your Support Ticket is Resolved</h1>
                    <p style="margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;">Hello <b>{{CUSTOMER_NAME}}</b>,<br>The support ticket you submitted has been successfully resolved and closed.</p>
                    <div class="meta-box" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Ticket No</span><br>
                                <span style="font-size:16px; color:#1e293b; font-weight:700;">#{{TICKET_NO}}</span>
                            </td></tr>
                            <tr><td style="padding:10px 0; border-bottom:1px solid #e2e8f0;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Subject</span><br>
                                <span style="font-size:15px; color:#1e293b;">{{SUBJECT}}</span>
                            </td></tr>
                            <tr><td style="padding-top:10px;">
                                <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Support Agent</span><br>
                                <span style="font-size:15px; color:#1e293b; font-weight:600;">{{AGENT_NAME}}</span>
                            </td></tr>
                        </table>
                    </div>
                    <div style="background:#fff; border:1px solid #dcfce7; border-left:4px solid #16a34a; border-radius:6px; padding:20px; margin-bottom:35px; font-size:14px; color:#1e293b; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
                        <strong style="color:#16a34a; display:block; margin-bottom:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Resolution Note:</strong>
                        {{MESSAGE}}
                    </div>
                </td></tr>
                <tr><td align="center" style="padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;">
                    <hr style="border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;">
                    <p style="margin:0;">This notification was sent via <b>{{COMPANY_NAME}}</b>.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>`
            },
            'imap_forward': {
                'tr_subj': '🔔 Yönlendirildi: {{SUBJECT}} [{{TICKET_NO}}]',
                'tr_body': "Merhaba,<br><br>Bu mesaj IMAP üzerinden sisteme aktarılan yeni bir bilet bildirimidir.<br><br><b>Müşteri:</b> {{CUSTOMER_NAME}}<br><b>Konu:</b> {{SUBJECT}}<br><b>Bilet No:</b> {{TICKET_NO}}<br><br><div style='background:#f9f9f9;padding:15px;border-left:4px solid #17a2b8;margin:15px 0;'>{{MESSAGE}}</div><br><div style='text-align:center;'><a href='{{LINK}}' style='display:inline-block;padding:10px 20px;background:#17a2b8;color:#fff;text-decoration:none;border-radius:5px;'>Bileti Görüntüle</a></div>",
                'en_subj': '🔔 Forwarded: {{SUBJECT}} [{{TICKET_NO}}]',
                'en_body': "Hello,<br><br>This message is a notification for a new ticket received via IMAP forwarding.<br><br><b>Customer:</b> {{CUSTOMER_NAME}}<br><b>Subject:</b> {{SUBJECT}}<br><b>Ticket No:</b> {{TICKET_NO}}<br><br><div style='background:#f9f9f9;padding:15px;border-left:4px solid #17a2b8;margin:15px 0;'>{{MESSAGE}}</div><br><div style='text-align:center;'><a href='{{LINK}}' style='display:inline-block;padding:10px 20px;background:#17a2b8;color:#fff;text-decoration:none;border-radius:5px;'>View Ticket</a></div>"
            }
        };


        const def = defaults[key] || {tr_subj:'', tr_body:'', en_subj:'', en_body:''};

        $('#drawerTitle').text(label);
        $('#templateKey').val(key);
        $('#templateSubject_tr').val(templateData['mail_'+key+'_tr_subject'] || templateData['mail_'+key+'_subject'] || def.tr_subj);
        $('#templateSubject_en').val(templateData['mail_'+key+'_en_subject'] || def.en_subj);

        const bodyTr = templateData['mail_'+key+'_tr_body'] || templateData['mail_'+key+'_body'] || def.tr_body;
        const bodyEn = templateData['mail_'+key+'_en_body'] || def.en_body;

        $('#template-raw-tr').val(bodyTr);
        $('#template-raw-en').val(bodyEn);

        $('#templateStatus').prop('checked', (templateData['mail_'+key+'_status'] || 'active') === 'active');
        $('#templateDrawer').addClass('open');
        $('#drawerOverlay').fadeIn();

        // Auto-detect HTML mode if content starts with doctype or html
        if (bodyTr.trim().toLowerCase().startsWith('<!doctype') || bodyTr.trim().toLowerCase().startsWith('<html')) {
            switchEditorMode('html');
        } else {
            switchEditorMode('visual');
        }

        switchDrawerLang(currentDefaultLang || '<?= s("mail_default_lang", $isTr ? "tr" : "en") ?>');
    }

    let currentEditorMode = 'visual';
    function switchEditorMode(mode) {
        currentEditorMode = mode;
        if (mode === 'html') {
            $('#template-editor-tr, #template-editor-en').hide();
            $('#template-raw-tr, #template-raw-en').show();
            $('#btn-mode-html').addClass('active');
            $('#btn-mode-visual').removeClass('active');
        } else {
            $('#template-raw-tr, #template-raw-en').hide();
            $('#template-editor-tr, #template-editor-en').show();
            $('#btn-mode-visual').addClass('active');
            $('#btn-mode-html').removeClass('active');

            // Render HTML to Preview iFrames
            renderPreview('tr');
            renderPreview('en');
        }
    }

    function renderPreview(lang) {
        let html = $('#template-raw-' + lang).val();
        const iframe = document.getElementById('template-preview-' + lang);
        if (iframe && iframe.contentWindow) {
            const doc = iframe.contentWindow.document;
            doc.open();

                const sampleVars = {
                'LOGO_SRC': '<?= getMailLogoBase64() ?>',
                'COMPANY_NAME': '<?= s("company_name", "Eaprimus") ?>',
                'SITE_TITLE': '<?= s("company_name", "Eaprimus") ?>',
                'mail_from_address': '<?= s("mail_from_address", "destek@sirket.com") ?>',
                'NAME': '[Personel Adı]',
                'CUSTOMER_NAME': '[Müşteri Adı]',
                'AGENT_NAME': '[Destek Uzmanı]',
                'USERNAME': '[Kullanıcı Adı]',
                'ITEM_NAME': '[Cihaz/Varlık Adı]',
                'TICKET_NO': '[Talep No]',
                'SUBJECT': '[Konu]',
                'MESSAGE': '[Mesaj/Cevap İçeriği]',
                'ACTIVATION_LINK': '#',
                'LINK': '#',
                'logo_url': '<?= getMailLogoBase64() ?>',
                'site_name': '<?= s("company_name", "Eaprimus") ?>',
                'code': '726007',
                'reset_link': '#'
            };

            for (const key in sampleVars) {
                const regex = new RegExp('{{' + key + '}}', 'gi');
                html = html.replace(regex, sampleVars[key]);
            }

            // Inject auto-scaling fix to prevent overflow
            const responsiveStyle = `
                <style>
                    body { margin: 0; padding: 10px; background: #f4f6f8; overflow-x: hidden; }
                    /* Force tables to fit if they are wider than preview */
                    table { max-width: 100% !important; height: auto !important; }
                    img { max-width: 100% !important; height: auto !important; }
                </style>
            `;
            doc.write(responsiveStyle + html);
            doc.close();
        }
    }

    function switchDrawerLang(l) {
        if(l==='tr'){ $('#drawer_tr').show(); $('#drawer_en').hide(); $('#btn-tr').addClass('active'); $('#btn-en').removeClass('active'); }
        else { $('#drawer_tr').hide(); $('#drawer_en').show(); $('#btn-en').addClass('active'); $('#btn-tr').removeClass('active'); }
    }

    function closeTemplateDrawer() { $('#templateDrawer').removeClass('open'); $('#drawerOverlay').fadeOut(); }

    function saveTemplate() {
        const key = $('#templateKey').val();
        const trBody = $('#template-raw-tr').val();
        const enBody = $('#template-raw-en').val();

        $.post('anasayfa?route=sistem-ayarlari', {
            section: 'mailtemplates',
            csrf_token: '<?= csrf_token() ?>',
            template_key: key,
            ['mail_'+key+'_status']: $('#templateStatus').is(':checked') ? 'active' : 'passive',
            ['mail_'+key+'_tr_subject']: $('#templateSubject_tr').val(),
            ['mail_'+key+'_tr_body']: trBody,
            ['mail_'+key+'_en_subject']: $('#templateSubject_en').val(),
            ['mail_'+key+'_en_body']: enBody
        }, () => location.reload());
    }

    function saveGeneralSetting(k, v) {
        $.post('anasayfa?route=sistem-ayarlari', { action:'save_single_setting', key:k, value:v, csrf_token:'<?= csrf_token() ?>' },
        () => Swal.fire({icon:'success', title:'<?= __("success_save") ?>', toast:true, position:'top-end', showConfirmButton:false, timer:2000}));
    }

    function addStatusRow() {
        const container = $('#status-container');
        if (!container.length) return;
        const row = $(`<div class="row align-items-center mb-3 status-row">
            <div class="col-md-3"><input type="text" name="status_key[]" class="form-control form-control-sm"></div>
            <div class="col-md-3"><input type="text" name="status_label[]" class="form-control form-control-sm"></div>
            <div class="col-md-2"><input type="color" name="status_color[]" class="form-control form-control-sm mx-auto" value="#64748b" style="width:40px;"></div>
            <div class="col-md-2 text-center"><div class="custom-control custom-switch"><input type="checkbox" name="status_show[]" class="custom-control-input" checked id="sw_${Date.now()}"><label class="custom-control-label" for="sw_${Date.now()}"></label></div></div>
            <div class="col-md-2 text-right"><button type="button" class="btn btn-sm text-danger" onclick="$(this).closest('.status-row').remove()"><i class="fas fa-trash"></i></button></div>
        </div>`);
        container.append(row);
    }
    function updateTemplateStatus(isChecked) {
        // immediate UI feedback if needed
    }

    function updateTelegramPreview($textarea) {
        let text = $textarea.val() || '';
        const nameAttr = $textarea.attr('name') || '';
        const isEn = nameAttr.includes('_en_');

        // Define language-specific variables
        const vars = isEn ? {
            'subject': 'Printer Connection Error',
            'ticket_no': 'TCK-1024',
            'priority': '🔥 High',
            'queue': 'IT Department',
            'user_name': 'John Doe',
            'agent_name': 'Jane Smith',
            'performer_name': 'Alice Johnson',
            'old_status': 'Open',
            'status': 'Resolved',
            'message': 'The printer does not appear on the network even though the cable is connected.',
            'link': 'https://destek.sirket.com/bilet-detay?id=1024'
        } : {
            'subject': 'Yazıcı Bağlantı Hatası',
            'ticket_no': 'TCK-1024',
            'priority': '🔥 Yüksek',
            'queue': 'Bilgi İşlem',
            'user_name': 'Ahmet Yılmaz',
            'agent_name': 'Mehmet Demir',
            'performer_name': 'Ayşe Kaya',
            'old_status': 'Açık',
            'status': 'Çözüldü',
            'message': 'Yazıcı kablosu takılı olmasına rağmen ağda görünmüyor, çıktı alamıyoruz.',
            'link': 'http://localhost/bilet-detay?id=1024'
        };

        // Replace placeholders: e.g. {{subject}} -> vars['subject']
        for (const key in vars) {
            const regex = new RegExp('{{' + key + '}}', 'g');
            text = text.replace(regex, vars[key]);
        }

        // We find the .tg-preview-container .tg-message-bubble element inside the same parent column and update it.
        const $bubble = $textarea.parent().find('.tg-message-bubble');
        const currentTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const timeHtml = `<span style="float: right; font-size: 11px; color: #708499; margin-left: 8px; margin-top: 5px; user-select: none;">${currentTime} <span style="color: #4fc3f7;">✓✓</span></span><div style="clear: both;"></div>`;
        $bubble.html(text + timeHtml);
    }

    $(document).ready(function() {
        // Bind input events on all Telegram textareas
        $('textarea[name^="tg_"]').on('input', function() {
            updateTelegramPreview($(this));
        });

        // Trigger input event on page load to initialize the previews
        $('textarea[name^="tg_"]').trigger('input');

        // Check for edit_template query parameter to auto-open drawer
        const urlParams = new URLSearchParams(window.location.search);
        const editTpl = urlParams.get('edit_template');
        if (editTpl) {
            const templateLabels = {
                'new_ticket_cust': '<?= __("tpl_new_ticket_cust") ?>',
                'new_ticket_agent': '<?= __("tpl_new_ticket_agent") ?>',
                'reply_cust': '<?= __("tpl_reply_cust") ?>',
                'reply_agent': '<?= __("tpl_reply_agent") ?>',
                'resolved': '<?= __("tpl_resolved") ?>',
                'imap_forward': '<?= __("tpl_imap_forward") ?>',
                'user_invitation': '<?= __("user_invitation") ?>',
                'user_registration': '<?= __("user_registration") ?>',
                'asset_assigned': '<?= __("asset_assigned") ?>',
                'asset_returned': '<?= __("asset_returned") ?>'
            };
            let label = templateLabels[editTpl] || editTpl;
            openTemplateDrawer(editTpl, label);
        }
    });
</script>

<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

<!-- Page Specific Tip -->
<script>
if (typeof isTr === 'undefined') {
    var isTr = <?= $isTr ? 'true' : 'false' ?>;
}
document.addEventListener("DOMContentLoaded", function() {
    if (localStorage.getItem('eaprimus_tip_ayarlar') !== 'true') {
        setTimeout(function() {
            if (typeof Swal !== 'undefined') {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 6000,
                    timerProgressBar: true,
                    background: '#0f1b3d',
                    color: '#fff',
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer)
                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                    }
                });
                Toast.fire({
                    icon: 'info',
                    iconColor: '#60a5fa',
                    title: typeof isTr !== 'undefined' && isTr ? 'İpucu' : 'Tip',
                    text: typeof isTr !== 'undefined' && isTr ? 'Bu sayfadan sisteminizin tüm ana ayarlarını, şirket logonuzu ve bildirim (Mail/Telegram) ayarlarını yapılandırabilirsiniz.' : 'From this page you can configure all main settings, your company logo, and notification (Mail/Telegram) settings.'
                });
                localStorage.setItem('eaprimus_tip_ayarlar', 'true');
            }
        }, 1500);
    }

    const btnRegen = document.getElementById('btnRegenerateKeys');
    if (btnRegen) {
        btnRegen.addEventListener('click', function(e) {
            e.preventDefault();
            Swal.fire({
                title: isTr ? 'Emin misiniz?' : 'Are you sure?',
                text: isTr
                    ? 'API anahtarlarını sıfırlamak istediğinize emin misiniz? Mevcut entegrasyonlar kopacaktır.'
                    : 'Are you sure you want to regenerate keys? Existing integrations will stop working.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: isTr ? 'Evet, Sıfırla' : 'Yes, Reset',
                cancelButtonText: isTr ? 'İptal' : 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = this.closest('form');
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'regenerate_keys';
                    input.value = '1';
                    form.appendChild(input);
                    form.submit();
                }
            });
        });
    }

    // Revoke agent key event listener
    $(document).on('click', '.btn-revoke-agent', function(e) {
        e.preventDefault();
        const agentKeyId = $(this).data('id');
        const computerName = $(this).data('name');

        Swal.fire({
            title: isTr ? 'Emin misiniz?' : 'Are you sure?',
            text: isTr
                ? `${computerName} cihazının API yetkisini iptal etmek istediğinize emin misiniz? Bu cihaz artık senkronizasyon yapamayacaktır.`
                : `Are you sure you want to revoke API access for ${computerName}? This device will no longer be able to sync.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: isTr ? 'Evet, Yetkiyi İptal Et' : 'Yes, Revoke Access',
            cancelButtonText: isTr ? 'İptal' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'anasayfa?route=sistem-ayarlari',
                    method: 'POST',
                    data: {
                        action: 'revoke_agent_key_admin',
                        agent_key_id: agentKeyId,
                        csrf_token: $('input[name="csrf_token"]').val()
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: isTr ? 'Başarılı' : 'Success',
                                text: response.message
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: isTr ? 'Hata' : 'Error',
                                text: response.message
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: isTr ? 'Hata' : 'Error',
                            text: isTr ? 'Bir sistem hatası oluştu.' : 'A system error occurred.'
                        });
                    }
                });
            }
        });
    });
});
</script>
<script>
function togglePassword(element, isTr = true) {
    const input = element.closest('.input-group').querySelector('input');
    const icon = element.querySelector('i');
    const textSpan = element.querySelector('.password-toggle-text');

    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
        if (textSpan) textSpan.innerText = isTr ? 'Gizle' : 'Hide';
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
        if (textSpan) textSpan.innerText = isTr ? 'Göster' : 'Show';
    }
}

function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            Swal.fire(typeof isTr !== 'undefined' && isTr ? 'Kopyalandı!' : 'Copied!', '', 'success');
        }).catch(() => {
            fallbackCopyToClipboard(text);
        });
    } else {
        fallbackCopyToClipboard(text);
    }
}

function fallbackCopyToClipboard(text) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-999999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        document.execCommand('copy');
        Swal.fire(typeof isTr !== 'undefined' && isTr ? 'Kopyalandı!' : 'Copied!', '', 'success');
    } catch (err) {
        Swal.fire(typeof isTr !== 'undefined' && isTr ? 'Kopyalanamadı' : 'Failed to copy', typeof isTr !== 'undefined' && isTr ? 'Lütfen el ile kopyalayın' : 'Please copy manually', 'error');
    }
    document.body.removeChild(textArea);
}

$(document).ready(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const highlight = urlParams.get('highlight');
    const scroll = urlParams.get('scroll');
    
    if (scroll === 'spam_protection') {
        const el = $('#spam-protection-section');
        if (el.length) {
            $('html, body').animate({
                scrollTop: el.offset().top - 100
            }, 600);
            
            if (!$('#blink-style-spam').length) {
                $('<style id="blink-style-spam">')
                    .html(`
                    @keyframes blinkYellow {
                        0% { box-shadow: 0 0 10px rgba(245, 158, 11, 0); }
                        50% { box-shadow: 0 0 15px rgba(245, 158, 11, 0.8); }
                        100% { box-shadow: 0 0 10px rgba(245, 158, 11, 0); }
                    }
                    .blinking-spam { animation: blinkYellow 1s ease-in-out 3; }
                    `)
                    .appendTo('head');
            }
            el.addClass('blinking-spam');
            setTimeout(() => el.removeClass('blinking-spam'), 3000);
        }
    }

    if (highlight === 'company_name') {
        const el = $('#company_name');
        if (el.length) {
            $('html, body').animate({
                scrollTop: el.offset().top - 150
            }, 600);

            if (!$('#blink-style').length) {
                $('<style id="blink-style">')
                    .html(`
                        @keyframes blink-highlight {
                            0% { background-color: transparent; }
                            50% { background-color: rgba(245, 158, 11, 0.25); border-color: #f59e0b; box-shadow: 0 0 8px rgba(245, 158, 11, 0.4); }
                            100% { background-color: transparent; }
                        }
                        .blink-highlight {
                            animation: blink-highlight 0.8s ease-in-out 4;
                        }
                    `)
                    .appendTo('head');
            }
            el.addClass('blink-highlight').focus();
            setTimeout(() => {
                el.removeClass('blink-highlight');
            }, 3200);
        }
    }
});
</script>

