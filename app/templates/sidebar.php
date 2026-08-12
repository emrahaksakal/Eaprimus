<?php
// PROFIL FOTOGRAFI ISLEMLERI
$sidebar_foto = (!empty($_navbar_avatar_src)) ? $_navbar_avatar_src : "dist/img/user2-160x160.jpg";

$current_panel = htmlspecialchars(trim($_GET['panel'] ?? (in_array(($route ?? ''), ['biletler', 'bilet-detay', 'musteriler', 'organizasyonlar', 'takimlar', 'kuyruklar', 'sla-dashboard', 'mail-spam-logs']) ? 'ticket' : 'inventory')));

$allowed_routes = $_SESSION['allowed_routes_' . ($current_user_role ?? 0)] ?? null;
if ($allowed_routes === null && isset($pdo) && isset($current_user_role)) {
  $allowed_routes = [];
  try {
    $stmtPerms = $pdo->prepare("SELECT route_name FROM user_perm WHERE role_id = ? AND user_id IS NULL");
    $stmtPerms->execute([$current_user_role]);
    $perms_res = $stmtPerms->fetch();
    if ($perms_res) {
      if ($perms_res['route_name'] === '*') {
        $allowed_routes = ['*'];
      } else {
        $allowed_routes = explode(',', $perms_res['route_name']);
      }
    }
    $_SESSION['allowed_routes_' . $current_user_role] = $allowed_routes;
  } catch (Exception $e) {}
}
if (!is_array($allowed_routes)) { $allowed_routes = ['*']; }
if (!function_exists('has_sidebar_permission')) {
  function has_sidebar_permission($route_key, $allowed_routes) {
    if (in_array('*', $allowed_routes)) {
      return true;
    }
    return in_array($route_key, $allowed_routes);
  }
}
?>

<aside class="main-sidebar sidebar-dark-primary elevation-4 sidebar-no-expand">
  <a href="anasayfa?panel=<?= $current_panel ?>" class="brand-link" onclick="toggleBrandLinkManual(event)">
    <?php if (isset($_logo_path) && file_exists(__DIR__ . '/../../' . $_logo_path)): 
      $logo_v = filemtime(__DIR__ . '/../../' . $_logo_path); ?>
      <img src="<?= htmlspecialchars($_logo_path) ?>?v=<?= $logo_v ?>" alt="Logo" class="brand-image elevation-3">
    <?php else: ?>
      <span class="brand-image img-circle elevation-3 brand-icon-fallback">
        <i class="fas fa-cogs fa-spin text-white" style="font-size: 1.2rem;"></i>
      </span>
    <?php endif; ?>
    <span class="brand-text brand-text-custom ml-2"><?= htmlspecialchars($_company_name ?? 'Destek') ?></span>
  </a>

  <div class="sidebar">
    <div class="user-panel mt-3 pb-3 mb-3 d-flex align-items-center">
      <div class="image">
        <img src="<?= htmlspecialchars($sidebar_foto) ?>" class="img-circle elevation-2" alt="User Image"
          style="width:34px; height:34px; object-fit:cover; border: 1px solid rgba(255,255,255,0.2);">
      </div>
        <div class="info">
          <a href="<?= $base_url ?>profilim" class="d-block">
            <?= htmlspecialchars($current_user_fullname) ?>
          </a>
      </div>
    </div>

    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
        <li class="nav-item">
          <a href="anasayfa?panel=<?= $current_panel ?>"
            class="nav-link <?= ($route == 'main') ? 'active' : '' ?>">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p><?= __('dashboard') ?></p>
          </a>
        </li>

        <?php if (has_sidebar_permission('varliklar', $allowed_routes)): ?>
        <li class="nav-header" style="font-weight: 700; color: #3b82f6;"><?= __('inventory_management') ?></li>

        <?php
        $c_all = $pdo->query("SELECT COUNT(*) FROM assets WHERE deleted_at IS NULL")->fetchColumn();
        $c_deployed = $pdo->query("SELECT COUNT(*) FROM assets WHERE (assigned_user_id IS NOT NULL OR asset_id IS NOT NULL) AND deleted_at IS NULL")->fetchColumn();
        $c_ready = $pdo->query("SELECT COUNT(*) FROM assets a JOIN asset_status_labels sl ON a.status_id = sl.id WHERE a.assigned_user_id IS NULL AND a.asset_id IS NULL AND sl.type = 'deployable' AND a.deleted_at IS NULL")->fetchColumn();
        $c_faulty = $pdo->query("SELECT COUNT(*) FROM assets a JOIN asset_status_labels sl ON a.status_id = sl.id WHERE sl.type IN ('undeployable', 'pending') AND sl.id != 6 AND a.deleted_at IS NULL")->fetchColumn();
        $c_scrapped = $pdo->query("SELECT COUNT(*) FROM assets a JOIN asset_status_labels sl ON a.status_id = sl.id WHERE sl.id = 6 AND a.deleted_at IS NULL")->fetchColumn();
        ?>

        <?php if (hasPermission('varliklar_view_all')): ?>
        <li class="nav-item <?= ($route == 'varliklar' && ($_GET['view'] ?? 'assets') == 'assets') ? 'menu-open' : '' ?>">
          <a href="#" class="nav-link <?= ($route == 'varliklar' && ($_GET['view'] ?? 'assets') == 'assets') ? 'active' : '' ?>">
            <i class="nav-icon fas fa-laptop"></i>
            <p>
              <?= __('fixed_assets') ?>
              <i class="right fas fa-angle-left"></i>
            </p>
          </a>
          <ul class="nav nav-treeview">
            <li class="nav-item text-xs">
              <a href="varliklar?view=assets" class="nav-link <?= !isset($_GET['status']) ? 'active' : '' ?>">
                <i class="fas fa-list nav-icon text-info"></i>
                <p><?= __('all_assets_list') ?> <span class="badge badge-secondary right"><?= $c_all ?></span></p>
              </a>
            </li>
            <li class="nav-item text-xs">
              <a href="varliklar?view=assets&status=deployed" class="nav-link <?= ($_GET['status'] ?? '') == 'deployed' ? 'active' : '' ?>">
                <i class="fas fa-user-check nav-icon text-primary"></i>
                <p><?= __('assigned_assets') ?> <span class="badge badge-secondary right"><?= $c_deployed ?></span></p>
              </a>
            </li>
            <li class="nav-item text-xs">
              <a href="varliklar?view=assets&status=ready" class="nav-link <?= ($_GET['status'] ?? '') == 'ready' ? 'active' : '' ?>">
                <i class="fas fa-check-circle nav-icon text-success"></i>
                <p><?= __('ready_for_assignment') ?> <span class="badge badge-secondary right"><?= $c_ready ?></span></p>
              </a>
            </li>
            <li class="nav-item text-xs">
              <a href="varliklar?view=assets&status=faulty" class="nav-link <?= ($_GET['status'] ?? '') == 'faulty' ? 'active' : '' ?>">
                <i class="fas fa-tools nav-icon text-warning"></i>
                <p><?= __('faulty_assets') ?> <span class="badge badge-secondary right"><?= $c_faulty ?></span></p>
              </a>
            </li>
            <li class="nav-item text-xs">
              <a href="varliklar?view=assets&status=scrapped" class="nav-link <?= ($_GET['status'] ?? '') == 'scrapped' ? 'active' : '' ?>">
                <i class="fas fa-dumpster nav-icon text-danger"></i>
                <p><?= __('scrapped_assets') ?> <span class="badge badge-secondary right"><?= $c_scrapped ?></span></p>
              </a>
            </li>
          </ul>
        </li>
        <?php else: 
            $c_my_assets = 0;
            try {
                if (isset($current_user_id)) {
                    $c_my_assets = $pdo->query("SELECT COUNT(*) FROM assets WHERE assigned_user_id = " . (int)$current_user_id . " AND deleted_at IS NULL")->fetchColumn();
                }
            } catch (Exception $e) {}
        ?>
        <li class="nav-item">
          <a href="varliklar?view=assets" class="nav-link <?= ($route == 'varliklar' && ($_GET['view'] ?? 'assets') == 'assets') ? 'active' : '' ?>">
            <i class="nav-icon fas fa-laptop"></i>
            <p><?= $isTr ? 'Zimmetlerim' : 'My Assets' ?> <span class="badge badge-secondary right"><?= $c_my_assets ?></span></p>
          </a>
        </li>
        <?php endif; ?>

        <?php
        $c_licenses = 0; $c_accessories = 0; $c_consumables = 0; $c_components = 0;
        try {
            $sb_uid = (int)($current_user_id ?? 0);
            $can_see_all_sb = hasPermission('varliklar_view_all') || in_array((int)($current_user_role ?? 0), [1, 3]);
            if ($can_see_all_sb) {
                $c_licenses = (int)$pdo->query("SELECT COUNT(*) FROM asset_licenses WHERE deleted_at IS NULL")->fetchColumn();
                $c_accessories = (int)$pdo->query("SELECT COUNT(*) FROM asset_accessories WHERE deleted_at IS NULL")->fetchColumn();
                $c_consumables = (int)$pdo->query("SELECT COUNT(*) FROM asset_consumables WHERE deleted_at IS NULL")->fetchColumn();
                $c_components = (int)$pdo->query("SELECT COUNT(*) FROM asset_components WHERE deleted_at IS NULL")->fetchColumn();
            } else {
                $c_licenses = (int)$pdo->query("SELECT COUNT(*) FROM asset_licenses l WHERE l.deleted_at IS NULL AND (l.assigned_user_id = $sb_uid OR l.id IN (SELECT alc.license_id FROM asset_license_checkouts alc LEFT JOIN assets a ON alc.asset_id = a.id WHERE alc.user_id = $sb_uid OR alc.assigned_user_id = $sb_uid OR a.assigned_user_id = $sb_uid))")->fetchColumn();
                $c_accessories = (int)$pdo->query("SELECT COUNT(*) FROM asset_accessories a WHERE a.deleted_at IS NULL AND (a.assigned_user_id = $sb_uid OR a.id IN (SELECT aac.accessory_id FROM asset_accessory_checkouts aac LEFT JOIN assets ast ON aac.asset_id = ast.id WHERE aac.user_id = $sb_uid OR aac.assigned_user_id = $sb_uid OR ast.assigned_user_id = $sb_uid))")->fetchColumn();
                $c_consumables = (int)$pdo->query("SELECT COUNT(*) FROM asset_consumables c WHERE c.deleted_at IS NULL AND (c.assigned_user_id = $sb_uid OR c.id IN (SELECT acc.consumable_id FROM asset_consumable_checkouts acc LEFT JOIN assets ast ON acc.asset_id = ast.id WHERE acc.user_id = $sb_uid OR acc.assigned_user_id = $sb_uid OR ast.assigned_user_id = $sb_uid))")->fetchColumn();
                $c_components = (int)$pdo->query("SELECT COUNT(*) FROM asset_components ca WHERE ca.deleted_at IS NULL AND (ca.assigned_user_id = $sb_uid OR ca.asset_id IN (SELECT id FROM assets WHERE assigned_user_id = $sb_uid) OR ca.id IN (SELECT acc.component_id FROM asset_component_checkouts acc LEFT JOIN assets ast ON acc.asset_id = ast.id WHERE acc.user_id = $sb_uid OR acc.assigned_user_id = $sb_uid OR acc.assigned_asset_id IN (SELECT id FROM assets WHERE assigned_user_id = $sb_uid) OR ast.assigned_user_id = $sb_uid))")->fetchColumn();
            }
        } catch (Exception $e) {}
        ?>

        <?php if (hasPermission('varliklar_view_licenses') || hasPermission('varliklar_view_all') || ((int)($current_user_role ?? 0) === 2)): ?>
        <li class="nav-item">
          <a href="varliklar?view=licenses"
            class="nav-link <?= ($_GET['view'] ?? '') == 'licenses' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-save"></i>
            <p><?= __('licenses') ?> <span class="badge badge-secondary right"><?= $c_licenses ?></span></p>
          </a>
        </li>
        <?php endif; ?>

        <?php if (hasPermission('varliklar_view_accessories') || hasPermission('varliklar_view_all') || ((int)($current_user_role ?? 0) === 2)): ?>
        <li class="nav-item">
          <a href="varliklar?view=accessories"
            class="nav-link <?= ($_GET['view'] ?? '') == 'accessories' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-keyboard"></i>
            <p><?= __('accessories') ?> <span class="badge badge-secondary right"><?= $c_accessories ?></span></p>
          </a>
        </li>
        <?php endif; ?>

        <?php if (hasPermission('varliklar_view_consumables') || hasPermission('varliklar_view_all') || ((int)($current_user_role ?? 0) === 2)): ?>
        <li class="nav-item">
          <a href="varliklar?view=consumables"
            class="nav-link <?= ($_GET['view'] ?? '') == 'consumables' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-tint"></i>
            <p><?= __('consumables') ?> <span class="badge badge-secondary right"><?= $c_consumables ?></span></p>
          </a>
        </li>
        <?php endif; ?>

        <?php if (hasPermission('varliklar_view_components') || hasPermission('varliklar_view_all') || ((int)($current_user_role ?? 0) === 2)): ?>
        <li class="nav-item">
          <a href="varliklar?view=components"
            class="nav-link <?= ($_GET['view'] ?? '') == 'components' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-microchip"></i>
            <p><?= __('components') ?> <span class="badge badge-secondary right"><?= $c_components ?></span></p>
          </a>
        </li>
        <?php endif; ?>



        <?php endif; ?>

        <?php if (has_sidebar_permission('raporlar', $allowed_routes)): ?>
        <li class="nav-item">
          <a href="raporlar" class="nav-link <?= $route == 'raporlar' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-chart-line"></i>
            <p><?= __('reports') ?? 'Raporlar' ?></p>
          </a>
        </li>
        <?php endif; ?>

        <?php if (has_sidebar_permission('amortisman', $allowed_routes)): ?>
        <li class="nav-item">
          <a href="amortisman" class="nav-link <?= $route == 'amortisman' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-calculator text-primary"></i>
            <p><?= $isTr ? 'Amortisman' : 'Depreciation' ?></p>
          </a>
        </li>
        <?php endif; ?>

        <?php if (has_sidebar_permission('sayim', $allowed_routes)): ?>
        <li class="nav-item">
          <a href="sayim" class="nav-link <?= $route == 'sayim' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-qrcode text-success"></i>
            <p><?= $isTr ? 'Envanter Sayımı' : 'Inventory Audit' ?></p>
          </a>
        </li>
        <?php endif; ?>

        <?php if (has_sidebar_permission('network-discovery', $allowed_routes)): ?>
        <li class="nav-item">
          <a href="network-discovery" class="nav-link <?= $route == 'network-discovery' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-network-wired text-info"></i>
            <p><?= $isTr ? 'Ağ Taraması' : 'Network Discovery' ?></p>
          </a>
        </li>
        <?php endif; ?>

        <?php
        $c_pending_signatures = 0;
        try {
            if (isset($current_user_id)) {
                 $isAdminBadge = in_array($current_user_role ?? 3, [1, 3]);
        $badgeWhere = "status = 'pending_user' AND user_id = " . (int)$current_user_id;
        if ($isAdminBadge) {
            $admin_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN (1, 3)")->fetchColumn();
            if ($admin_count > 1) {
                $badgeWhere = "(status = 'pending_user' AND user_id = " . (int)$current_user_id . ") OR (status = 'pending_admin' AND user_id != " . (int)$current_user_id . ")";
            } else {
                $badgeWhere = "(status = 'pending_user' AND user_id = " . (int)$current_user_id . ") OR (status = 'pending_admin')";
            }
        }
        $c_pending_signatures = $pdo->query("SELECT COUNT(*) FROM asset_signatures WHERE $badgeWhere")->fetchColumn();
            }
        } catch (Exception $e) {}
        ?>
        <li class="nav-item">
          <a href="varliklar?view=signatures" class="nav-link <?= ($_GET['view'] ?? '') == 'signatures' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-file-signature text-warning"></i>
            <p>
              <?= $isTr ? 'Zimmet Onaylarım' : 'My Approvals' ?>
              <?php if ($c_pending_signatures > 0): ?>
                <span class="badge badge-danger right"><?= $c_pending_signatures ?></span>
              <?php endif; ?>
            </p>
          </a>
        </li>

        <li class="nav-header" style="font-weight: 700; color: #10b981;"><?= __('ticket_system') ?></li>

        <?php if ($current_user_role == 2): ?>
          <li class="nav-item">
            <a href="anasayfa?panel=ticket&status=all" class="nav-link <?= $route == 'biletler' ? 'active' : '' ?>">
              <i class="nav-icon fas fa-ticket-alt text-info"></i>
              <p><?= $isTr ? 'Biletlerim' : 'My Tickets' ?></p>
            </a>
          </li>
          <?php if (has_sidebar_permission('ticket-olustur', $allowed_routes)): ?>
          <li class="nav-item">
            <a href="ticket-olustur" class="nav-link <?= $route == 'ticket-olustur' ? 'active' : '' ?>">
              <i class="nav-icon fas fa-plus-circle text-success"></i>
              <p><?= $isTr ? 'Yeni Bilet Aç' : 'Open New Ticket' ?></p>
            </a>
          </li>
          <?php endif; ?>
        <?php else: ?>
          <li class="nav-item">
            <a href="anasayfa?panel=ticket&status=all" class="nav-link <?= $route == 'biletler' ? 'active' : '' ?>">
              <i class="nav-icon fas fa-ticket-alt"></i>
              <p><?= __('all_tickets') ?></p>
            </a>
          </li>
        <?php endif; ?>

        <?php if ($current_user_role == 1 || $current_user_role == 3): ?>
          <li class="nav-item">
            <a href="sla-dashboard" class="nav-link <?= $route == 'sla-dashboard' ? 'active' : '' ?>">
              <i class="nav-icon fas fa-chart-pie"></i>
              <p><?= __('sla') ?></p>
            </a>
          </li>

          <li class="nav-item <?= in_array($route, ['musteriler', 'organizasyonlar', 'musteri_fields']) ? 'menu-open' : '' ?>">
            <a href="#" class="nav-link <?= in_array($route, ['musteriler', 'organizasyonlar', 'musteri_fields']) ? 'active' : '' ?>">
              <i class="nav-icon fas fa-address-book"></i>
              <p>
                <?= __('customers') ?>
                <i class="right fas fa-angle-left"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="organizasyonlar" class="nav-link <?= $route == 'organizasyonlar' ? 'active' : '' ?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p><?= __('organizations') ?></p>
                </a>
              </li>
              <li class="nav-item">
                <a href="musteriler" class="nav-link <?= $route == 'musteriler' ? 'active' : '' ?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p><?= __('customers_list_menu') ?></p>
                </a>
              </li>
              <?php if ($current_user_role == 1): ?>
                <li class="nav-item">
                  <a href="musteri-fields" class="nav-link <?= $route == 'musteri_fields' ? 'active' : '' ?>">
                    <i class="far fa-circle nav-icon"></i>
                    <p><?= __('customer_custom_fields') ?></p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>

          <li class="nav-item <?= in_array($route, ['takimlar', 'kuyruklar', 'mail-spam-logs']) ? 'menu-open' : '' ?>">
            <a href="#" class="nav-link <?= in_array($route, ['takimlar', 'kuyruklar', 'mail-spam-logs']) ? 'active' : '' ?>">
              <i class="nav-icon fas fa-sitemap"></i>
              <p>
                <?= __('ticket_infra') ?>
                <i class="right fas fa-angle-left"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="takimlar" class="nav-link <?= $route == 'takimlar' ? 'active' : '' ?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p><?= __('teams') ?></p>
                </a>
              </li>
              <li class="nav-item">
                <a href="kuyruklar" class="nav-link <?= $route == 'kuyruklar' ? 'active' : '' ?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p><?= __('queues') ?></p>
                </a>
              </li>
              <?php if ($current_user_role == 1): ?>
              <li class="nav-item">
                <a href="anasayfa?route=mail-spam-logs" class="nav-link <?= $route == 'mail-spam-logs' ? 'active' : '' ?>">
                  <i class="fas fa-shield-alt nav-icon text-danger"></i>
                  <p><?= $isTr ? 'Spam Logları' : 'Spam Logs' ?></p>
                </a>
              </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <li class="nav-header" style="font-weight: 700; color: #64748b;"><?= __('system') ?></li>

        <?php if ($current_user_role == 1 || $current_user_role == 3): ?>
          <li class="nav-item <?= in_array($route, ['kullanici_ekle', 'kullanici_listele', 'kullanici_duzenle', 'davet_bekleyenler']) ? 'menu-open' : '' ?>">
            <a href="#" class="nav-link <?= in_array($route, ['kullanici_ekle', 'kullanici_listele', 'kullanici_duzenle', 'davet_bekleyenler']) ? 'active' : '' ?>">
              <i class="nav-icon fas fa-users"></i>
              <p>
                <?= __('users') ?>
                <i class="right fas fa-angle-left"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="kullanici-listele" class="nav-link <?= $route == 'kullanici_listele' ? 'active' : '' ?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p><?= __('user_list') ?></p>
                </a>
              </li>
              <li class="nav-item">
                <a href="kullanici-ekle" class="nav-link <?= $route == 'kullanici_ekle' ? 'active' : '' ?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p><?= __('add_user') ?></p>
                </a>
              </li>
              <?php 
                // Bekleyen davet sayısını çek (Silinmemiş aktif kayıtlar)
                $c_invites = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 2 AND (password IS NULL OR password = '') AND deleted_at IS NULL")->fetchColumn();
              ?>
              <li class="nav-item">
                <a href="davet-bekleyenler" class="nav-link <?= $route == 'davet_bekleyenler' ? 'active' : '' ?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Davet Bekleyenler <?= $c_invites > 0 ? '<span class="badge badge-warning right">'.$c_invites.'</span>' : '' ?></p>
                </a>
              </li>
              <li class="nav-item">
                <a href="anasayfa?route=toplu_ice_aktar" class="nav-link <?= $route == 'toplu_ice_aktar' ? 'active' : '' ?>">
                  <i class="fas fa-file-import nav-icon text-success"></i>
                  <p><?= $isTr ? 'Toplu İçe Aktar (Excel)' : 'Bulk Import (Excel)' ?></p>
                </a>
              </li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($current_user_role == 1 || $current_user_role == 3): ?>
          <li class="nav-item">
            <a href="anasayfa?route=duyurular" class="nav-link <?= $route == 'duyurular' ? 'active' : '' ?>">
              <i class="nav-icon fas fa-bullhorn text-warning"></i>
              <p><?= $isTr ? 'Duyuru Yönetimi' : 'Announcements' ?></p>
            </a>
          </li>
        <?php endif; ?>

        <?php if ($current_user_role == 1): ?>
          <li class="nav-item">
            <a href="yetki-yonetimi" class="nav-link <?= $route == 'yetki-yonetimi' ? 'active' : '' ?>">
              <i class="nav-icon fas fa-user-shield text-info"></i>
              <p><?= $isTr ? 'Yetki ve Roller' : 'Permissions & Roles' ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="sistem-ayarlari" class="nav-link <?= $route == 'sistem-ayarlari' ? 'active' : '' ?>">
              <i class="nav-icon fas fa-cogs"></i>
              <p><?= __('settings') ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="system-logs" class="nav-link <?= $route == 'sistem-loglari' ? 'active' : '' ?>">
              <i class="nav-icon fas fa-terminal"></i>
              <p><?= $isTr ? 'Sistem & Cron Logları' : 'System Logs' ?></p>
            </a>
          </li>
        <?php elseif ($current_user_role == 2 || $current_user_role == 3 || hasPermission('canned_responses_access')): ?>
          <li class="nav-item">
            <a href="sistem-ayarlari?tab=canned" class="nav-link <?= ($route == 'sistem-ayarlari' && ($_GET['tab'] ?? '') == 'canned') ? 'active' : '' ?>">
              <i class="nav-icon fas fa-bolt text-warning"></i>
              <p><?= $isTr ? 'Hazır Yanıt Şablonları' : 'Canned Responses' ?></p>
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </nav>
    <div class="sidebar-bottom-spacer" style="height: 25px;"></div>
  </div>
</aside>

