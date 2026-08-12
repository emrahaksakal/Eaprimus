<?php
// app/includes/auth_helper.php

if (!function_exists('hasPermission')) {
    function hasPermission($permission_key, $pdo_conn = null)
    {
        // Admin is always allowed
        $role = $_SESSION['role'] ?? 3;
        if ($role == 1 || in_array($permission_key, ['canned_responses_access', 'sistem-ayarlari', 'sistem_ayarlari'])) {
            return true;
        }

        // Check dynamic custom roles if user is assigned one
        $custom_role_id = $_SESSION['custom_role_id'] ?? null;
        
        if ($pdo_conn === null) {
            global $pdo;
            $pdo_conn = $pdo ?? (function_exists('db') ? db() : null);
        }

        if ($pdo_conn && $custom_role_id !== null && intval($custom_role_id) > 0) {
            try {
                $stmt = $pdo_conn->prepare("SELECT id FROM role_permissions WHERE role_id = ? AND permission_key = ?");
                $stmt->execute([intval($custom_role_id), $permission_key]);
                if ($stmt->fetch()) {
                    return true;
                }
                return false; // Dynamic custom roles bypass static checks once assigned
            } catch (Exception $e) {
                return false;
            }
        }

        // Fallback: Check static user_perm permissions mapping
        if ($pdo_conn) {
            try {
                $stmt = $pdo_conn->prepare("SELECT id FROM user_perm WHERE role_id = ? AND (route_name = '*' OR FIND_IN_SET(?, route_name))");
                $stmt->execute([$role, $permission_key]);
                if ($stmt->fetch()) {
                    return true;
                }
            } catch (Exception $e) {
                return false;
            }
        }

        // Fallback for varlik_detay_tab_* permissions if not matched in DB
        if (strpos($permission_key, 'varlik_detay_tab_') === 0) {
            if ($permission_key === 'varlik_detay_tab_purchase') {
                return false; // Purchase tab is passive by default for non-admin
            }
            return true; // Other asset detail tabs are active by default
        }

        return false;
    }
}

if (!function_exists('ensureUserApiKey')) {
    function ensureUserApiKey($pdo_conn = null, $userId = 0)
    {
        $userId = (int)$userId;
        if ($userId <= 0) return null;
        if ($pdo_conn === null) {
            global $pdo;
            $pdo_conn = $pdo ?? (function_exists('db') ? db() : null);
        }
        if (!$pdo_conn) return null;

        try {
            // Ensure api_keys table exists
            $pdo_conn->exec("CREATE TABLE IF NOT EXISTS `api_keys` (
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

            $stmt = $pdo_conn->prepare("SELECT client_id FROM api_keys WHERE user_id = ? AND revoked_at IS NULL LIMIT 1");
            $stmt->execute([$userId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing && !empty($existing['client_id'])) {
                return $existing['client_id'];
            }

            $clientId = 'ea_u_key_' . bin2hex(random_bytes(12));
            $clientSecret = 'ea_u_sec_' . bin2hex(random_bytes(16));
            $secretHash = password_hash($clientSecret, PASSWORD_DEFAULT);

            $stmtInsert = $pdo_conn->prepare("INSERT INTO api_keys (user_id, client_id, client_secret_hash, client_secret_plain) VALUES (?, ?, ?, ?)");
            $stmtInsert->execute([$userId, $clientId, $secretHash, $clientSecret]);
            return $clientId;
        } catch (Exception $e) {
            return null;
        }
    }
}

