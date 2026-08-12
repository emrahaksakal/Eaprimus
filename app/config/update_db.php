<?php
/**
 * Eaprimus - One-Time Database Schema Migration & Update Utility
 * Run this file manually or via CLI (php app/config/update_db.php) when updating the database schema.
 */

require_once __DIR__ . '/db.php';
$pdo = db();

if (!$pdo) {
    die("Database connection failed.\n");
}

echo "Starting database schema check and migrations...\n";

try {
    try { $pdo->exec("ALTER TABLE users MODIFY COLUMN tc_no varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN theme varchar(50) DEFAULT 'light'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN lang varchar(10) DEFAULT 'tr'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN onboarding_done TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE customers ADD COLUMN avatar VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}

    // Ensure default permissions for Personnel (role 2) and Tech Support (role 3)
    try {
        $stmt2 = $pdo->prepare("SELECT id FROM user_perm WHERE role_id = 2 AND user_id IS NULL");
        $stmt2->execute();
        if (!$stmt2->fetch()) {
            $staff_perms = "main,biletler,bilet-detay,ticket-olustur,varliklar,varlik_detay,profil_duzenle,varliklar_view_own,biletler_view_own,varliklar_view_licenses,varliklar_view_accessories,varliklar_view_consumables,varliklar_view_components";
            $pdo->prepare("INSERT INTO user_perm (role_id, route_name) VALUES (2, ?)")->execute([$staff_perms]);
        }
        $stmt3 = $pdo->prepare("SELECT id FROM user_perm WHERE role_id = 3 AND user_id IS NULL");
        $stmt3->execute();
        if (!$stmt3->fetch()) {
            $tech_perms = "main,biletler,bilet-detay,ticket-olustur,varliklar,varlik_detay,musteriler,musteri_detay,musteri_ekle,musteri_duzenle,organizasyonlar,tedarikci_detay,kullanici_listele,kullanici_ekle,kullanici_duzenle,takimlar,kuyruklar,sla-dashboard,raporlar,network-discovery,profil_duzenle,sayim,amortisman,varliklar_view_all,varliklar_edit,biletler_view_all,biletler_edit,varliklar_checkin,varliklar_upload_attachment,varliklar_delete_attachment,varliklar_clear_logs,varliklar_view_licenses,varliklar_view_accessories,varliklar_view_consumables,varliklar_view_components";
            $pdo->prepare("INSERT INTO user_perm (role_id, route_name) VALUES (3, ?)")->execute([$tech_perms]);
        }
    } catch (Throwable $e) {}

    // Canned responses table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `canned_responses` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `title` VARCHAR(255) NOT NULL,
          `title_en` VARCHAR(255) DEFAULT NULL,
          `category` VARCHAR(100) NOT NULL DEFAULT 'Genel',
          `category_en` VARCHAR(100) DEFAULT NULL,
          `content` TEXT NOT NULL,
          `content_en` TEXT DEFAULT NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Throwable $e) {}

    // Tickets table columns
    $ticketsCols = [];
    try { $ticketsCols = $pdo->query("DESCRIBE tickets")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    if ($ticketsCols) {
        if (!in_array('closed_by', $ticketsCols)) { try { $pdo->exec("ALTER TABLE tickets ADD COLUMN closed_by INT DEFAULT NULL"); } catch (Throwable $e) {} }
        if (!in_array('closed_date', $ticketsCols)) { try { $pdo->exec("ALTER TABLE tickets ADD COLUMN closed_date DATETIME DEFAULT NULL"); } catch (Throwable $e) {} }
        if (!in_array('resolved_date', $ticketsCols)) { try { $pdo->exec("ALTER TABLE tickets ADD COLUMN resolved_date DATETIME DEFAULT NULL"); } catch (Throwable $e) {} }
        if (!in_array('agent_read', $ticketsCols)) { try { $pdo->exec("ALTER TABLE tickets ADD COLUMN agent_read TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {} }
        if (!in_array('unread_replies_count', $ticketsCols)) { try { $pdo->exec("ALTER TABLE tickets ADD COLUMN unread_replies_count INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {} }
    }

    // Notification queue table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `notification_queue` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `type` varchar(50) NOT NULL,
          `recipient` varchar(255) NOT NULL,
          `subject` varchar(255) DEFAULT NULL,
          `body` longtext NOT NULL,
          `status` varchar(20) NOT NULL DEFAULT 'pending',
          `attempts` int(11) NOT NULL DEFAULT 0,
          `created_at` datetime NOT NULL,
          `sent_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `status_attempts` (`status`,`attempts`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    // Announcements tables
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            announcement_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ann_user (announcement_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Throwable $e) {}

    echo "Database migrations completed successfully!\n";
} catch (Throwable $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
}
