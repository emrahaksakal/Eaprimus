<?php
// Kullanıcının tam ismini al ve sadece ilk ismi kullan
$tam_isim = !empty($current_user_fullname) ? $current_user_fullname : 'Kullanıcı';
$isim_parcalari = explode(' ', trim($tam_isim));
$ilk_isim = $isim_parcalari[0];
$current_lang = $_SESSION['lang'] ?? 'tr';
$isTr = ($current_lang === 'tr');
$current_panel = htmlspecialchars(trim($_GET['panel'] ?? (in_array(($route ?? ''), ['biletler', 'bilet-detay', 'musteriler', 'organizasyonlar', 'takimlar', 'kuyruklar', 'sla-dashboard']) ? 'ticket' : 'inventory')));

// Kullanıcının profil fotoğrafını al
$_navbar_avatar_src = $_SESSION['user_avatar'] ?? null;
if ($_navbar_avatar_src === null && isset($current_user_id)) {
    try {
        $stmtAvatar = $pdo->prepare("SELECT profil_fotosu FROM users WHERE id = ? LIMIT 1");
        $stmtAvatar->execute([$current_user_id]);
        $avatarRow = $stmtAvatar->fetch(PDO::FETCH_ASSOC);
        if ($avatarRow && !empty($avatarRow['profil_fotosu'])) {
            $pf = $avatarRow['profil_fotosu'];
            if (filter_var($pf, FILTER_VALIDATE_URL)) {
                $_navbar_avatar_src = htmlspecialchars($pf);
            } elseif (strpos($pf, 'dist/img/avatars/') !== false) {
                $cleanPath = preg_replace('/^.*(dist\/img\/avatars\/[a-zA-Z0-9_\-\.]+)/', '$1', $pf);
                $_navbar_avatar_src = htmlspecialchars($cleanPath);
            } else {
                $pfPath = __DIR__ . '/../../public/uploads/profil/' . $pf;
                if (is_file($pfPath)) {
                    $_navbar_avatar_src = 'uploads/profil/' . htmlspecialchars($pf) . '?v=' . filemtime($pfPath);
                }
            }
        }
        $_SESSION['user_avatar'] = $_navbar_avatar_src ?? '';
    } catch (Exception $e) { /* sesizce geç */ }
}

// Critical Stock Logic
$critical_items = [];
if ($current_panel === 'inventory' || true) { // Also show in ticket panel for visibility
    try {
        // Consumables
        $sql = "SELECT 'consumables' as view, id, name, total_qty, 
                (total_qty - (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'checkin' THEN -quantity ELSE quantity END), 0) FROM asset_consumable_checkouts WHERE consumable_id = ac.id AND transaction_type IN ('consume', 'checkin'))) as remaining,
                min_qty
                FROM asset_consumables ac
                WHERE deleted_at IS NULL AND min_qty > 0
                HAVING remaining <= min_qty";
        $stmt = $pdo->query($sql);
        if ($stmt) while($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $critical_items[] = $row; }

        $sql = "SELECT 'accessories' as view, id, name, total_qty,
                (total_qty - (SELECT COALESCE(SUM(quantity), 0) FROM asset_accessory_checkouts WHERE accessory_id = aa.id AND (transaction_type = 'assign' OR transaction_type IS NULL))) as remaining,
                min_qty
                FROM asset_accessories aa
                WHERE deleted_at IS NULL AND min_qty > 0
                HAVING remaining <= min_qty";
        $stmt = $pdo->query($sql);
        if ($stmt) while($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $critical_items[] = $row; }
    } catch(Exception $e) {}
}
$critical_stock_count = count($critical_items);
?>

<style>
    /* Hover ile dropdown açma */
    .nav-item.dropdown:hover .dropdown-menu {
        display: block;
        margin-top: 0;
    }

    /* Kullanıcı ismi mobilde gizlenmesin */
    .user-welcome-text {
        display: flex !important;
        align-items: center;
        color: #333;
        cursor: pointer;
    }

    /* Çıkış animasyonu */
    @keyframes exitWalk {
        0% {
            transform: translateX(0);
            opacity: 1;
        }

        50% {
            transform: translateX(6px);
            opacity: 0.7;
        }

        100% {
            transform: translateX(0);
            opacity: 1;
        }
    }

    .animasyonlu-cikis {
        display: inline-block;
        animation: exitWalk 1.2s infinite ease-in-out;
        color: #dc3545;
        font-size: 1.2rem;
    }

    @keyframes signature-pulse {
        0% { transform: scale(1); opacity: 1; text-shadow: 0 0 3px rgba(239, 68, 68, 0.4); }
        50% { transform: scale(1.2); opacity: 0.8; text-shadow: 0 0 10px rgba(239, 68, 68, 0.8); }
        100% { transform: scale(1); opacity: 1; text-shadow: 0 0 3px rgba(239, 68, 68, 0.4); }
    }
    .pulse-signature-icon {
        display: inline-block;
        animation: signature-pulse 1.5s infinite ease-in-out;
        color: #ef4444 !important; /* Beautiful Glowing Red/Neon! */
        font-size: 1.15rem;
    }

    @keyframes ticket-pulse {
        0% { transform: scale(1); opacity: 1; text-shadow: 0 0 3px rgba(16, 185, 129, 0.4); }
        50% { transform: scale(1.2); opacity: 0.8; text-shadow: 0 0 10px rgba(16, 185, 129, 0.8); }
        100% { transform: scale(1); opacity: 1; text-shadow: 0 0 3px rgba(16, 185, 129, 0.4); }
    }
    .pulse-ticket-icon {
        display: inline-block;
        animation: ticket-pulse 1.5s infinite ease-in-out;
        color: #10b981 !important; /* Beautiful Glowing Emerald Green/Neon! */
        font-size: 1.15rem;
    }
    .normal-ticket-icon {
        color: #64748b !important;
        font-size: 1.15rem;
        transition: color 0.2s ease;
    }
    body.dark-mode .normal-ticket-icon {
        color: #94a3b8 !important;
    }
    
    .ticket-active-glow {
        background: rgba(16, 185, 129, 0.15) !important;
        color: #10b981 !important;
    }
    
    /* Radar Echo Wave effect */
    .ticket-active-glow::after {
        content: '';
        position: absolute;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        border: 2px solid #10b981;
        left: 0;
        top: 0;
        opacity: 0;
        box-sizing: border-box;
        animation: radar-echo 1.5s infinite ease-out;
    }

    @keyframes radar-echo {
        0% { transform: scale(1); opacity: 1; }
        100% { transform: scale(1.6); opacity: 0; }
    }

    /* Dropdown sağa hizalama */
    .dropdown-menu-right {
        right: 0;
        left: auto;
    }

    /* --- DARK MODE UYUMLULUĞU --- */
    /* Koyu mod aktifken navbar arka planını ve sınırlarını ayarla */
    body.dark-mode .main-header {
        background-color: #0b0f19 !important;
        border-bottom: 1px solid #1e293b !important;
    }

    /* Koyu modda navbar içindeki ikonlar ve yazılar beyaz olsun */
    body.dark-mode .navbar-light .navbar-nav .nav-link {
        color: #e2e8f0 !important;
    }

    /* Koyu modda kullanıcı karşılama metni */
    body.dark-mode .user-welcome-text {
        color: #ffffff !important;
    }

    body.dark-mode .user-welcome-text .text-muted {
        color: #ced4da !important;
        /* Biraz daha açık gri */
    }

    /* Koyu modda dropdown menü */
    body.dark-mode .dropdown-menu {
        background-color: #343a40;
        border-color: #56606a;
    }

    body.dark-mode .dropdown-item {
        color: #fff;
    }

    body.dark-mode .dropdown-item:hover {
        background-color: #3f474e;
    }

    body.dark-mode .dropdown-divider {
        border-top-color: #56606a;
    }

    /* Autocomplete Styling */
    .ui-autocomplete {
        z-index: 10000 !important;
        border-radius: 12px !important;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1) !important;
        border: 1px solid #e2e8f0 !important;
        padding: 8px 0 !important;
    }
    .ui-autocomplete-category {
        font-weight: 700;
        padding: 8px 12px 4px 12px;
        background: #f8fafc;
        color: #64748b;
        font-size: 11px;
        text-transform: uppercase;
    }

    /* --- SLEEK SEARCH BAR --- */
    .nav-search-bar {
        border-radius: 20px !important;
        background-color: #f1f5f9;
        border: 1px solid transparent;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        padding: 0 10px;
    }
    .nav-search-bar:focus-within {
        background-color: #fff;
        border: 1px solid #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    .nav-search-input {
        background: transparent !important;
        color: #334155 !important;
        box-shadow: none !important;
    }
    body.dark-mode .nav-search-bar {
        background-color: #2d333b;
        border-color: #444c56;
    }
    body.dark-mode .nav-search-bar:focus-within {
        background-color: #22272e;
        border-color: #60a5fa;
        box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
    }
    body.dark-mode .nav-search-input {
        color: #e2e8f0 !important;
    }
    body.dark-mode .nav-search-input::placeholder {
        color: #94a3b8 !important;
    }

    /* --- SLEEK CREATE ICON BUTTON --- */
    .btn-create-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white !important;
        box-shadow: 0 2px 6px rgba(37, 99, 235, 0.3);
        transition: all 0.3s ease;
    }
    .nav-link.create-toggle {
        padding: 0 !important;
        margin-left: 15px;
    }
    .nav-link.create-toggle:hover .btn-create-icon {
        transform: translateY(-2px) scale(1.05);
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.4);
    }
    .nav-link.create-toggle::after {
        display: none; /* Hide default dropdown arrow */
    }
    body.dark-mode .btn-create-icon {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.5);
    }

    /* --- ICON NAV BUTTONS (right side) --- */
    .nav-icon-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: transparent;
        color: #64748b;
        transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
        position: relative;
    }
    .nav-icon-btn:hover {
        background: #f1f5f9;
        color: #1e293b;
        transform: translateY(-1px);
    }
    .nav-link.nav-icon-link {
        padding: 0 4px !important;
        margin: 0 2px;
    }
    .nav-link.nav-icon-link .nav-icon-btn i {
        font-size: 1rem;
    }
    body.dark-mode .nav-icon-btn {
        color: #94a3b8;
    }
    body.dark-mode .nav-icon-btn:hover {
        background: #2d333b;
        color: #e2e8f0;
    }

    /* Dark mode toggle sun icon */
    body.dark-mode #navDarkModeIcon {
        color: #fbbf24;
    }

    /* --- USER WELCOME AREA --- */
    .user-welcome-text {
        display: flex !important;
        align-items: center;
        padding: 0 8px !important;
        gap: 8px;
        cursor: pointer;
    }
    .user-avatar-circle {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
        flex-shrink: 0;
        box-shadow: 0 2px 6px rgba(59, 130, 246, 0.3);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        overflow: hidden; /* fotoğraf taşmasını engelle */
    }
    .user-avatar-circle img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
        display: block;
    }
    .user-welcome-text:hover .user-avatar-circle {
        transform: scale(1.05);
        box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);
    }
    body.dark-mode .user-avatar-circle {
        background: linear-gradient(135deg, #2563eb, #7c3aed);
        box-shadow: 0 2px 6px rgba(0,0,0,0.5);
    }
    .user-info-text .user-greeting {
        font-size: 0.7rem;
        color: #94a3b8;
        line-height: 1;
    }
    .user-info-text .user-name {
        font-size: 0.9rem;
        font-weight: 700;
        color: #1e293b;
        line-height: 1.2;
    }
    body.dark-mode .user-info-text .user-name {
        color: #e2e8f0 !important;
    }
    body.dark-mode .user-info-text .user-greeting {
        color: #64748b !important;
    }

    /* Dropdown styling polish */
    .navbar-dropdown-menu {
        border-radius: 14px !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 10px 30px rgba(0,0,0,0.12) !important;
        padding: 6px !important;
        min-width: 210px;
    }
    .navbar-dropdown-menu .dropdown-item {
        border-radius: 8px;
        margin: 1px 0;
        font-size: 0.875rem;
        padding: 9px 12px;
        transition: background 0.15s ease;
    }
    .navbar-dropdown-menu .dropdown-item:hover {
        background: #f1f5f9;
    }
    body.dark-mode .navbar-dropdown-menu {
        background-color: #22272e !important;
        border-color: #444c56 !important;
        box-shadow: 0 10px 30px rgba(0,0,0,0.5) !important;
    }
    body.dark-mode .navbar-dropdown-menu .dropdown-item {
        color: #cdd9e5 !important;
    }
    body.dark-mode .navbar-dropdown-menu .dropdown-item:hover {
        background: #2d333b !important;
        color: #e2e8f0 !important;
    }
    body.dark-mode .navbar-dropdown-menu .dropdown-divider {
        border-top-color: #444c56 !important;
    }
</style>

<nav class="main-header navbar navbar-expand navbar-white navbar-light">

    <ul class="navbar-nav">
        <li class="nav-item">
            <a class="nav-link" data-widget="pushmenu" href="#" role="button" onclick="toggleSidebarManual(event)">
                <i class="fas fa-bars"></i>
            </a>
        </li>
        <li class="nav-item d-md-none d-flex align-items-center ml-2">
            <a href="anasayfa?panel=<?= $current_panel ?>" class="d-flex align-items-center">
                <?php if (isset($_logo_path) && file_exists(__DIR__ . '/../../' . $_logo_path)): 
                    $logo_v = filemtime(__DIR__ . '/../../' . $_logo_path); ?>
                    <img src="<?= htmlspecialchars($_logo_path) ?>?v=<?= $logo_v ?>" alt="Logo" style="max-height: 30px; width: auto;">
                <?php else: ?>
                    <span class="font-weight-bold text-dark"><?= htmlspecialchars($_company_name ?? 'Eaprimus') ?></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>

    <!-- Global Arama ve Yeni Oluştur (User Request) -->
    <?php if (($route ?? '') !== 'takimlar'): ?>
    <form action="varliklar" method="GET" class="form-inline ml-3 d-none d-md-flex">
        <input type="hidden" name="view" value="assets">
        <div class="nav-search-bar" style="height: 38px;">
            <i class="fas fa-search text-muted ml-1"></i>
            <input class="form-control border-0 nav-search-input" type="search" name="search" placeholder="<?= $isTr ? 'Arama yap...' : 'Search...' ?>" style="width: 220px; height: 100%;">
        </div>
    </form>
    <?php endif; ?>

    <?php if (($route ?? '') !== 'takimlar'): ?>
    <ul class="navbar-nav d-none d-md-flex">
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle create-toggle" data-toggle="dropdown" href="#" title="<?= ($current_lang ?? 'tr') == 'tr' ? 'Yeni Oluştur' : 'Create New' ?>">
                <div class="btn-create-icon">
                    <i class="fas fa-plus"></i>
                </div>
            </a>
            <div class="dropdown-menu dropdown-menu-left shadow-lg border-0 mt-2" style="min-width: 200px; border-radius: 12px;">
                <span class="dropdown-item dropdown-header font-weight-bold text-muted text-left px-3 pt-2 pb-1"><?= ($current_lang ?? 'tr') == 'tr' ? 'Hızlı İşlemler' : 'Quick Actions' ?></span>
                <a href="varliklar?view=assets&action=new" class="dropdown-item py-2"><i class="fas fa-laptop mr-2 text-primary" style="width:20px;"></i> <?= __("fixed_assets") ?></a>
                <a href="varliklar?view=licenses&action=new" class="dropdown-item py-2"><i class="fas fa-id-card mr-2 text-warning" style="width:20px;"></i> <?= __("licenses") ?></a>
                <a href="varliklar?view=accessories&action=new" class="dropdown-item py-2"><i class="fas fa-keyboard mr-2 text-info" style="width:20px;"></i> <?= __("accessories") ?></a>
                <a href="varliklar?view=consumables&action=new" class="dropdown-item py-2"><i class="fas fa-tint mr-2 text-danger" style="width:20px;"></i> <?= __("consumables") ?></a>
                <a href="varliklar?view=components&action=new" class="dropdown-item py-2"><i class="fas fa-microchip mr-2 text-success" style="width:20px;"></i> <?= __("components") ?></a>
                <div class="dropdown-divider"></div>
                <a href="kullanici-ekle" class="dropdown-item py-2 pb-3"><i class="fas fa-user-plus mr-2 text-secondary" style="width:20px;"></i> <?= __("user") ?></a>
            </div>
        </li>
    </ul>
    <?php endif; ?>

    <ul class="navbar-nav ml-auto align-items-center">
        <!-- Critical Stock Notifications -->
        <?php if ($critical_stock_count > 0): ?>
        <li class="nav-item dropdown">
            <a class="nav-link nav-icon-link" data-toggle="dropdown" href="#" role="button" title="<?= $isTr ? 'Kritik Stok Uyarıları' : 'Critical Stock Alerts' ?>">
                <div class="nav-icon-btn" style="background: rgba(239,68,68,0.1);">
                    <i class="fas fa-exclamation-triangle text-danger"></i>
                    <span class="badge badge-danger navbar-badge"><?= $critical_stock_count ?></span>
                </div>
            </a>
            <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right border-0 shadow-lg" style="border-radius:12px;">
                <span class="dropdown-item dropdown-header text-danger font-weight-bold p-3">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= $isTr ? 'Kritik Stok Uyarısı' : 'Critical Stock Alert' ?>
                </span>
                <div class="dropdown-divider"></div>
                <div style="max-height: 300px; overflow-y: auto;">
                    <?php foreach($critical_items as $ci): ?>
                    <a href="varliklar?view=<?= $ci['view'] ?>&highlight_id=<?= $ci['id'] ?>#item-<?= $ci['id'] ?>" class="dropdown-item py-2">
                        <div class="d-flex align-items-center">
                            <div class="mr-3 bg-light rounded-circle d-flex align-items-center justify-content-center" style="width:30px; height:30px;">
                                <i class="fas <?= $ci['view'] == 'consumables' ? 'fa-tint text-danger' : 'fa-keyboard text-info' ?> fa-xs"></i>
                            </div>
                            <div class="small">
                                <p class="mb-0 font-weight-bold text-dark text-truncate" style="max-width: 180px;"><?= htmlspecialchars($ci['name']) ?></p>
                                <p class="mb-0 text-muted" style="font-size:10px;">
                                    <?= $isTr ? 'Kalan:' : 'Remaining:' ?> <span class="text-danger font-weight-600"><?= $ci['remaining'] ?></span> / 
                                    <?= $isTr ? 'Toplam:' : 'Total:' ?> <?= $ci['total_qty'] ?> / 
                                    <?= $isTr ? 'Min:' : 'Min:' ?> <?= $ci['min_qty'] ?>
                                </p>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <div class="dropdown-divider"></div>
                <a href="varliklar?view=consumables" class="dropdown-item dropdown-footer py-2 small font-weight-bold text-primary"><?= $isTr ? 'Tümünü Gör' : 'View All' ?></a>
            </div>
        </li>
        <?php endif; ?>
        
        <?php
        $c_pending_sigs_nav = 0;
        try {
            if (isset($current_user_id)) {
                 $isAdminBadgeNav = in_array($current_user_role ?? 3, [1, 3]);
                 $badgeWhereNav = "status = 'pending_user' AND user_id = " . (int)$current_user_id;
                 if ($isAdminBadgeNav) {
                     $admin_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN (1, 3)")->fetchColumn();
                     if ($admin_count > 1) {
                         $badgeWhereNav = "(status = 'pending_user' AND user_id = " . (int)$current_user_id . ") OR (status = 'pending_admin' AND user_id != " . (int)$current_user_id . ")";
                     } else {
                         $badgeWhereNav = "(status = 'pending_user' AND user_id = " . (int)$current_user_id . ") OR (status = 'pending_admin')";
                     }
                 }
                 $c_pending_sigs_nav = $pdo->query("SELECT COUNT(*) FROM asset_signatures WHERE $badgeWhereNav")->fetchColumn();
            }
        } catch (Exception $e) {}
        ?>
        <?php if ($c_pending_sigs_nav > 0): ?>
        <li class="nav-item">
            <a class="nav-link nav-icon-link" href="varliklar?view=signatures" title="<?= $isTr ? 'Zimmet Onaylarım' : 'My Approvals' ?>">
                <div class="nav-icon-btn">
                    <i class="fas fa-file-signature pulse-signature-icon"></i>
                    <span class="badge badge-danger navbar-badge"><?= $c_pending_sigs_nav ?></span>
                </div>
            </a>
        </li>
        <?php endif; ?>

        <?php
        $c_new_tickets_nav = 0;
        try {
            if (isset($current_user_id) && isset($current_user_role)) {
                $tenantCond = "1=1";
                if (function_exists('tenantWhere')) {
                    $tenantCond = tenantWhere();
                }
                $whereCountNav = "";
                static $hasClosedByNav = null;
                if ($hasClosedByNav === null) {
                    try {
                        $chkC = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'closed_by'");
                        $hasClosedByNav = ($chkC && $chkC->fetch()) ? true : false;
                    } catch (Throwable $e) { $hasClosedByNav = false; }
                }
                $closedByColNav = $hasClosedByNav ? "t.closed_by" : "NULL";
                $statusCond = "(
                    t.status NOT IN ('resolved','closed')
                    OR (t.status IN ('resolved','closed') AND (t.unread_replies_count > 0 OR EXISTS (SELECT 1 FROM ticket_ratings tr WHERE tr.ticket_id = t.id)))
                )";

                if ($current_user_role == 2) {
                    // Müşteri: Kendi oluşturduğu veya müşteri olduğu biletler
                    $whereCountNav = "AND (t.creator_id = " . (int)$current_user_id . " OR t.customer_id = " . (int)$current_user_id . ")";
                } elseif ($current_user_role == 1) {
                    // Admin: TÜM biletleri görür - filtre yok
                    $whereCountNav = "";
                } else {
                    // Teknik Destek (Role 3): Kendine atanan, kendi açtığı VEYA takımının kuyruğundaki atanmamış biletler
                    static $col_nav_r3 = null;
                    if ($col_nav_r3 === null) {
                        $stmtCol = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'personnel_id'");
                        $col_nav_r3 = $stmtCol->fetch() ? 'personnel_id' : 'assigned_to';
                    }
                    $whereCountNav = "AND (
                        t.$col_nav_r3 = " . (int)$current_user_id . "
                        OR t.creator_id = " . (int)$current_user_id . "
                        OR ( (t.$col_nav_r3 = 0 OR t.$col_nav_r3 IS NULL) AND t.queue_id IN (SELECT q.id FROM queues q JOIN teams_users tu ON q.team_id = tu.team_id WHERE tu.user_id = " . (int)$current_user_id . ") )
                    )";
                }
                
                $all_candidate_tickets = $pdo->query("SELECT t.id, t.ticket_no, t.title, t.status, t.agent_read, t.unread_replies_count, (SELECT MAX(r.id) FROM ticket_replies r WHERE r.ticket_id = t.id AND r.reply_type != 'system') as max_reply_id, (SELECT COUNT(r.id) FROM ticket_replies r WHERE r.ticket_id = t.id AND r.reply_type != 'system') as reply_count FROM tickets t WHERE $statusCond AND $tenantCond $whereCountNav ORDER BY t.update_date DESC, t.id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);

                $new_tickets_list = [];
                foreach ($all_candidate_tickets as $nt) {
                    $ratedVal = null;
                    try {
                        $stmtRatedCheck = $pdo->prepare("SELECT rating FROM ticket_ratings WHERE ticket_id = ?");
                        $stmtRatedCheck->execute([$nt['id']]);
                        $ratedVal = $stmtRatedCheck->fetchColumn();
                    } catch (Exception $e) {}

                    $token = ($nt['max_reply_id'] ? (int)$nt['max_reply_id'] : 'new') . '_' . ($nt['status'] ?? 'open') . '_' . ($ratedVal ?: '0');
                    $hasReadInDB = ((int)($nt['agent_read'] ?? 0) === 1 && (int)($nt['unread_replies_count'] ?? 0) === 0);
                    $hasReadInSessionCookie = (
                        (isset($_SESSION['read_ticket_replies'][$nt['id']]) && $_SESSION['read_ticket_replies'][$nt['id']] === $token)
                        || (isset($_COOKIE['read_ticket_reply_' . $current_user_id . '_' . $nt['id']]) && $_COOKIE['read_ticket_reply_' . $current_user_id . '_' . $nt['id']] === $token)
                    );
                    $isRead = ($hasReadInDB && $hasReadInSessionCookie && empty($ratedVal));
                    if (!$isRead) {
                        $nt['rated_val'] = $ratedVal;
                        $new_tickets_list[] = $nt;
                    }
                }
                $c_new_tickets_nav = count($new_tickets_list);
                $new_tickets_list = array_slice($new_tickets_list, 0, 5);
            }
        } catch (Exception $e) {}
        ?>
        <li class="nav-item dropdown" id="nav-ticket-item">
            <a class="nav-link nav-icon-link" data-toggle="dropdown" href="#" role="button" title="<?= $isTr ? 'Bekleyen Biletler' : 'Pending Tickets' ?>">
                <div class="nav-icon-btn <?= $c_new_tickets_nav > 0 ? 'ticket-active-glow' : '' ?>" id="nav-ticket-btn">
                    <i class="fas fa-ticket-alt <?= $c_new_tickets_nav > 0 ? 'pulse-ticket-icon' : 'normal-ticket-icon' ?>" id="nav-ticket-icon-element"></i>
                    <span class="badge badge-success navbar-badge <?= $c_new_tickets_nav > 0 ? '' : 'd-none' ?>" id="unread-tickets-badge"><?= $c_new_tickets_nav ?></span>
                </div>
            </a>
            <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right border-0 shadow-lg" style="border-radius:12px; width: 320px; padding: 0;">
                <span class="dropdown-item dropdown-header text-success font-weight-bold p-3 text-left">
                    <i class="fas fa-ticket-alt mr-2"></i><?= $isTr ? 'Bekleyen Biletler' : 'Pending Tickets' ?> (<span id="nav-ticket-header-count"><?= $c_new_tickets_nav ?></span>)
                </span>
                <div class="dropdown-divider" style="margin: 0;"></div>
                <script>window.EAPRIMUS_LANG_NO_NOTIFICATIONS = "<?= $isTr ? 'Yeni veya okunmamış bilet bildirimi yok' : 'No unread ticket notifications' ?>";</script>
                <div id="nav-ticket-dropdown-items">
                <?php if(empty($new_tickets_list)): ?>
                    <div class="p-3 text-center text-muted small"><?= $isTr ? 'Yeni veya okunmamış bilet bildirimi yok' : 'No unread ticket notifications' ?></div>
                <?php else: ?>
                <?php foreach($new_tickets_list as $nt): 
                    $status_text = '';
                    $status_class = 'badge-secondary';
                    $unread = (int)($nt['unread_replies_count'] ?? 0);
                    
                    // Bilet değerlendirilmiş mi kontrol et
                    $ratedVal = null;
                    try {
                        $stmtRatedCheck = $pdo->prepare("SELECT rating FROM ticket_ratings WHERE ticket_id = ?");
                        $stmtRatedCheck->execute([$nt['id']]);
                        $ratedVal = $stmtRatedCheck->fetchColumn();
                    } catch (Exception $e) {}

                    if ($ratedVal) {
                        $status_text = ($isTr ? 'Puanlandı (' : 'Rated (') . $ratedVal . ' ★)';
                        $status_class = 'badge-warning text-dark';
                    } else if ($nt['status'] === 'closed') {
                        $status_text = $isTr ? 'Bilet Kapatıldı' : 'Ticket Closed';
                        $status_class = 'badge-danger';
                    } else if ($nt['status'] === 'resolved') {
                        $status_text = $isTr ? 'Bilet Çözüldü' : 'Ticket Resolved';
                        $status_class = 'badge-info';
                    } else if ($nt['status'] === 'open' || $nt['status'] === 'assigned') {
                        if ($nt['reply_count'] == 0) {
                            $status_text = $isTr ? 'Yeni Bilet' : 'New Ticket';
                            $status_class = 'badge-success';
                        } else {
                            if ($unread > 0) {
                                $status_text = ($isTr ? 'Cevap Geldi (' : 'New Reply (') . $unread . ')';
                            } else {
                                $status_text = $isTr ? 'Cevap Geldi' : 'New Reply';
                            }
                            $status_class = 'badge-warning text-dark';
                        }
                    } else if ($nt['status'] === 'waiting_customer') {
                        $status_text = $isTr ? 'Cevap Geldi' : 'New Reply';
                        $status_class = 'badge-warning text-dark';
                    } else {
                        $status_text = ucfirst($nt['status']);
                    }
                
                    $target_href = ($ratedVal && $current_user_role != 2)
                        ? ($base_url . "raporlar?view=csat")
                        : ($base_url . "bilet-detay/" . $nt['id']);
                ?>
                    <a href="<?= $target_href ?>" class="dropdown-item py-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div style="max-width: 190px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <strong class="small font-weight-bold text-dark">#<?= htmlspecialchars($nt['ticket_no']) ?></strong><br>
                                <span class="small text-muted" style="font-size: 11px;"><?= htmlspecialchars($nt['title']) ?></span>
                            </div>
                            <span class="badge <?= $status_class ?> ml-2" style="font-size: 10px; padding: 4px 6px;"><?= $status_text ?></span>
                        </div>
                    </a>
                    <div class="dropdown-divider" style="margin: 0;"></div>
                <?php endforeach; ?>
                <?php endif; ?>
                </div>
                <a href="<?= $base_url ?>biletler" class="dropdown-item dropdown-footer py-3 small font-weight-bold text-success text-center"><?= $isTr ? 'Tüm Biletleri Gör' : 'View All Tickets' ?></a>
            </div>
        </li>

        <!-- Ses Açma / Kapama (Mute Toggle) -->
        <li class="nav-item">
            <a class="nav-link nav-icon-link cursor-pointer" onclick="EaprimusRealtime.toggleMute()" role="button" title="Sesli Bildirim Ayarı">
                <div class="nav-icon-btn">
                    <i id="nav-volume-icon" class="fas fa-volume-up text-success"></i>
                </div>
            </a>
        </li>

        <!-- Dil Seçimi -->
        <li class="nav-item dropdown">
            <a class="nav-link nav-icon-link" data-toggle="dropdown" href="#" role="button" title="<?= __('language') ?>">
                <div class="nav-icon-btn">
                    <i class="fas fa-globe"></i>
                    <span class="badge badge-warning navbar-badge" style="font-size: 0.6rem;"><?= strtoupper($current_lang) ?></span>
                </div>
            </a>
            <?php
            $current_uri = strtok($_SERVER["REQUEST_URI"], '?');
            $qs = $_GET;
            unset($qs['lang']);
            if (isset($qs['action'])) {
                unset($qs['action']);
                unset($qs['id']);
                unset($qs['clone']);
                unset($qs['return_to']);
            }
            $tr_link = $current_uri . "?" . http_build_query(array_merge($qs, ['lang' => 'tr']));
            $en_link = $current_uri . "?" . http_build_query(array_merge($qs, ['lang' => 'en']));
            ?>
            <div class="dropdown-menu dropdown-menu-right p-0" style="min-width: 120px;">
                <a href="<?= $tr_link ?>" id="lang-link-tr"
                    class="dropdown-item <?= $current_lang == 'tr' ? 'active' : '' ?> d-flex align-items-center py-2">
                    <img src="https://flagcdn.com/w20/tr.png" class="mr-2" style="width: 18px; border-radius: 2px;">
                    Türkçe
                </a>
                <a href="<?= $en_link ?>" id="lang-link-en"
                    class="dropdown-item <?= $current_lang == 'en' ? 'active' : '' ?> d-flex align-items-center py-2">
                    <img src="https://flagcdn.com/w20/gb.png" class="mr-2" style="width: 18px; border-radius: 2px;">
                    English
                </a>
            </div>
        </li>

        <li class="nav-item">
            <a class="nav-link nav-icon-link" id="navDarkModeToggle" href="javascript:void(0);" onclick="toggleDarkMode(event)" role="button" title="<?= __('dark_mode') ?>">
                <div class="nav-icon-btn">
                    <i class="fas <?= (($_SESSION['theme'] ?? '') === 'dark') ? 'fa-sun' : 'fa-moon' ?>" id="navDarkModeIcon"></i>
                </div>
            </a>
            <script>
                if (typeof window.toggleDarkMode === 'undefined') {
                    window.toggleDarkMode = function(e) {
                        if (e) { e.preventDefault(); e.stopPropagation(); }
                        document.body.classList.toggle('dark-mode');
                        var isDark = document.body.classList.contains('dark-mode');
                        var theme = isDark ? 'dark' : 'light';
                        localStorage.setItem('theme', theme);
                        document.querySelectorAll('#navDarkModeIcon, #darkModeIcon, .darkModeIcon').forEach(function(icon) {
                            if (isDark) { icon.classList.replace('fa-moon', 'fa-sun'); }
                            else { icon.classList.replace('fa-sun', 'fa-moon'); }
                        });
                        fetch('anasayfa', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                            body: 'action=toggle_theme&theme=' + theme + '&csrf_token=' + encodeURIComponent('<?= csrf_token() ?>')
                        }).catch(function(err){});
                    };
                }
            </script>
        </li>

        <li class="nav-item dropdown ml-1">
            <a class="nav-link user-welcome-text" data-toggle="dropdown" href="#" aria-expanded="false">
                <div class="user-avatar-circle">
                    <?php if (!empty($_navbar_avatar_src)): ?>
                        <img src="<?= $_navbar_avatar_src ?>" alt="<?= htmlspecialchars($ilk_isim) ?>">
                    <?php else: ?>
                        <?php echo strtoupper(mb_substr($ilk_isim, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="user-info-text d-none d-md-block">
                    <span class="user-greeting"><?= __('hello') ?>,</span>
                    <div class="user-name"><?php echo htmlspecialchars($ilk_isim); ?></div>
                </div>
            </a>

            <div class="dropdown-menu navbar-dropdown-menu dropdown-menu-right mt-2">
                <a href="<?= $base_url ?>profilim" class="dropdown-item d-flex align-items-center">
                    <i class="fas fa-id-card mr-3 text-info" style="width:16px;"></i> <?= __('profile') ?>
                </a>

                <a href="anasayfa?panel=<?= $current_panel ?>" class="dropdown-item d-flex align-items-center">
                    <i class="fas fa-home mr-3 text-primary" style="width:16px;"></i> <?= __('dashboard') ?>
                </a>

                <?php if ($current_user_role == 1): ?>
                <a href="yetki-yonetimi" class="dropdown-item d-flex align-items-center">
                    <i class="fas fa-user-shield mr-3 text-warning" style="width:16px;"></i> <?= $isTr ? 'Yetki ve Roller' : 'Permissions & Roles' ?>
                </a>
                <?php endif; ?>

                <div class="dropdown-divider my-1"></div>

                <a href="anasayfa?route=cikis" class="dropdown-item d-flex align-items-center text-danger font-weight-bold">
                    <i class="fas fa-running mr-3 animasyonlu-cikis" style="width:16px;"></i>
                    <?= __('logout') ?>
                </a>
            </div>

        </li>
    </ul>
</nav>

<script>
$(function() {
    var s = $(".main-header input[name='search']");
    if(s.length && $.ui && $.ui.autocomplete) {
        s.autocomplete({
            source: "varliklar?autocomplete=1",
            minLength: 1,
            select: function(e, ui) { 
                s.val(ui.item.value); 
                if (ui.item.url) {
                    window.location.href = ui.item.url;
                } else {
                    window.location.href = "varliklar?view=assets&search=" + encodeURIComponent(ui.item.value);
                }
                return false; 
            }
        });
        if(s.autocomplete("instance")) {
            s.autocomplete("instance")._renderMenu = function(ul, items) {
                var that = this, cat = "";
                $.each(items, function(i, item) {
                    if (item.category != cat) { ul.append("<li class='ui-autocomplete-category'>" + item.category + "</li>"); cat = item.category; }
                    that._renderItemData(ul, item);
                });
            };
        }
    }
});
</script>
