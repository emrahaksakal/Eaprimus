<?php
if (function_exists('opcache_reset')) { @opcache_reset(); }
ob_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 1. OTURUM VE AYARLAR
require_once __DIR__ . '/../app/includes/session.php';

// QR Hızlı İzleme (varlik_detay) için giriş zorunluluğunu esnetiyoruz
$route = $_GET['route'] ?? 'main';
if ($route === 'cikis') {
    if (isset($_SESSION['user_id'])) {
        try {
            require_once __DIR__ . "/../app/config/db.php";
            $pdo = db();
            $user_id = $_SESSION['user_id'];
            if ($pdo) {
                $stmt = $pdo->prepare("UPDATE users SET is_online = 0 WHERE id = ?");
                $stmt->execute([$user_id]);
            }
        } catch (Throwable $e) {}
    }
    if (isset($_SESSION['lang'])) {
        setcookie('lang', $_SESSION['lang'], time() + (365 * 24 * 60 * 60), '/');
    }
    session_unset();
    session_destroy();
    
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $base_dir = rtrim(str_replace('/public', '', dirname($script_name)), '/\\');
    header("Location: " . $base_dir . "/giris");
    exit;
}
if ($route === 'varlik_detay' || $route === 'asset_detail') {
    // Sayısal ID istekleri yönetici detay sayfasıdır ve oturum gerektirir.
    // Sadece 16 karakterlik şifreli güvenli token (QR taraması) oturum gerektirmez.
    $req_id = $_GET['id'] ?? '';
    $is_token = (strlen($req_id) === 16 && !is_numeric($req_id));
    if (!$is_token) {
        requireLogin();
    }
} else if ($route === 'test_db_cli') {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 1;
} else {
    requireLogin();
}

require_once __DIR__ . "/../app/config/db.php";
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_theme') {
    ob_clean();
    header('Content-Type: application/json');
    $theme = $_POST['theme'] ?? 'light';
    if (in_array($theme, ['light', 'dark'])) {
        $_SESSION['theme'] = $theme;
        if (isset($_SESSION['user_id'])) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET theme = ? WHERE id = ?");
                $stmt->execute([$theme, $_SESSION['user_id']]);
            } catch (Exception $e) {}
        }
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_onboarding') {
    ob_clean();
    header('Content-Type: application/json');
    if (isset($_SESSION['user_id']) && isset($pdo) && $pdo !== null) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET onboarding_done = 1 WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

require_once __DIR__ . "/../app/includes/auth_helper.php";

// Dinamik site ayarları (db.php önbelleğinden alınır)
global $allSettings;
$_site_settings = $allSettings ?? [];
$_site_title = $_site_settings['site_title'] ?? 'Destek Sistemi';
$_favicon_path = $_site_settings['favicon_path'] ?? 'public/favicon.png';
$_logo_path = $_site_settings['logo_path'] ?? 'public/logo.png';
if ($_favicon_path && !str_starts_with($_favicon_path, 'public/') && !str_starts_with($_favicon_path, 'http')) $_favicon_path = 'public/' . $_favicon_path;
if ($_logo_path && !str_starts_with($_logo_path, 'public/') && !str_starts_with($_logo_path, 'http')) $_logo_path = 'public/' . $_logo_path;

// Fallback to defaults if files do not exist
if (!file_exists(__DIR__ . '/../' . $_logo_path)) {
    $_logo_path = 'public/logo.png';
}
if (!file_exists(__DIR__ . '/../' . $_favicon_path)) {
    $_favicon_path = 'public/favicon.png';
}
$_site_slogan = $_site_settings['site_slogan'] ?? 'Hızlı Destek Merkezi';
$_company_name = $_site_settings['company_name'] ?? 'Destek Ekibi';
// 2. BASE URL AYARI (ERKEN HESAPLAMA)
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$port = $_SERVER['SERVER_PORT'];
$disp_port = ($protocol == 'http' && $port == 80 || $protocol == 'https' && $port == 443) ? '' : ":$port";
$domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
$path = dirname($_SERVER['SCRIPT_NAME']);
$path = str_replace('\\', '/', $path); // Windows fix
if (substr($path, -7) === '/public') {
    $path = substr($path, 0, -7);
}
$path = rtrim($path, '/');
$base_url = "$protocol://$domain$disp_port$path/";

// 3. KULLANICI VE ROTA
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user_role = $_SESSION['role'] ?? 3; // 3: Guest/Misafir
$current_user_fullname = $_SESSION['fullname'] ?? 'Misafir';

// $route was already normalized above
$route = preg_replace('/[^a-z0-9_\-]/', '', $route);
$page_path = __DIR__ . "/../app/pages/" . $route . ".php";

// 4. YETKİ KONTROLÜ
$izin_var_mi = false;
$public_pages = ['403', '404', 'cikis', 'varlik_detay', 'asset_detail', 'view_attachment', 'profil_duzenle', 'profilim', 'profil', 'profile', 'test_db_cli', 'duyurular', 'toplu_ice_aktar', 'toplu-ice-aktar'];

if ($current_user_role == 1) {
    // Admin her zaman tam yetkiye sahiptir
    $izin_var_mi = true;
} elseif (in_array($route, $public_pages) || $route == 'varliklar' || $route == 'sistem-ayarlari' || $route == 'sistem_ayarlari' || (isset($_POST['action']) && $_POST['action'] == 'approve_signature')) {
    $izin_var_mi = true;
} else {
    $izin_var_mi = hasPermission($route, $pdo);
}


// 5. İŞLEYİCİLER
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['ajax_action']) || (isset($_POST['action']) && in_array($_POST['action'], ['approve_signature', 'quick_assign', 'upload_attachment', 'delete_attachment', 'trigger_agent_sync', 'save_single_setting', 'check_sync_status', 'fix_agent_api', 'generate_user_api_key_admin', 'revoke_user_api_key_admin', 'revoke_agent_key_admin'])))) {
    ob_clean();
    header('Content-Type: application/json');
    if ($izin_var_mi && file_exists($page_path)) {
        include $page_path;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Yetki yok.']);
    }
    exit();
}

if (!$izin_var_mi && $route !== '403') {
    // Erişim engellendiğinde 403 sayfasına yönlendirme yerine DIRECT include yapıyoruz.
    $page_path = __DIR__ . "/../app/pages/403.php";
    $_GET['hedef'] = $route; // 403.php nin hangi sayfaya gidilmek istendiğini bilmesi için
}

// Layout bypass for file exports or view_attachment file delivery
$is_file_delivery = false;
if ($route === 'view_attachment') {
    if (isset($_GET['recover_file'])) {
        $is_file_delivery = true;
    } elseif (isset($_GET['id'])) {
        $check_id = intval($_GET['id']);
        if ($check_id > 0) {
            $stmt_check = $pdo->prepare("SELECT file_path FROM attachments WHERE id = ?");
            $stmt_check->execute([$check_id]);
            $path_check = $stmt_check->fetchColumn();
            if (!$path_check) {
                $stmt_checkT = $pdo->prepare("SELECT file_path FROM ticket_attachments WHERE id = ?");
                $stmt_checkT->execute([$check_id]);
                $path_check = $stmt_checkT->fetchColumn();
                if ($path_check) {
                    $path_check = 'public/' . ltrim($path_check, '/');
                }
            }
            if ($path_check && file_exists(__DIR__ . '/../' . $path_check)) {
                $is_file_delivery = true;
            }
        }
    }
}

if (in_array($route, ['export_users_excel', 'export_users_pdf']) || $is_file_delivery || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_depreciation')) {
    ob_clean();
    if (file_exists($page_path)) {
        $view_attachment_delivery_phase = true;
        include $page_path;
    }
    exit();
}

?>
<!DOCTYPE html>
<html lang="<?= $current_lang ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>
        <?php
        $site_slogan = trim($_site_settings['site_description'] ?? '');
        echo htmlspecialchars($_site_title);
        if ($site_slogan !== '') {
            echo ' | ' . htmlspecialchars($site_slogan);
        }
        ?>
    </title>

    <base href="<?php echo $base_url; ?>">

    <?php
    $_fav = $_favicon_path;
    $_fav_mime = (str_ends_with(strtolower($_fav), '.ico')) ? 'image/x-icon' : 'image/png';
    ?>
    <link rel="icon" type="<?= $_fav_mime ?>" href="<?= htmlspecialchars($_fav) ?>?v=<?= time() ?>">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Eaprimus">
    <link rel="apple-touch-icon" href="icon-192.png">
    <script>
        window.EaprimusBaseUrl = '<?= $base_url ?>';
    </script>
    <script src="<?= $base_url ?>assets/js/eaprimus_realtime.js?v=<?= time() ?>"></script>
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
    <link rel="stylesheet" href="plugins/select2/css/select2.min.css">
    <link rel="stylesheet" href="plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">
    <script src="plugins/sweetalert2/sweetalert2.all.min.js"></script>
    <script src="plugins/chart.js/Chart.bundle.min.js"></script>
    <script src="plugins/jquery/jquery.min.js"></script>
    <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="plugins/bs-custom-file-input/bs-custom-file-input.min.js"></script>
    <script src="plugins/select2/js/select2.full.min.js"></script>
    <script src="plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
    <script src="dist/js/adminlte.min.js"></script>
    <link rel="stylesheet" href="plugins/toastr/toastr.min.css">
    <script src="plugins/toastr/toastr.min.js"></script>
    <link rel="stylesheet" href="plugins/jquery-ui/jquery-ui.min.css">
    <script src="plugins/jquery-ui/jquery-ui.min.js"></script>

    <?php if (file_exists(__DIR__ . '/dist/css/dark.css')): ?>
        <link rel="stylesheet" href="dist/css/dark.css?v=<?php echo time(); ?>">
    <?php endif; ?>

    <!-- Onboarding Tour -->
    <link rel="stylesheet" href="plugins/driver/driver.css"/>
    <script src="plugins/driver/driver.js.iife.js"></script>
    <script src="plugins/canvas-confetti/confetti.browser.min.js"></script>

    <script>
        // Dark Mode Logic defined early in head to prevent ReferenceErrors during page load
        function toggleDarkMode(e) {
            if (e) {
                if (e.preventDefault) e.preventDefault();
                if (e.stopPropagation) e.stopPropagation();
            }
            var body = document.body;
            body.classList.toggle('dark-mode');
            var isDark = body.classList.contains('dark-mode');
            var currentTheme = isDark ? 'dark' : 'light';
            localStorage.setItem('theme', currentTheme);
            updateDarkModeIcons(isDark);

            fetch('<?= $base_url ?>anasayfa', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=toggle_theme&theme=' + currentTheme + '&csrf_token=' + encodeURIComponent('<?= csrf_token() ?>')
            }).catch(function (err) { console.error('Dark mode save error:', err); });
        }
        window.toggleDarkMode = toggleDarkMode;

        function updateDarkModeIcons(isDark) {
            var icons = document.querySelectorAll('#navDarkModeIcon, #darkModeIcon, .darkModeIcon');
            icons.forEach(function (icon) {
                if (isDark) {
                    icon.classList.remove('fa-moon');
                    icon.classList.add('fa-sun');
                } else {
                    icon.classList.remove('fa-sun');
                    icon.classList.add('fa-moon');
                }
            });
        }
    </script>

    <style>
        /* Modern Driver.js Theme */
        .modern-driver-theme {
            border-radius: 16px !important;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2) !important;
            border: 1px solid rgba(0,0,0,0.05) !important;
            padding: 20px !important;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #ffffff !important;
        }
        .modern-driver-theme .fas {
            font-family: 'Font Awesome 5 Free' !important;
            font-weight: 900 !important;
            font-style: normal !important;
        }
        .modern-driver-theme .far {
            font-family: 'Font Awesome 5 Free' !important;
            font-weight: 400 !important;
            font-style: normal !important;
        }
        body.dark-mode .modern-driver-theme {
            background: #1e293b !important;
            border: 1px solid rgba(255,255,255,0.1) !important;
        }
        .modern-driver-theme .driver-popover-title {
            font-size: 1.25rem !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            margin-bottom: 10px !important;
        }
        body.dark-mode .modern-driver-theme .driver-popover-title {
            color: #f8fafc !important;
        }
        .modern-driver-theme .driver-popover-description {
            font-size: 0.95rem !important;
            color: #475569 !important;
            line-height: 1.6 !important;
        }
        body.dark-mode .modern-driver-theme .driver-popover-description {
            color: #cbd5e1 !important;
        }
        .modern-driver-theme .driver-popover-footer button {
            border-radius: 8px !important;
            font-weight: 600 !important;
            padding: 8px 16px !important;
            text-shadow: none !important;
        }
        .modern-driver-theme .driver-popover-next-btn {
            background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
            border: none !important;
            color: #fff !important;
        }
        .modern-driver-theme .driver-popover-prev-btn {
            background: #f1f5f9 !important;
            color: #475569 !important;
            border: none !important;
        }
        body.dark-mode .modern-driver-theme .driver-popover-prev-btn {
            background: #334155 !important;
            color: #e2e8f0 !important;
        }
        
        .nav-pills .nav-link.active,
        .nav-pills .show>.nav-link {
            background-color: var(--primary-color, #007bff) !important;
            color: #fff;
        }

        .content-wrapper {
            background-color: #f4f6f9;
        }

        body,
        .content-wrapper,
        .main-header,
        .card {
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .wrapper {
            overflow-x: hidden;
        }

        .row-highlight-pulse {
            animation: pulse-bg 2s infinite;
            background-color: rgba(60, 141, 188, 0.1) !important;
            border-radius: 8px;
        }

        @keyframes pulse-bg {
            0% { background-color: rgba(60, 141, 188, 0.05); }
            50% { background-color: rgba(60, 141, 188, 0.2); }
            100% { background-color: rgba(60, 141, 188, 0.05); }
        }
        
        <?php $_primary_color = $_site_settings['primary_color'] ?? '#007bff'; ?>
        :root {
            --primary-color: <?= htmlspecialchars($_primary_color) ?>;
        }
        .bg-primary, .badge-primary, .progress-bar { background-color: var(--primary-color) !important; color: #fff !important; }
        .btn-primary { background-color: var(--primary-color) !important; border-color: var(--primary-color) !important; color: #fff !important; }
        .btn-primary:hover, .btn-primary:active, .btn-primary:focus { background-color: var(--primary-color) !important; filter: brightness(0.9); border-color: var(--primary-color) !important; color: #fff !important; }
        .text-primary { color: var(--primary-color) !important; }
        .page-item.active .page-link { background-color: var(--primary-color) !important; border-color: var(--primary-color) !important; }
        .sidebar-dark-primary .nav-sidebar > .nav-item > .nav-link.active,
        .sidebar-dark-primary .nav-sidebar .nav-treeview > .nav-item > .nav-link.active { 
            background-color: var(--primary-color) !important; 
            color: #fff !important; 
        }
        .nav-sidebar .nav-link:hover {
            color: #fff !important;
        }
        .main-sidebar { border-right: 1px solid rgba(0,0,0,.1); }
        .nav-sidebar .nav-link p { font-weight: 500; }
        
        /* Sidebar Treeview Child Styling */
        .nav-treeview > .nav-item > .nav-link {
            padding-left: 2rem !important;
        }
        .nav-treeview > .nav-item > .nav-link.active {
            box-shadow: none !important;
        }

        /* Brand Link (Logo Sütunu) - Light Sweep & Theme Styling */
        .brand-link {
            position: relative !important;
            overflow: hidden !important;
            display: flex !important;
            align-items: center !important;
            padding: 0.8rem 1rem !important;
            transition: background-color 0.3s ease, border-color 0.3s ease !important;
            z-index: 1;
        }

        /* Işık Süzmesi (Light Sweep / Shimmer Animation) */
        .brand-link::before {
            content: '' !important;
            position: absolute !important;
            top: -60% !important;
            left: -130% !important;
            width: 70% !important;
            height: 220% !important;
            background: linear-gradient(
                115deg,
                rgba(255, 255, 255, 0) 0%,
                rgba(255, 255, 255, 0.08) 20%,
                rgba(255, 255, 255, 0.45) 50%,
                rgba(255, 255, 255, 0.08) 80%,
                rgba(255, 255, 255, 0) 100%
            ) !important;
            transform: rotate(25deg) !important;
            pointer-events: none !important;
            animation: brandLightSweep 6s infinite cubic-bezier(0.4, 0, 0.2, 1) !important;
            z-index: 2 !important;
        }

        .brand-link:hover::before {
            animation: brandLightSweepHover 0.85s cubic-bezier(0.4, 0, 0.2, 1) forwards !important;
        }

        @keyframes brandLightSweep {
            0% { left: -140%; }
            20% { left: 160%; }
            100% { left: 160%; }
        }

        @keyframes brandLightSweepHover {
            0% { left: -140%; }
            100% { left: 160%; }
        }

        /* Yazıda Şirket İsmi Kalın Ama Çok Değil (Semibold 600) */
        .brand-link .brand-text-custom,
        .brand-link .brand-text {
            font-weight: 600 !important;
            font-size: 1.05rem !important;
            letter-spacing: 0.35px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            transition: color 0.3s ease, letter-spacing 0.3s ease !important;
        }

        .brand-link:hover .brand-text-custom,
        .brand-link:hover .brand-text {
            letter-spacing: 0.5px !important;
        }

        /* Eklenecek Logolar - Dark Mode / Aydınlık Mode Uyumlu Logo Stilleri */
        .brand-link .brand-image {
            max-width: 40px !important;
            max-height: 35px !important;
            object-fit: contain !important;
            border-radius: 6px !important;
            opacity: 0.96 !important;
            float: none !important;
            margin-right: 0.65rem !important;
            margin-top: 0 !important;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.15)) !important;
            transition: filter 0.3s ease, transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
        }

        .brand-link:hover .brand-image {
            transform: scale(1.08) translateY(-1px) !important;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.25)) !important;
        }

        .brand-link .brand-icon-fallback {
            width: 33px !important;
            height: 33px !important;
            border-radius: 8px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin-right: 0.65rem !important;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8) !important;
            box-shadow: 0 3px 8px rgba(59, 130, 246, 0.35) !important;
            transition: transform 0.3s ease, box-shadow 0.3s ease !important;
        }

        .brand-link:hover .brand-icon-fallback {
            transform: scale(1.08) translateY(-1px) !important;
            box-shadow: 0 5px 12px rgba(59, 130, 246, 0.5) !important;
        }
        
        /* Dark Mode Sidebar Fixes */
        body.dark-mode .main-sidebar {
            background-color: #1a1d21 !important;
        }
        body.dark-mode .nav-sidebar .nav-link {
            color: #94a3b8 !important;
        }
        body.dark-mode .nav-sidebar .nav-link.active,
        body.dark-mode .nav-sidebar .nav-link:hover,
        .sidebar-dark-primary .nav-sidebar .nav-link.active {
            color: #fff !important;
        }
        body.dark-mode .nav-treeview > .nav-item > .nav-link.active {
            background-color: rgba(255,255,255,0.05) !important;
            border-left: 3px solid var(--primary-color);
            color: #fff !important;
        }
        
        /* Sidebar Dropdown Arrow Overlap Fix */
        .nav-sidebar .nav-link p {
            font-weight: 500;
        }
        
        /* 1. When sidebar is NOT collapsed (regular expanded state) */
        body:not(.sidebar-collapse) .sidebar .nav-sidebar .nav-link p {
            display: inline !important;
            opacity: 1 !important;
            visibility: visible !important;
        }
        body:not(.sidebar-collapse) .sidebar .nav-sidebar .nav-header {
            display: block !important;
            opacity: 1 !important;
            visibility: visible !important;
        }
        body:not(.sidebar-collapse) .sidebar .nav-sidebar .nav-link .right,
        body:not(.sidebar-collapse) .sidebar .nav-sidebar .nav-link .badge {
            display: inline-block !important;
            opacity: 1 !important;
            visibility: visible !important;
        }

        /* 2. When sidebar is collapsed */
        body.sidebar-collapse .sidebar .nav-sidebar .nav-link p,
        body.sidebar-collapse .sidebar .nav-sidebar .nav-header,
        body.sidebar-collapse .sidebar .nav-sidebar .nav-link .right,
        body.sidebar-collapse .sidebar .nav-sidebar .nav-link .badge {
            display: none !important;
            opacity: 0 !important;
            visibility: hidden !important;
            width: 0 !important;
        }

        /* 2b. Hide submenus by default when sidebar is collapsed */
        body.sidebar-collapse .sidebar .nav-sidebar .nav-treeview {
            display: none !important;
        }

        /* 2c. Collapsed sidebar layout & hover popouts */
        body.sidebar-collapse .main-sidebar,
        body.sidebar-collapse .main-sidebar .sidebar,
        body.sidebar-collapse .main-sidebar .os-host,
        body.sidebar-collapse .main-sidebar .os-padding,
        body.sidebar-collapse .main-sidebar .os-viewport,
        body.sidebar-collapse .main-sidebar .os-content {
            overflow: visible !important;
        }
        body.sidebar-collapse .sidebar .nav-sidebar > .nav-item {
            margin-bottom: 0px !important;
        }
        body.sidebar-collapse .sidebar .nav-sidebar .nav-link {
            padding: 0.42rem 0.5rem !important;
            margin: 0 4px !important;
            border-radius: 8px !important;
        }
        body.sidebar-collapse .sidebar .nav-sidebar > .nav-item > .nav-link.active {
            border-radius: 8px !important;
            margin: 0 4px !important;
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3) !important;
        }
        body.sidebar-collapse .sidebar .nav-sidebar > .nav-item > .nav-link.active::after,
        body.sidebar-collapse .sidebar .nav-sidebar > .nav-item > .nav-link.active::before {
            display: none !important;
        }
        body.sidebar-collapse .sidebar-bottom-spacer {
            display: none !important;
        }

        /* 3. Arrow & Badge absolute positioning alignment */
        .nav-sidebar .nav-link .right,
        .nav-sidebar .nav-link p .right,
        .nav-sidebar .nav-link i.right,
        .nav-sidebar .nav-link svg.right {
            position: absolute !important;
            right: 1rem !important;
            left: auto !important;
            top: 50% !important;
            margin-top: -6px !important;
        }

        /* --- SIDEBAR POPOUT SUBMENU ON HOVER (COLLAPSED STATE) --- */
        @media (min-width: 992px) {
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item {
                position: relative;
            }
            
            /* Increase z-index of hovered nav-item so it floats over others */
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active {
                z-index: 10000 !important;
            }
            
                /* Bridge removed, menu will touch the sidebar directly */
            
            /* Floating header (main menu item text) on hover or active */
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-link p,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-link p {
                display: block !important;
                opacity: 1 !important;
                visibility: visible !important;
                position: absolute !important;
                left: calc(100% + 8px) !important; /* Perfectly touch the sidebar edge without gap */
                top: 0 !important;
                width: 250px !important; /* Fixed unified width */
                box-sizing: border-box !important; /* Keep width exact despite padding */
                background-color: #1a202c !important; /* Premium dark background */
                color: #fff !important;
                padding: 12px 15px 12px 47px !important; /* 47px aligns with submenu text */
                border-radius: 0 6px 6px 0 !important; /* Full rounded right-side by default */
                box-shadow: 5px 0 15px rgba(0,0,0,0.3) !important;
                z-index: 9999 !important;
                font-weight: 600;
                border-left: 1px solid rgba(255,255,255,0.05);
                margin: 0 !important;
            }

            /* Transparent bridge strictly for the gap (prevents hover loss) without blocking text */
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-link p::before,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-link p::before {
                content: "";
                position: absolute;
                top: 0;
                left: -25px; /* Extend into the gap */
                width: 25px; /* Cover only the gap */
                height: 100%;
                background: transparent;
            }



                /* Bridge removed */

            /* Adjust header border-radius if it has a submenu */
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:has(.nav-treeview):hover > .nav-link p,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active:has(.nav-treeview) > .nav-link p {
                border-radius: 0 6px 0 0 !important; /* Only top-right rounded */
            }

            /* Hide the chevron arrow inside the floating header */
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-link p .right,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-link p .right {
                display: none !important;
            }

            /* Floating submenu container */
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-treeview,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-treeview {
                display: block !important;
                position: absolute !important;
                left: calc(100% + 8px) !important; /* Matches header left position */
                top: 48px !important; /* Below the floating header (header is ~48px tall) */
                width: 250px !important; /* Matches header width perfectly */
                box-sizing: border-box !important;
                background-color: #151a24 !important; /* Slightly darker body */
                border-radius: 0 0 6px 6px !important;
                box-shadow: 5px 5px 15px rgba(0,0,0,0.4) !important;
                z-index: 9998 !important;
                padding: 5px 0 !important;
                margin: 0 !important;
                border-left: 1px solid rgba(255,255,255,0.05);
            }

            /* Transparent bridge strictly for the gap for submenu container */
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-treeview::before,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-treeview::before {
                content: "";
                position: absolute;
                top: -44px; /* Cover header height */
                left: -25px; /* Extend into the gap */
                width: 25px; /* Cover only the gap */
                height: calc(100% + 44px);
                background: transparent;
            }

                /* Bridge removed */

            /* Submenu items inside the floating container */
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-treeview > .nav-item > .nav-link,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-treeview > .nav-item > .nav-link {
                padding: 8px 15px !important; /* Unified padding */
                width: 100% !important;
                box-sizing: border-box !important; /* Prevents overflow that cuts off badges */
                background-color: transparent !important;
                display: flex !important;
                align-items: center !important;
                position: relative !important; /* Ensures badges align inside the link */
            }
            
            /* Align icons in floating submenu and resolve overlap */
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-treeview > .nav-item > .nav-link i,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-treeview > .nav-item > .nav-link i,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-treeview > .nav-item > .nav-link .nav-icon,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-treeview > .nav-item > .nav-link .nav-icon {
                display: inline-block !important;
                position: static !important;
                margin-right: 10px !important;
                width: 22px !important; /* Fixed width so all texts align perfectly vertically */
                text-align: center !important;
                vertical-align: middle !important;
                font-size: 0.9rem !important; /* Uniform icon size */
            }
            
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-treeview > .nav-item > .nav-link p,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-treeview > .nav-item > .nav-link p {
                display: flex !important;
                align-items: center !important;
                flex: 1 !important;
                opacity: 1 !important;
                visibility: visible !important;
                position: static !important;
                width: auto !important; /* Prevents blowing past parent limits */
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                vertical-align: middle !important;
                white-space: nowrap !important;
            }
            
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-treeview > .nav-item > .nav-link:hover,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-treeview > .nav-item > .nav-link:hover {
                background-color: rgba(255,255,255,0.05) !important;
                color: #fff !important;
            }

            /* Align badges inside the floating submenu using flexbox */
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-treeview .right,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item:hover > .nav-treeview .badge,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-treeview .right,
            body.sidebar-collapse .sidebar .nav-sidebar > .nav-item.hover-active > .nav-treeview .badge {
                position: static !important; /* Removed absolute to prevent overflow cutting */
                display: inline-block !important;
                opacity: 1 !important;
                visibility: visible !important;
                margin-top: 0 !important;
                transform: none !important; /* Reset transform */
                margin-left: auto !important; /* Pushes the badge perfectly to the right edge of the padding */
                right: auto !important;
                top: auto !important;
                padding: 4px 8px !important; /* Give the pill proper breathing room */
                min-width: 24px !important; /* Ensure the pill is nicely sized */
                text-align: center !important; /* Center the number */
                border-radius: 12px !important; /* Make it nicely rounded */
                line-height: 1 !important;
            }
        
        /* Premium Dark Mode Overrides (Tailwind Slate Theme) */
        .main-footer {
            background-color: #ffffff !important;
            border-top: 1px solid #dee2e6 !important;
        }
        body.dark-mode,
        body.dark-mode .content-wrapper,
        body.dark-mode .main-footer {
            background-color: #0f172a !important;
            color: #cbd5e1 !important;
        }
        body.dark-mode .main-sidebar {
            background-color: #0b0f19 !important;
            border-right: 1px solid #1e293b !important;
        }
        body.dark-mode .card {
            background-color: #1e293b !important;
            border: 1px solid #334155 !important;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06) !important;
        }
        body.dark-mode .card-header {
            border-bottom: 1px solid #334155 !important;
            background-color: rgba(255, 255, 255, 0.02) !important;
        }
        body.dark-mode .card-footer {
            border-top: 1px solid #334155 !important;
            background-color: rgba(255, 255, 255, 0.02) !important;
        }
        body.dark-mode .table {
            color: #cbd5e1 !important;
        }
        body.dark-mode .table th,
        body.dark-mode .table td {
            border-top: 1px solid #334155 !important;
            border-bottom: 1px solid #334155 !important;
        }
        body.dark-mode .table thead th {
            background-color: #1e293b !important;
            color: #f1f5f9 !important;
            border-bottom: 2px solid #334155 !important;
        }
        body.dark-mode .form-control,
        body.dark-mode .form-control-lg,
        body.dark-mode .bg-light,
        body.dark-mode select.form-control,
        body.dark-mode textarea.form-control,
        body.dark-mode input.form-control {
            background-color: #1e293b !important;
            color: #f8fafc !important;
            border: 1px solid #334155 !important;
        }
        body.dark-mode .form-control::placeholder {
            color: #64748b !important;
        }
        body.dark-mode .form-control:focus {
            background-color: #1e293b !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2) !important;
        }
        body.dark-mode .side-drawer {
            background-color: #0f172a !important;
            border-left: 1px solid #334155 !important;
            box-shadow: -10px 0 30px rgba(0, 0, 0, 0.5) !important;
        }
        body.dark-mode .side-drawer-header {
            background-color: #1e293b !important;
            border-bottom: 1px solid #334155 !important;
        }
        body.dark-mode .side-drawer-footer {
            background-color: #1e293b !important;
            border-top: 1px solid #334155 !important;
            color: #cbd5e1 !important;
        }
        body.dark-mode .modal-content {
            background-color: #0f172a !important;
            border: 1px solid #334155 !important;
            color: #cbd5e1 !important;
        }
        body.dark-mode .modal-header {
            border-bottom: 1px solid #334155 !important;
            background-color: #1e293b !important;
        }
        body.dark-mode .modal-footer {
            border-top: 1px solid #334155 !important;
            background-color: #1e293b !important;
        }
        body.dark-mode .bg-white {
            background-color: #1e293b !important;
            color: #f1f5f9 !important;
        }
        body.dark-mode .text-dark {
            color: #f1f5f9 !important;
        }
        body.dark-mode .text-muted {
            color: #94a3b8 !important;
        }
        body.dark-mode .border {
            border-color: #334155 !important;
        }
        body.dark-mode .badge-light {
            background-color: #334155 !important;
            color: #cbd5e1 !important;
            border-color: #475569 !important;
        }
        body.dark-mode .text-black-50 {
            color: rgba(255, 255, 255, 0.5) !important;
        }
        body.dark-mode select {
            color: #f8fafc !important;
        }
        body.dark-mode option {
            background-color: #1e293b !important;
            color: #f8fafc !important;
        }
        body.dark-mode .nav-tabs .nav-link.active {
            background-color: #1e293b !important;
            border-color: #334155 #334155 transparent !important;
            color: #fff !important;
        }
        body.dark-mode .nav-tabs {
            border-bottom: 1px solid #334155 !important;
        }
        body.dark-mode .nav-tabs .nav-link:hover {
            border-color: transparent transparent #334155 !important;
        }
        body.dark-mode .btn-light {
            background-color: #334155 !important;
            color: #f8fafc !important;
            border-color: #475569 !important;
        }
        body.dark-mode .btn-light:hover {
            background-color: #475569 !important;
            color: #fff !important;
        }
        body.dark-mode .content-header h1 {
            color: #f8fafc !important;
        }
        body.dark-mode .breadcrumb-item.active {
            color: #94a3b8 !important;
        }
        body.dark-mode .breadcrumb-item a {
            color: #3b82f6 !important;
        }
        body.dark-mode .alert-info {
            background-color: rgba(59, 130, 246, 0.1) !important;
            border-color: rgba(59, 130, 246, 0.3) !important;
            color: #93c5fd !important;
        }
        body.dark-mode .alert-warning {
            background-color: rgba(245, 158, 11, 0.1) !important;
            border-color: rgba(245, 158, 11, 0.3) !important;
            color: #fde047 !important;
        }
        body.dark-mode .alert-danger {
            background-color: rgba(239, 68, 68, 0.1) !important;
            border-color: rgba(239, 68, 68, 0.3) !important;
            color: #fca5a5 !important;
        }
        body.dark-mode .alert-success {
            background-color: rgba(16, 185, 129, 0.1) !important;
            border-color: rgba(16, 185, 129, 0.3) !important;
            color: #6ee7b7 !important;
        }
        body.dark-mode .modal-header .close, 
        body.dark-mode .modal-header .mailbox-attachment-close {
            color: #fff !important;
            opacity: 0.8 !important;
        }
    </style>
</head>

<body class="hold-transition sidebar-mini layout-fixed sidebar-collapse <?= (($_SESSION['theme'] ?? '') === 'dark') ? 'dark-mode' : '' ?>">
    <script>
        // Restore theme and sidebar state immediately to prevent flicker
        (function () {
            var sessionTheme = <?= json_encode($_SESSION['theme'] ?? null) ?>;
            var theme = sessionTheme || localStorage.getItem('theme') || 'light';
            if (theme === 'dark') {
                document.body.classList.add('dark-mode');
            } else {
                document.body.classList.remove('dark-mode');
            }
            var state = localStorage.getItem('sidebar_state');
            if (state === 'expanded') {
                document.body.classList.remove('sidebar-collapse');
            } else if (state === 'collapsed') {
                document.body.classList.add('sidebar-collapse');
            }
        })();

        window.toggleSidebarManual = function(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            var body = document.body;
            var isMobile = window.innerWidth < 992;
            if (isMobile) {
                if (body.classList.contains('sidebar-open')) {
                    body.classList.remove('sidebar-open');
                    body.classList.add('sidebar-collapse');
                    var overlay = document.querySelector('.sidebar-overlay');
                    if (overlay) overlay.remove();
                } else {
                    body.classList.add('sidebar-open');
                    body.classList.remove('sidebar-collapse');
                    var overlay = document.querySelector('.sidebar-overlay');
                    if (!overlay) {
                        overlay = document.createElement('div');
                        overlay.className = 'sidebar-overlay';
                        overlay.addEventListener('click', function() {
                            body.classList.remove('sidebar-open');
                            body.classList.add('sidebar-collapse');
                            overlay.remove();
                        });
                        body.appendChild(overlay);
                    }
                }
            } else {
                if (body.classList.contains('sidebar-collapse')) {
                    body.classList.remove('sidebar-collapse');
                    localStorage.setItem('sidebar_state', 'expanded');
                } else {
                    body.classList.add('sidebar-collapse');
                    localStorage.setItem('sidebar_state', 'collapsed');
                }
            }
        };

        window.toggleBrandLinkManual = function(event) {
            var body = document.body;
            if (body.classList.contains('sidebar-collapse')) {
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                window.toggleSidebarManual();
            }
        };
    </script>
    <!-- Preloader Removed for Debugging -->
    <!-- <div class="preloader flex-column justify-content-center align-items-center">
        <img class="animation__shake" src="logo.png" alt="Logo" height="60" width="60">
    </div> -->
    <div class="wrapper">
        <?php
        if ($current_user_id > 0) {
            if (file_exists(__DIR__ . '/../app/templates/navbar.php'))
                include __DIR__ . '/../app/templates/navbar.php';
            if (file_exists(__DIR__ . '/../app/templates/sidebar.php'))
                include __DIR__ . '/../app/templates/sidebar.php';
        }
        ?>

        <div class="content-wrapper" style="<?= ($current_user_id == 0) ? 'margin-left: 0 !important;' : '' ?>">
            <?php
            $activeBanners = [];
            if ($current_user_id > 0) {
                // Ensure announcements tables exist
                try {
                    $pdo->query("SELECT 1 FROM announcements LIMIT 1");
                } catch (Exception $ex) {
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
                    } catch (Exception $ex2) {}
                }

                try {
                    // Fetch user's teams
                    $userTeams = [0];
                    $stmtT = $pdo->prepare("SELECT team_id FROM teams_users WHERE user_id = ?");
                    $stmtT->execute([$current_user_id]);
                    $userTeams = array_merge($userTeams, $stmtT->fetchAll(PDO::FETCH_COLUMN));
                    $inTeams = implode(',', array_map('intval', $userTeams));

                    // Map current role to target_role
                    $roleMap = 'personnel';
                    if ($current_user_role == 1) $roleMap = 'admin';
                    elseif ($current_user_role == 3) $roleMap = 'tech';

                    $annQuery = "
                        SELECT a.* FROM announcements a
                        WHERE a.is_active = 1
                          AND (a.start_date IS NULL OR a.start_date <= NOW())
                          AND (a.end_date IS NULL OR a.end_date >= NOW())
                          AND (
                               a.target_role = 'all'
                               OR a.target_role = ?
                               OR (a.target_role = 'team' AND a.target_team_id IN ($inTeams))
                          )
                          AND a.id NOT IN (
                               SELECT r.announcement_id FROM announcement_reads r WHERE r.user_id = ?
                          )
                        ORDER BY a.created_at DESC
                    ";
                    $stmtAnn = $pdo->prepare($annQuery);
                    $stmtAnn->execute([$roleMap, $current_user_id]);
                    $activeBanners = $stmtAnn->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {}
            }
            ?>

            <?php if (!empty($activeBanners)): ?>
                <div class="container-fluid pt-3 px-4" id="system-announcements-container">
                    <?php foreach ($activeBanners as $banner):
                        $bType = $banner['type'] ?? 'info';
                        $alertClass = 'alert-info';
                        $iconClass = 'fa-info-circle';
                        $borderStyle = 'border-left: 5px solid #17a2b8;';
                        if ($bType === 'warning') {
                            $alertClass = 'alert-warning text-dark';
                            $iconClass = 'fa-exclamation-triangle';
                            $borderStyle = 'border-left: 5px solid #ffc107;';
                        } elseif ($bType === 'danger') {
                            $alertClass = 'alert-danger';
                            $iconClass = 'fa-radiation-alt';
                            $borderStyle = 'border-left: 5px solid #dc3545;';
                        } elseif ($bType === 'success') {
                            $alertClass = 'alert-success';
                            $iconClass = 'fa-check-circle';
                            $borderStyle = 'border-left: 5px solid #28a745;';
                        }
                    ?>
                        <div class="alert <?= $alertClass ?> alert-dismissible fade show shadow-sm border-0 mb-3 d-flex align-items-center justify-content-between p-3" 
                             role="alert" 
                             style="border-radius: 12px; <?= $borderStyle ?> font-size: 14.2px; background: rgba(255,255,255,0.08); backdrop-filter: blur(10px); color: inherit; box-shadow: 0 8px 30px rgba(0,0,0,0.05) !important; transition: transform 0.2s, box-shadow 0.2s;" 
                             id="announcement-banner-<?= $banner['id'] ?>"
                             data-end-time="<?= $banner['end_date'] ? strtotime($banner['end_date']) * 1000 : 0 ?>">
                            <div class="d-flex align-items-center pr-3">
                                <span class="d-flex align-items-center justify-content-center mr-3" style="width: 36px; height: 36px; border-radius: 50%; background: rgba(0,0,0,0.05);">
                                    <i class="fas <?= $iconClass ?> fa-lg"></i>
                                </span>
                                <div>
                                    <h6 class="font-weight-bold mb-1" style="font-size: 14.8px; letter-spacing: 0.2px;"><?= htmlspecialchars($banner['title']) ?></h6>
                                    <div class="announcement-body-content mb-0" style="opacity: 0.95; line-height: 1.5; font-weight: 500; font-family: inherit;"><?= $banner['content'] ?></div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none font-weight-bold" 
                                    style="color: inherit; font-size:12.5px; opacity:0.7; transition: opacity 0.2s;"
                                    onclick="dismissAnnouncement(<?= $banner['id'] ?>)"
                                    onmouseover="this.style.opacity=1"
                                    onmouseout="this.style.opacity=0.7">
                                <i class="fas fa-times fa-lg"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <script>
                    function dismissAnnouncement(annId) {
                        $.ajax({
                            url: 'anasayfa?route=duyurular',
                            type: 'POST',
                            data: {
                                action: 'dismiss_announcement',
                                announcement_id: annId,
                                csrf_token: <?= json_encode(csrf_token()) ?>
                            },
                            success: function(response) {
                                $('#announcement-banner-' + annId).slideUp(300, function() {
                                    $(this).remove();
                                    if ($('#system-announcements-container .alert').length === 0) {
                                        $('#system-announcements-container').remove();
                                    }
                                });
                            }
                        });
                    }

                    // Sayfayı yenilemeden, bitiş süresi gelen duyuruları otomatik yok etme (Real-time Checker)
                    setInterval(function() {
                        const now = Date.now();
                        document.querySelectorAll('[data-end-time]').forEach(function(el) {
                            const endTime = parseInt(el.getAttribute('data-end-time'));
                            if (endTime > 0 && now >= endTime) {
                                $(el).slideUp(500, function() {
                                    $(this).remove();
                                    if (document.querySelectorAll('#system-announcements-container .alert').length === 0) {
                                        $('#system-announcements-container').remove();
                                    }
                                });
                            }
                        });
                    }, 5000); // Her 5 saniyede bir kontrol et
                </script>
            <?php endif; ?>

            <div class="container-fluid pt-3">
                <?php 
                // If onboarding tour is going to trigger, suppress the "Login Successful" sweetalert to avoid double overlapping popups
                $willShowOnboarding = false;
                if (($current_user_role == 1) && isset($_SESSION['mesaj']) && $route === 'main' && isset($pdo) && $pdo !== null) {
                    try {
                        $stmtOnb = $pdo->prepare("SELECT onboarding_done FROM users WHERE id = ?");
                        $stmtOnb->execute([$current_user_id]);
                        if (!$stmtOnb->fetchColumn()) $willShowOnboarding = true;
                    } catch (Throwable $e) { $willShowOnboarding = true; }
                }
                
                if ($willShowOnboarding && isset($_SESSION['mesaj'])) {
                    if (strpos($_SESSION['mesaj'], 'Giriş Başarılı') !== false || strpos($_SESSION['mesaj'], 'Login Successful') !== false) {
                        unset($_SESSION['mesaj']);
                    }
                }
                ?>
                <?php if (isset($_SESSION['mesaj'])): ?>
                    <?php
                    $isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';
                    $swalHtml = str_replace(array("\r", "\n"), '', $_SESSION['mesaj']);
                    $warningToastrs = "";
                    if (!empty($_SESSION['send_warnings']) && is_array($_SESSION['send_warnings'])) {
                        if ((int)($_SESSION['role'] ?? 0) === 1) {
                            $uniqueWarnings = array_unique($_SESSION['send_warnings']);
                            $warningTitle = $isTr ? 'Sistem Uyarısı' : 'System Warning';
                            foreach ($uniqueWarnings as $w) {
                                $safeWarn = addslashes($w);
                                $warningToastrs .= "toastr.warning('{$safeWarn}', '{$warningTitle}');\n";
                            }
                        }
                        unset($_SESSION['send_warnings']);
                    }
                    ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            <?= $warningToastrs ?>
                            Swal.fire({
                                icon: "<?= (strpos($_SESSION['mesaj'], 'Hata') !== false || strpos($_SESSION['mesaj'], 'Error') !== false) ? 'error' : 'success' ?>",
                                title: "<?= (strpos($_SESSION['mesaj'], 'Hata') !== false || strpos($_SESSION['mesaj'], 'Error') !== false) ? t('error') : t('success') ?>",
                                html: "<?= addslashes($swalHtml) ?>",
                                confirmButtonText: "<?= t('ok') ?>",
                                confirmButtonColor: "var(--primary-color, #3b82f6)"
                            });
                        });
                    </script>
                    <?php unset($_SESSION['mesaj']); ?>
                <?php endif; ?>
            </div>

            <section class="content">
                <div class="container-fluid">
                    <?php
                    if (file_exists($page_path)) {
                        include $page_path;
                    } else {
                        include __DIR__ . "/../app/pages/404.php";
                    }
                    ?>
                </div>
            </section>
        </div>

        <?php if (file_exists(__DIR__ . '/../app/templates/footer.php'))
            include __DIR__ . '/../app/templates/footer.php'; ?>
    </div>

    <div class="modal fade" id="timeoutWarningModal" data-backdrop="static" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-warning">
                <div class="modal-header">
                    <h5 class="modal-title"><?= __("session_ending_title") ?></h5>
                </div>
                <div class="modal-body">
                    <p><strong id="countdownTime"></strong><?= __("session_ending_msg") ?></p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-success"
                        onclick="extendSession()"><?= __("extend_session") ?></button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global delegation for dark mode toggles
        document.addEventListener('click', function (e) {
            var toggleBtn = e.target.closest('#navDarkModeToggle, #darkModeToggle, .darkModeToggle');
            if (toggleBtn) {
                toggleDarkMode(e);
            }
        });

        (function syncInitialDarkModeIcon() {
            var isDark = document.body.classList.contains('dark-mode');
            if (typeof updateDarkModeIcons === 'function') {
                updateDarkModeIcons(isDark);
            }
        })();

        // Timeout & Active Inactivity Logic
        const SESSION_TIMEOUT = <?= SESSION_TIMEOUT_SECONDS ?>;
        const WARNING_BEFORE = 60;
        let lastActivityTime = Date.now();
        let userHasActivity = false;

        function recordActivity() {
            lastActivityTime = Date.now();
            userHasActivity = true;
        }

        // Listen for actual user interaction events
        window.addEventListener('mousemove', recordActivity);
        window.addEventListener('keypress', recordActivity);
        window.addEventListener('click', recordActivity);
        window.addEventListener('scroll', recordActivity);

        function checkSessionInactivity() {
            const timePassed = Date.now() - lastActivityTime;
            const warningLimit = (SESSION_TIMEOUT - WARNING_BEFORE) * 1000;
            const absoluteLimit = SESSION_TIMEOUT * 1000;

            if (timePassed >= absoluteLimit) {
                clearInterval(inactivityCheckInterval);
                window.location.href = 'logout?reason=inactivity';
            } else if (timePassed >= warningLimit) {
                // Show modal if not already open
                if (!$('#timeoutWarningModal').hasClass('show')) {
                    $('#timeoutWarningModal').modal('show');
                }
                const secondsLeft = Math.ceil((absoluteLimit - timePassed) / 1000);
                const m = Math.floor(secondsLeft / 60);
                const s = secondsLeft % 60;
                $('#countdownTime').text(`${m}:${s < 10 ? '0' + s : s}`);
            } else {
                // Hide modal if open
                if ($('#timeoutWarningModal').hasClass('show')) {
                    $('#timeoutWarningModal').modal('hide');
                }
            }
        }
        
        let inactivityCheckInterval = setInterval(checkSessionInactivity, 1000);

        function extendSession() {
            recordActivity();
            $('#timeoutWarningModal').modal('hide');
            $.get('<?= $base_url ?>ajax/heartbeat'); // Force a heartbeat right now
        }

        // +++++++++++++++++++++++++++++++++++++++++++++++++++++
        // NABIZ (HEARTBEAT) SİSTEMİ - GÜNCELLENDİ (AKILLI SİNYAL)
        // +++++++++++++++++++++++++++++++++++++++++++++++++++++
        // Sadece kullanıcı aktif ise sunucuya sinyal gönderir. SESSİZ/BOŞTA ise session zaman aşımına bırakılır.
        setInterval(function () {
            if (document.visibilityState === 'visible' && userHasActivity) {
                $.get('<?= $base_url ?>ajax/heartbeat');
                userHasActivity = false;
            }
            $.get('<?= $base_url ?>cron'); // Her durumda tetikle
        }, 60000); // 60000 ms = 1 Dakika

        $(function () {
            if (typeof bsCustomFileInput !== 'undefined') bsCustomFileInput.init();
            $('.select2').select2({ theme: 'bootstrap4' });
            setTimeout(function () { $(".alert-success").fadeOut(500); }, 5000);
            $.post('<?= $base_url ?>cron');

            // Nested modal scrolling fix
            $(document).on('hidden.bs.modal', '.modal', function () {
                if ($('.modal.show').length) {
                $('body').addClass('modal-open');
                }
            });
            
            // Onboarding Tour (Detaylı ve Modern)
            <?php 
            $showOnboarding = false;
            $db_onboarding_val = 'null';
            if ($current_user_role == 1 && $route === 'main' && isset($pdo) && $pdo !== null) {
                try {
                    $stmt = $pdo->prepare("SELECT onboarding_done FROM users WHERE id = ?");
                    $stmt->execute([$current_user_id]);
                    $onboarding_done = $stmt->fetchColumn();
                    $db_onboarding_val = ($onboarding_done === false) ? 'false' : (int)$onboarding_done;
                    if (!$onboarding_done) {
                        $showOnboarding = true;
                    }
                } catch (Throwable $e) {
                    $showOnboarding = true;
                    $db_onboarding_val = 'error: ' . $e->getMessage();
                }
            }
            ?>
            // Debug line removed for production
            <?php
            if ($showOnboarding): 
            ?>
            <?php
            $tourTexts = ($current_lang === 'tr') ? [
                'done' => 'Turu Bitir',
                'close' => 'Kapat',
                'next' => 'Sonraki Adım',
                'prev' => 'Önceki Adım',
                'welcome_title' => '<i class="fas fa-gift text-warning mr-2"></i> Eaprimus\'a Hoş Geldiniz!',
                'welcome_desc' => 'Sistem kurulumunu başarıyla tamamladınız. Eaprimus, BT varlık yönetimi ve destek taleplerini (ticket) tek bir noktadan yönetmenizi sağlar. Şimdi size paneli tanıtalım.',
                'sidebar_title' => '<i class="fas fa-bars text-primary mr-2"></i> Ana Menü (Sidebar)',
                'sidebar_desc' => 'Tüm modüllere bu sol menüden ulaşırsınız. Eğer daraltılmışsa, menü ikonuna tıklayarak genişletebilirsiniz.',
                'ticket_title' => '<i class="fas fa-ticket-alt text-success mr-2"></i> Biletler (Tickets)',
                'ticket_desc' => 'Kullanıcılardan gelen tüm destek (yardım) taleplerini buradan görüntüleyebilir, yanıtlayabilir ve çözüme kavuşturabilirsiniz.',
                'assets_title' => '<i class="fas fa-desktop text-primary mr-2"></i> Envanter ve Varlıklar',
                'assets_desc' => 'Şirket demirbaşlarını ve IT cihazlarını (bilgisayar, monitör vb.) buradan ekleyip kayıt altına alabilirsiniz. Yeni bir demirbaş eklemek veya var olanı personele zimmetlemek için bu alanı kullanın.',
                'licenses_title' => '<i class="fas fa-key text-warning mr-2"></i> Lisanslar ve Yazılımlar',
                'licenses_desc' => 'Sadece fiziksel cihazları değil, yazılım lisanslarınızı da (Office, Antivirüs vb.) buradan yönetip kullanıcılara atayabilirsiniz.',
                'users_title' => '<i class="fas fa-users text-info mr-2"></i> Kullanıcı Yönetimi',
                'users_desc' => 'Sistemi kullanacak olan personellerinizi ve teknisyenlerinizi buradan ekleyebilirsiniz. Hangi kullanıcının hangi menüleri göreceğini yetkilendirme ile ayarlayabilirsiniz. (Müşteriler, Müşteriler menüsünden eklenir.)',
                'settings_title' => '<i class="fas fa-cogs text-secondary mr-2"></i> Sistem Ayarları',
                'settings_desc' => 'Sistemin kalbi! E-posta (SMTP) ayarları, firma logosu, bildirim tercihleri ve aktif dizin (LDAP) gibi teknik yapılandırmaların tümü bu sayfadadır.',
                'navbar_title' => '<i class="fas fa-moon text-warning mr-2"></i> Üst Panel ve Temalar',
                'navbar_desc' => 'Profilinize erişmek, hızlı bildirimleri görmek veya tek tıkla karanlık moda (Dark Mode) geçiş yapmak için üst paneli kullanabilirsiniz.',
                'ready_title' => '<i class="fas fa-rocket text-danger mr-2"></i> Hazırsınız!',
                'ready_desc' => 'Tebrikler, temel bilgileri aldınız! İlk varlığınızı eklemeden önce sistem ayarlarını (e-posta, LDAP, logo vb.) yapılandırmanız önerilir.<br><br><a href="sistem-ayarlari" onclick="if(typeof completeOnboardingAndRedirect===\'function\'){event.preventDefault();completeOnboardingAndRedirect(\'sistem-ayarlari\');}" class="btn btn-sm btn-primary mt-2"><i class="fas fa-cogs mr-1"></i> Sistem Ayarlarına Git <i class="fas fa-chevron-right ml-1"></i></a>'
            ] : [
                'done' => 'Finish Tour',
                'close' => 'Close',
                'next' => 'Next Step',
                'prev' => 'Previous Step',
                'welcome_title' => '<i class="fas fa-gift text-warning mr-2"></i> Welcome to Eaprimus!',
                'welcome_desc' => 'You have successfully completed the system setup. Eaprimus allows you to manage IT asset management and support tickets from a single point. Let\'s introduce you to the panel.',
                'sidebar_title' => '<i class="fas fa-bars text-primary mr-2"></i> Main Menu (Sidebar)',
                'sidebar_desc' => 'You can access all modules from this left menu. If it is collapsed, you can expand it by clicking the menu icon.',
                'ticket_title' => '<i class="fas fa-ticket-alt text-success mr-2"></i> Tickets',
                'ticket_desc' => 'You can view, reply to, and resolve all support requests coming from users here.',
                'assets_title' => '<i class="fas fa-desktop text-primary mr-2"></i> Inventory and Assets',
                'assets_desc' => 'You can add and record company fixtures and IT devices (computers, monitors, etc.) here. Use this area to add a new fixture or assign an existing one to personnel.',
                'licenses_title' => '<i class="fas fa-key text-warning mr-2"></i> Licenses and Software',
                'licenses_desc' => 'You can manage and assign not only physical devices but also software licenses (Office, Antivirus, etc.) to users from here.',
                'users_title' => '<i class="fas fa-users text-info mr-2"></i> User Management',
                'users_desc' => 'You can add your personnel and technicians who will use the system here. You can configure which user will see which menus through authorization. (Customers are added from the Customers menu.)',
                'settings_title' => '<i class="fas fa-cogs text-secondary mr-2"></i> System Settings',
                'settings_desc' => 'The heart of the system! All technical configurations such as email (SMTP) settings, company logo, notification preferences, and active directory (LDAP) are on this page.',
                'navbar_title' => '<i class="fas fa-moon text-warning mr-2"></i> Top Panel and Themes',
                'navbar_desc' => 'You can use the top panel to access your profile, view quick notifications, or switch to Dark Mode with a single click.',
                'ready_title' => '<i class="fas fa-rocket text-danger mr-2"></i> You\'re Ready!',
                'ready_desc' => 'Congratulations, you got the basics! Before adding your first asset, it is recommended to configure your system settings (email, LDAP, logo, etc.).<br><br><a href="sistem-ayarlari" onclick="if(typeof completeOnboardingAndRedirect===\'function\'){event.preventDefault();completeOnboardingAndRedirect(\'sistem-ayarlari\');}" class="btn btn-sm btn-primary mt-2"><i class="fas fa-cogs mr-1"></i> Go to System Settings <i class="fas fa-chevron-right ml-1"></i></a>'
            ];
            ?>
            console.log("Onboarding triggered. Role is 1, Cookie v6 is not set.");
            function completeOnboardingAndRedirect(url) {
                document.cookie = "onboarding_done_v8=1; max-age=31536000; path=/";
                let completedCalls = 0;
                const checkRedirect = () => {
                    completedCalls++;
                    if (completedCalls >= 2) {
                        window.location.href = url;
                    }
                };
                $.post('<?= $base_url ?>ajax/heartbeat', { 
                    action: 'complete_onboarding',
                    csrf_token: '<?= csrf_token() ?>'
                }).always(checkRedirect);
                $.post(window.location.href, { 
                    action: 'complete_onboarding',
                    csrf_token: '<?= csrf_token() ?>'
                }).always(checkRedirect);
            }
            function startTour() {
                const t = <?= json_encode($tourTexts) ?>;
                const driver = window.driver.js.driver({
                    showProgress: true,
                    animate: true,
                    opacity: 0.75,
                    popoverClass: 'modern-driver-theme',
                    doneBtnText: t.done,
                    closeBtnText: t.close,
                    nextBtnText: t.next,
                    prevBtnText: t.prev,
                    steps: [
                        { popover: { title: t.welcome_title, description: t.welcome_desc } },
                        { element: '.main-sidebar', popover: { title: t.sidebar_title, description: t.sidebar_desc, side: 'right' } },
                        { element: 'a[href="anasayfa?panel=ticket&status=all"]', popover: { title: t.ticket_title, description: t.ticket_desc, side: 'right' } },
                        { element: 'a[href="varliklar?view=assets"]', popover: { title: t.assets_title, description: t.assets_desc, side: 'right' } },
                        { element: 'a[href="varliklar?view=licenses"]', popover: { title: t.licenses_title, description: t.licenses_desc, side: 'right' } },
                        { element: 'a[href="kullanici-listele"]', popover: { title: t.users_title, description: t.users_desc, side: 'right' } },
                        { element: 'a[href="sistem-ayarlari"]', popover: { title: t.settings_title, description: t.settings_desc, side: 'right' } },
                        { element: '.navbar', popover: { title: t.navbar_title, description: t.navbar_desc, side: 'bottom' } },
                        { popover: { title: t.ready_title, description: t.ready_desc } }
                    ],
                    onHighlightStarted: (element) => {
                        if (element && typeof element.closest === 'function') {
                            let treeview = element.closest('.nav-treeview');
                            if (treeview) {
                                let parentItem = treeview.closest('.nav-item');
                                if (parentItem && !parentItem.classList.contains('menu-open')) {
                                    parentItem.classList.add('menu-open');
                                    $(treeview).slideDown(300);
                                }
                            }
                        }
                    },
                    onDestroyStarted: () => {
                        document.cookie = "onboarding_done_v8=1; max-age=31536000; path=/";
                        try {
                            $.post('<?= $base_url ?>ajax/heartbeat', { 
                                action: 'complete_onboarding',
                                csrf_token: '<?= csrf_token() ?>'
                            });
                            $.post(window.location.href, { 
                                action: 'complete_onboarding',
                                csrf_token: '<?= csrf_token() ?>'
                            });
                        } catch (e) {
                            console.error(e);
                        }
                        if (typeof confetti === 'function') {
                            confetti({
                                particleCount: 150,
                                spread: 80,
                                origin: { y: 0.6 }
                            });
                        }
                        if (driver.hasNextStep()) {
                            driver.destroy();
                        } else {
                            driver.destroy();
                        }
                    }
                });
                
                // Eğer sol menü tamamen daraltılmışsa (ikon modundaysa) genişlet, böylece tur düzgün çalışsın
                if ($('body').hasClass('sidebar-collapse')) {
                    $('[data-widget="pushmenu"]').PushMenu('expand');
                }
                
                driver.drive();
            }

            let driverAttempts = 0;
            const driverInterval = setInterval(() => {
                driverAttempts++;
                if (typeof window.driver !== 'undefined' && typeof window.driver.js !== 'undefined') {
                    clearInterval(driverInterval);
                    startTour();
                } else if (driverAttempts > 50) {
                    clearInterval(driverInterval);
                    console.error("Driver.js not loaded after 5 seconds!");
                }
            }, 100);
            <?php endif; ?>
            // Auto-scroll sidebar to the active menu item on page load
            setTimeout(function() {
                var activeLink = $('.sidebar .nav-link.active');
                if (activeLink.length) {
                    var osInstance = $('.sidebar').overlayScrollbars();
                    if (osInstance) {
                        osInstance.scroll({ el: activeLink[0], scroll: 'ifneeded' }, 500);
                    } else {
                        var container = $('.sidebar');
                        container.animate({
                            scrollTop: activeLink.offset().top - container.offset().top + container.scrollTop() - 100
                        }, 500);
                    }
                }
            }, 600);
            
            // Prevent collapsed sidebar inline menu expansion on click
            $('.nav-sidebar').on('click', '.nav-item > .nav-link', function(e) {
                if ($('body').hasClass('sidebar-collapse') && $(this).next('.nav-treeview').length) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });

            // Hover persistence and smart positioning for collapsed sidebar floating menus
            var sidebarHoverTimeout;
            $('.nav-sidebar').on('mouseenter', '> .nav-item', function() {
                clearTimeout(sidebarHoverTimeout);
                // Remove hover-active from all other top-level items so they don't get stuck open
                $(this).siblings().removeClass('hover-active').find('.nav-link p, .nav-treeview').css('transform', '');
                $(this).addClass('hover-active');

                // Smart positioning: Shift menu up if it goes off the bottom of the screen
                if ($('body').hasClass('sidebar-collapse')) {
                    var $item = $(this);
                    setTimeout(function() {
                        var $treeview = $item.children('.nav-treeview');
                        var $p = $item.children('.nav-link').children('p');
                        
                        // Temporarily reset transform to get true native position
                        var oldTreeviewTransform = $treeview.css('transform');
                        var oldPTransform = $p.css('transform');
                        $treeview.css('transform', 'none');
                        $p.css('transform', 'none');

                        if ($treeview.length > 0) {
                            var rect = $treeview[0].getBoundingClientRect();
                            var windowHeight = $(window).height();
                            if (rect.bottom > windowHeight) {
                                var overflow = rect.bottom - windowHeight + 15; // 15px padding from bottom
                                $treeview.css('transform', 'translateY(-' + overflow + 'px)');
                                $p.css('transform', 'translateY(-' + overflow + 'px)');
                            } else {
                                $treeview.css('transform', '');
                                $p.css('transform', '');
                            }
                        }
                    }, 10);
                }
            }).on('mouseleave', '> .nav-item', function() {
                var $item = $(this);
                sidebarHoverTimeout = setTimeout(function() {
                    if (!$item.is(':hover')) {
                        $item.removeClass('hover-active');
                        $item.find('.nav-link p, .nav-treeview').css('transform', '');
                    }
                }, 400); // 400ms timeout
            });

            // Auto-scroll sidebar when a menu item is expanded to make submenu items visible
            $(document).on('click', '.nav-sidebar .nav-item > .nav-link', function() {
                var parent = $(this).parent('.nav-item');
                setTimeout(function() {
                    if (parent.hasClass('menu-open') || parent.hasClass('menu-is-opening')) {
                        var lastItem = parent.find('.nav-treeview .nav-item:last-child');
                        if (lastItem.length) {
                            var osInstance = $('.sidebar').overlayScrollbars();
                            if (osInstance) {
                                osInstance.scroll({ el: lastItem[0], scroll: 'ifneeded' }, 300);
                            }
                        }
                    }
                }, 350);
            });
            
            // Listen to pushmenu toggling and persist state in localStorage
            $(document).on('collapsed.lte.pushmenu', function() {
                localStorage.setItem('sidebar_state', 'collapsed');
            });
            $(document).on('shown.lte.pushmenu', function() {
                localStorage.setItem('sidebar_state', 'expanded');
            });

            // Initialize Realtime Engine
            <?php
            $currentTicketId = 0;
            if (isset($ticketId) && (int)$ticketId > 0) {
                $currentTicketId = (int)$ticketId;
            } elseif (isset($ticket['id']) && (int)$ticket['id'] > 0) {
                $currentTicketId = (int)$ticket['id'];
            } elseif (isset($_GET['ticket_id']) && (int)$_GET['ticket_id'] > 0) {
                $currentTicketId = (int)$_GET['ticket_id'];
            } elseif (isset($_GET['id']) && (int)$_GET['id'] > 0) {
                $currentTicketId = (int)$_GET['id'];
            }
            $currentMaxReplyId = 0;
            if ($currentTicketId > 0 && isset($pdo)) {
                try {
                    $stMax = $pdo->prepare("SELECT MAX(id) FROM ticket_replies WHERE ticket_id = ?");
                    $stMax->execute([$currentTicketId]);
                    $currentMaxReplyId = intval($stMax->fetchColumn() ?: 0);
                } catch (Exception $e) {}
            }
            $maxTicketId = 0;
            $maxGlobalReplyId = 0;
            $maxRatingId = 0;
            // Try multiple times to get a valid PDO connection
            $pdoInit = (isset($pdo) && $pdo !== null) ? $pdo : null;
            if (!$pdoInit) { try { $pdoInit = db(); } catch (Throwable $e) {} }
            if ($pdoInit) {
                try {
                    $maxTicketId = intval($pdoInit->query("SELECT MAX(id) FROM tickets")->fetchColumn() ?: 0);
                } catch (Throwable $e) {}
                try {
                    $maxGlobalReplyId = intval($pdoInit->query("SELECT MAX(id) FROM ticket_replies")->fetchColumn() ?: 0);
                } catch (Throwable $e) {}
                try {
                    $maxRatingId = intval($pdoInit->query("SELECT MAX(id) FROM ticket_ratings")->fetchColumn() ?: 0);
                } catch (Throwable $e) {}
            }
            ?>
            var activeTicketId = <?= $currentTicketId ?>;
            var maxReplyId = <?= $currentMaxReplyId ?>;
            var maxTicketId = <?= $maxTicketId ?>;
            var maxGlobalReplyId = <?= $maxGlobalReplyId ?>;
            var maxRatingId = <?= $maxRatingId ?>;
            if (window.EaprimusRealtime) {
                window.EaprimusRealtime.init(activeTicketId, maxReplyId, maxTicketId, maxGlobalReplyId, maxRatingId);
            }

        });
    </script>
</body>

</html>
<?php ob_end_flush(); ?>
