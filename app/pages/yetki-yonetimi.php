<?php
// app/pages/yetki-yonetimi.php
if (!defined('EAPRIMUS_KEY')) {
    exit('Erişim Engellendi');
}

$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

if ($current_user_role != 1) {
    header("Location: 403");
    exit;
}

$mesaj = '';
$hata = '';

// 🔍 Dinamik Rota Keşfi (app/pages/ dizinindeki dosyaları tarar)
$pages_dir = __DIR__ . "/../pages/";
$files = glob($pages_dir . "*.php");
$dynamic_routes = [];

$exclude = ['403', '404', 'cikis', 'view_attachment', 'export_activity', 'export_users_pdf', 'migrate_sig_indexes'];

foreach ($files as $file) {
    $route_name = basename($file, ".php");
    if (!in_array($route_name, $exclude)) {
        $dynamic_routes[] = $route_name;
    }
}
sort($dynamic_routes);
if (($main_key = array_search('main', $dynamic_routes)) !== false) {
    unset($dynamic_routes[$main_key]);
    array_unshift($dynamic_routes, 'main');
}

// Alt yetki (Granüler) tanımları
$granular_perms = [
    'main' => [
        'dashboard_view_all' => ['tr' => 'Tüm İstatistikleri Görebilir (Modern Dashboard)', 'en' => 'Can View All Stats (Modern Dashboard)']
    ],
    'varliklar' => [
        'varliklar_view_all' => ['tr' => 'Tüm Varlıkları Görebilir', 'en' => 'Can View All Assets'],
        'varliklar_view_own' => ['tr' => 'Sadece Kendisine Zimmetli Olanları Görebilir', 'en' => 'Can View Own Assets Only'],
        'varliklar_edit' => ['tr' => 'Varlık Ekleyebilir / Düzenleyebilir', 'en' => 'Can Edit / Create Assets'],
        'varliklar_checkin' => ['tr' => 'Zimmet İade Alabilir', 'en' => 'Can Return / Check-in Assets'],
        'varliklar_upload_attachment' => ['tr' => 'Belge/Dosya Yükleyebilir', 'en' => 'Can Upload Documents/Attachments'],
        'varliklar_delete_attachment' => ['tr' => 'Belge/Dosya Silebilir', 'en' => 'Can Delete Documents/Attachments'],
        'varliklar_clear_logs' => ['tr' => 'Geçmiş Hareket Kayıtlarını Silebilir/Temizleyebilir', 'en' => 'Can Clear/Delete Activity Logs'],
        'varliklar_view_licenses' => ['tr' => 'Lisansları Görebilir', 'en' => 'Can View Licenses'],
        'varliklar_view_accessories' => ['tr' => 'Aksesuarları Görebilir', 'en' => 'Can View Accessories'],
        'varliklar_view_consumables' => ['tr' => 'Sarf Malzemeleri Görebilir', 'en' => 'Can View Consumables'],
        'varliklar_view_components' => ['tr' => 'Bileşenleri Görebilir', 'en' => 'Can View Components']
    ],
    'biletler' => [
        'biletler_view_all' => ['tr' => 'Tüm Biletleri Görebilir', 'en' => 'Can View All Tickets'],
        'biletler_view_own' => ['tr' => 'Sadece Kendisine Atanan / Açtığı Biletleri Görebilir', 'en' => 'Can View Own Tickets Only'],
        'biletler_edit' => ['tr' => 'Biletleri Düzenleyebilir', 'en' => 'Can Edit Tickets'],
        'biletler_add_effort' => ['tr' => 'Efor Ekleyebilir', 'en' => 'Can Add Effort (Time Logs)'],
        'biletler_add_subtask' => ['tr' => 'Alt Görev Ekleyebilir', 'en' => 'Can Add Subtasks'],
        'biletler_transfer' => ['tr' => 'Bilet Transfer Edebilir', 'en' => 'Can Transfer Tickets'],
        'canned_responses_access' => ['tr' => 'Hazır Yanıt Şablonlarına Erişim ve Kullanım', 'en' => 'Canned Responses Access & Management']
    ],
    'varlik_detay' => [
        'varlik_detay_tab_purchase' => ['tr' => 'Satın Alma Sekmesini Görebilir (Fiyat/Fatura)', 'en' => 'Can View Purchase Tab (Price/Invoice)'],
        'varlik_detay_tab_licenses' => ['tr' => 'Lisanslar Sekmesini Görebilir', 'en' => 'Can View Licenses Tab'],
        'varlik_detay_tab_devices' => ['tr' => 'İlişkili Cihazlar Sekmesini Görebilir', 'en' => 'Can View Related Devices Tab'],
        'varlik_detay_tab_accessories' => ['tr' => 'İlişkili Aksesuarlar Sekmesini Görebilir', 'en' => 'Can View Related Accessories Tab'],
        'varlik_detay_tab_components' => ['tr' => 'Bileşenler Sekmesini Görebilir', 'en' => 'Can View Components Tab'],
        'varlik_detay_tab_attachments' => ['tr' => 'Ekler / Belgeler Sekmesini Görebilir', 'en' => 'Can View Attachments / Documents Tab'],
        'varlik_detay_view_all_attachments' => ['tr' => 'Geçmiş Tüm Zimmet Belgelerini ve Ekleri Görebilir', 'en' => 'Can View All Past Zimmet Documents & Attachments']
    ]
];

// POST İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    // Rol Ekleme
    if (isset($_POST['action']) && $_POST['action'] === 'add_role') {
        $name = trim($_POST['role_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (!empty($name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO custom_roles (role_name, description) VALUES (?, ?)");
                $stmt->execute([$name, $desc]);
                $mesaj = $isTr ? "Yeni rol başarıyla oluşturuldu." : "New role created successfully.";
            } catch (Exception $e) {
                $hata = $e->getMessage();
            }
        } else {
            $hata = $isTr ? "Rol adı boş olamaz!" : "Role name cannot be empty!";
        }
    }

    // Rol Yetkilerini Güncelleme
    if (isset($_POST['action']) && $_POST['action'] === 'save_permissions') {
        $role_id_str = $_POST['role_id'] ?? '';
        $selected_perms = $_POST['perms'] ?? [];

        try {
            $pdo->beginTransaction();
            
            if (strpos($role_id_str, 'default_') === 0) {
                // Update default user_perm
                $r_id = intval(str_replace('default_', '', $role_id_str));
                $routes_str = implode(',', $selected_perms);
                
                $stmt = $pdo->prepare("SELECT id FROM user_perm WHERE role_id = ? AND user_id IS NULL");
                $stmt->execute([$r_id]);
                if ($stmt->fetch()) {
                    $pdo->prepare("UPDATE user_perm SET route_name = ? WHERE role_id = ? AND user_id IS NULL")->execute([$routes_str, $r_id]);
                } else {
                    $pdo->prepare("INSERT INTO user_perm (role_id, route_name) VALUES (?, ?)")->execute([$r_id, $routes_str]);
                }
                $mesaj = $isTr ? "Sistem rolü yetkileri başarıyla güncellendi." : "System role permissions updated successfully.";
            } else {
                // Update custom role_permissions
                $role_id = intval($role_id_str);
                if ($role_id > 0) {
                    $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role_id]);
                    if (!empty($selected_perms)) {
                        $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key) VALUES (?, ?)");
                        foreach ($selected_perms as $perm) {
                            $stmt->execute([$role_id, $perm]);
                        }
                    }
                    $mesaj = $isTr ? "Özel rol yetkileri başarıyla güncellendi." : "Custom role permissions updated successfully.";
                }
            }
            $pdo->commit();
            if (isset($r_id)) {
                $saved_role_id = 'default_' . $r_id;
            } elseif (isset($role_id)) {
                $saved_role_id = $role_id;
            }
            $mesaj = $isTr ? "Sistem rolü yetkileri başarıyla güncellendi." : "System role permissions updated successfully.";
            if (isset($role_id)) {
                $mesaj = $isTr ? "Özel rol yetkileri başarıyla güncellendi." : "Custom role permissions updated successfully.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $hata = $e->getMessage();
        }
    }

    // Rol Silme
    if (isset($_POST['action']) && $_POST['action'] === 'delete_role') {
        $role_id = intval($_POST['role_id'] ?? 0);
        if ($role_id > 0) {
            try {
                $pdo->beginTransaction();
                
                // Rolü sil
                $pdo->prepare("DELETE FROM custom_roles WHERE id = ?")->execute([$role_id]);
                // Yetkilerini sil
                $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role_id]);
                // Kullanıcılardaki rolü temizle (Custom Role atananları null yap)
                $pdo->prepare("UPDATE users SET custom_role_id = NULL WHERE custom_role_id = ?")->execute([$role_id]);
                
                $pdo->commit();
                $mesaj = $isTr ? "Rol başarıyla silindi." : "Role deleted successfully.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $hata = $e->getMessage();
            }
        }
    }
}

// Tüm Özel Rolleri Çek
$roles = $pdo->query("SELECT r.*, (SELECT COUNT(*) FROM users WHERE custom_role_id = r.id) as user_count FROM custom_roles r ORDER BY r.role_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Sistem Rollerini Çek
$count2 = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 2 AND (custom_role_id IS NULL OR custom_role_id = 0)")->fetchColumn();
$count3 = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 3 AND (custom_role_id IS NULL OR custom_role_id = 0)")->fetchColumn();

$system_roles = [
    ['id' => 'default_2', 'role_name' => $isTr ? 'Personel (Sistem Rolü)' : 'Personnel (System Role)', 'description' => $isTr ? 'Varsayılan Personel erişim yetkileri' : 'Default Personnel access permissions', 'user_count' => $count2, 'is_system' => true],
    ['id' => 'default_3', 'role_name' => $isTr ? 'Teknik Destek (Sistem Rolü)' : 'Tech Support (System Role)', 'description' => $isTr ? 'Varsayılan Teknik Destek yetkileri' : 'Default Tech Support access permissions', 'user_count' => $count3, 'is_system' => true]
];

// Tüm Rolleri Birleştir
$all_roles = array_merge($system_roles, $roles);

// İzinleri Çek (Özel Roller)
$permissions = [];
$perm_rows = $pdo->query("SELECT role_id, permission_key FROM role_permissions")->fetchAll(PDO::FETCH_ASSOC);
foreach ($perm_rows as $prow) {
    $permissions[$prow['role_id']][] = $prow['permission_key'];
}

// İzinleri Çek (Sistem Rolleri)
$def_perms = $pdo->query("SELECT role_id, route_name FROM user_perm WHERE user_id IS NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
$permissions['default_2'] = isset($def_perms[2]) ? explode(',', $def_perms[2]) : [];
$permissions['default_3'] = isset($def_perms[3]) ? explode(',', $def_perms[3]) : [];

// Yardımcı Etiket Fonksiyonları
if (!function_exists('getRouteLabel')) {
    function getRouteLabel($route, $isTr) {
        $labels = [
            'main' => ['tr' => 'Genel Bakış (Dashboard)', 'en' => 'Dashboard Overview'],
            'biletler' => ['tr' => 'Bilet Listesi', 'en' => 'Ticket List'],
            'bilet-detay' => ['tr' => 'Bilet Detayı', 'en' => 'Ticket Detail'],
            'ticket-olustur' => ['tr' => 'Yeni Bilet Aç', 'en' => 'Create Ticket'],
            'varliklar' => ['tr' => 'Varlık Yönetimi', 'en' => 'Asset Management'],
            'varlik_detay' => ['tr' => 'Varlık Detayı', 'en' => 'Asset Detail'],
            'musteriler' => ['tr' => 'Müşteriler', 'en' => 'Customers'],
            'organizasyonlar' => ['tr' => 'Organizasyonlar', 'en' => 'Organizations'],
            'kullanici_listele' => ['tr' => 'Kullanıcı Listesi', 'en' => 'User List'],
            'kullanici_ekle' => ['tr' => 'Kullanıcı Ekle', 'en' => 'Add User'],
            'kullanici_duzenle' => ['tr' => 'Kullanıcı Düzenle', 'en' => 'Edit User'],
            'takimlar' => ['tr' => 'Takım Yönetimi', 'en' => 'Team Management'],
            'kuyruklar' => ['tr' => 'Kuyruk Yönetimi', 'en' => 'Queue Management'],
            'sla-dashboard' => ['tr' => 'SLA Paneli', 'en' => 'SLA Dashboard'],
            'raporlar' => ['tr' => 'Raporlar & Analizler', 'en' => 'Reports & Analytics'],
            'network-discovery' => ['tr' => 'Ağ Taraması (Discovery)', 'en' => 'Network Discovery'],
            'profil_duzenle' => ['tr' => 'Profil Düzenle', 'en' => 'Edit Profile'],
            'sistem-ayarlari' => ['tr' => 'Sistem Ayarları', 'en' => 'System Settings'],
            'yetkilendirme' => ['tr' => 'Sayfa Yetkilendirme', 'en' => 'Page Authorization'],
            'musteri_detay' => ['tr' => 'Müşteri Detayı', 'en' => 'Customer Detail'],
            'musteri_ekle' => ['tr' => 'Müşteri Ekle', 'en' => 'Add Customer'],
            'musteri_duzenle' => ['tr' => 'Müşteri Düzenle', 'en' => 'Edit Customer'],
            'musteri_fields' => ['tr' => 'Müşteri Özel Alanları', 'en' => 'Customer Custom Fields'],
            'tedarikci_detay' => ['tr' => 'Tedarikçi Detayı', 'en' => 'Supplier Detail'],
            'davet_bekleyenler' => ['tr' => 'Davet Bekleyenler', 'en' => 'Pending Invites'],
            'mail-spam-logs' => ['tr' => 'Mail Spam Logları', 'en' => 'Mail Spam Logs'],
            'amortisman' => ['tr' => 'Amortisman Modülü', 'en' => 'Depreciation Module'],
            'sayim' => ['tr' => 'Barkod & QR Envanter Sayımı', 'en' => 'Inventory Audit Scan'],
            'yetki-yonetimi' => ['tr' => 'Dinamik Yetki Yönetimi', 'en' => 'Dynamic RBAC Roles']
        ];
        return $labels[$route][$isTr ? 'tr' : 'en'] ?? ucwords(str_replace(['_', '-'], ' ', $route));
    }
}

if (!function_exists('getRouteIcon')) {
    function getRouteIcon($route) {
        $icons = [
            'main' => 'fas fa-chart-line',
            'biletler' => 'fas fa-ticket-alt',
            'bilet-detay' => 'fas fa-info-circle',
            'ticket-olustur' => 'fas fa-plus-circle',
            'varliklar' => 'fas fa-cubes',
            'varlik_detay' => 'fas fa-cube',
            'musteriler' => 'fas fa-user-friends',
            'organizasyonlar' => 'fas fa-sitemap',
            'kullanici_listele' => 'fas fa-users-cog',
            'kullanici_ekle' => 'fas fa-user-plus',
            'kullanici_duzenle' => 'fas fa-user-edit',
            'takimlar' => 'fas fa-users',
            'kuyruklar' => 'fas fa-stream',
            'sla-dashboard' => 'fas fa-business-time',
            'raporlar' => 'fas fa-file-invoice-dollar',
            'network-discovery' => 'fas fa-wifi',
            'profil_duzenle' => 'fas fa-user-circle',
            'sistem-ayarlari' => 'fas fa-sliders-h',
            'yetkilendirme' => 'fas fa-user-shield',
            'musteri_detay' => 'fas fa-user-tag',
            'musteri_ekle' => 'fas fa-plus',
            'musteri_duzenle' => 'fas fa-edit',
            'musteri_fields' => 'fas fa-list-ul',
            'tedarikci_detay' => 'fas fa-truck-loading',
            'davet_bekleyenler' => 'fas fa-envelope-open-text',
            'mail-spam-logs' => 'fas fa-shield-virus',
            'amortisman' => 'fas fa-calculator',
            'sayim' => 'fas fa-qrcode',
            'yetki-yonetimi' => 'fas fa-user-shield'
        ];
        return $icons[$route] ?? 'fas fa-link';
    }
}
?>

<style>
    .role-card {
        border-radius: 15px;
        border: none;
        transition: all 0.3s;
    }
    .role-card:hover {
        transform: translateY(-2px);
    }
    .permission-group-header {
        background-color: #f8fafc;
        border-bottom: 2px solid #e2e8f0;
    }
    body.dark-mode .permission-group-header {
        background-color: #0f172a;
        border-bottom-color: #334155;
    }
    .route-perm-row {
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.2s;
    }
    .route-perm-row:hover {
        background-color: rgba(59, 130, 246, 0.03);
    }
    body.dark-mode .route-perm-row {
        border-bottom-color: #334155;
    }
    .ios-switch {
        position: relative;
        display: inline-block;
        width: 44px;
        height: 24px;
    }
    .ios-switch input { opacity: 0; width: 0; height: 0; }
    .ios-slider {
        position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
        background-color: #cbd5e1; transition: .3s; border-radius: 24px;
    }
    .ios-slider:before {
        position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px;
        background-color: white; transition: .3s; border-radius: 50%;
    }
    input:checked + .ios-slider { background-color: #10b981; }
    input:checked + .ios-slider:before { transform: translateX(20px); }
</style>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="m-0 font-weight-bold text-dark"><i class="fas fa-user-lock mr-2 text-primary"></i><?= $isTr ? 'Dinamik Yetki ve Rol Yönetimi (RBAC)' : 'Dynamic Permissions & RBAC' ?></h1>
            <p class="text-muted small"><?= $isTr ? 'Kurumunuza özel sınırsız yetkilere sahip roller oluşturun ve personellere atayın.' : 'Create unlimited custom roles with specific permissions and assign them to users.' ?></p>
        </div>
        <button class="btn btn-primary" style="border-radius:10px;" data-toggle="modal" data-target="#addRoleModal">
            <i class="fas fa-plus mr-1"></i> <?= $isTr ? 'Yeni Rol Ekle' : 'Add New Role' ?>
        </button>
    </div>
</div>

<?php if ($mesaj): ?>
    <div class="alert alert-success border-0 shadow-sm mb-4" style="border-radius:12px;">
        <i class="fas fa-check-circle mr-2"></i><?= $mesaj ?>
    </div>
<?php endif; ?>
<?php if ($hata): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4" style="border-radius:12px;">
        <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($hata) ?>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Sol: Rol Listesi -->
    <div class="col-md-4 mb-4">
        <div class="card role-card shadow-sm">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="font-weight-bold text-dark mb-0"><?= $isTr ? 'Rol Listesi' : 'Roles List' ?></h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php if (empty($all_roles)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-info-circle mr-1"></i> <?= $isTr ? 'Henüz hiçbir rol tanımlanmadı.' : 'No roles defined yet.' ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($all_roles as $r): ?>
                            <button class="list-group-item list-group-item-action border-bottom d-flex justify-content-between align-items-center py-3 select-role-btn" 
                                    style="border-radius:0;" 
                                    data-id="<?= htmlspecialchars($r['id']) ?>" 
                                    data-name="<?= htmlspecialchars($r['role_name']) ?>"
                                    data-desc="<?= htmlspecialchars($r['description']) ?>"
                                    data-is-system="<?= isset($r['is_system']) ? '1' : '0' ?>"
                                    data-perms="<?= htmlspecialchars(json_encode($permissions[$r['id']] ?? [])) ?>">
                                <div>
                                    <h6 class="font-weight-bold <?= isset($r['is_system']) ? 'text-primary' : 'text-dark' ?> mb-1">
                                        <?php if(isset($r['is_system'])): ?><i class="fas fa-shield-alt mr-1"></i><?php endif; ?>
                                        <?= htmlspecialchars($r['role_name']) ?>
                                    </h6>
                                    <small class="text-muted"><?= htmlspecialchars($r['description'] ?: ($isTr ? 'Açıklama yok' : 'No description')) ?></small>
                                </div>
                                <span class="badge badge-pill badge-primary"><?= $r['user_count'] ?> <?= $isTr ? 'Kullanıcı' : 'Users' ?></span>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Sağ: İzin Matrisi / Seçili Rolün Detayları -->
    <div class="col-md-8 mb-4">
        <div class="card role-card shadow-sm d-none" id="permission-matrix-card">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="save_permissions">
                <input type="hidden" name="role_id" id="matrix_role_id">

                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="font-weight-bold text-primary mb-1" id="matrix-role-name"></h5>
                        <p class="text-muted small mb-0" id="matrix-role-desc"></p>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-success" style="border-radius:10px;">
                            <i class="fas fa-save mr-1"></i> <?= $isTr ? 'Yetkileri Kaydet' : 'Save Permissions' ?>
                        </button>
                        <button type="button" class="btn btn-outline-danger" id="delete-role-btn" style="border-radius:10px;" onclick="confirmDeleteRole()">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
                
                <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
                    <div class="list-group list-group-flush">
                        <?php foreach ($dynamic_routes as $route_key): ?>
                            <div class="route-perm-row p-3 border-bottom">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center">
                                        <div class="mr-3 text-muted" style="width:30px; text-align:center;">
                                            <i class="<?= getRouteIcon($route_key) ?> fa-lg"></i>
                                        </div>
                                        <div>
                                            <h6 class="font-weight-bold text-dark mb-0"><?= getRouteLabel($route_key, $isTr) ?></h6>
                                            <small class="text-muted" style="font-family: monospace;"><?= $route_key ?></small>
                                        </div>
                                    </div>
                                    <label class="ios-switch">
                                        <input type="checkbox" name="perms[]" value="<?= $route_key ?>" class="perm-checkbox">
                                        <span class="ios-slider"></span>
                                    </label>
                                </div>
                                
                                <?php if (isset($granular_perms[$route_key])): ?>
                                    <div class="pl-5 mt-3 border-left ml-3" style="border-color: #e2e8f0 !important;">
                                        <?php foreach ($granular_perms[$route_key] as $sub_key => $sub_label): ?>
                                            <div class="d-flex align-items-center justify-content-between mb-2">
                                                <div>
                                                    <span class="text-secondary small font-weight-bold"><i class="fas fa-caret-right mr-1"></i> <?= $sub_label[$isTr ? 'tr' : 'en'] ?></span>
                                                </div>
                                                <label class="ios-switch" style="transform: scale(0.8); margin: 0;">
                                                    <input type="checkbox" name="perms[]" value="<?= $sub_key ?>" class="perm-checkbox">
                                                    <span class="ios-slider"></span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
        </div>

        <div class="card role-card shadow-sm p-5 text-center my-auto" id="no-role-selected-msg" style="min-height:300px; display:flex; justify-content:center; align-items:center;">
            <i class="fas fa-user-shield fa-4x mb-3 text-light"></i>
            <h5 class="font-weight-bold text-muted"><?= $isTr ? 'Bir Rol Seçin' : 'Select a Role' ?></h5>
            <p class="text-muted small"><?= $isTr ? 'Sayfa yetkilerini ve izinlerini düzenlemek için sol taraftaki özel rollerden birine tıklayın.' : 'Click on one of the custom roles on the left to edit page authorizations and permissions.' ?></p>
        </div>
    </div>
</div>

<!-- Rol Ekleme Modalı -->
<div class="modal fade" id="addRoleModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius:20px; border:none;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title font-weight-bold text-dark"><i class="fas fa-plus-circle mr-2 text-primary"></i><?= $isTr ? 'Yeni Rol Tanımla' : 'Define New Role' ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="add_role">
                    
                    <div class="form-group">
                        <label class="small font-weight-bold text-muted"><?= $isTr ? 'Rol Adı' : 'Role Name' ?></label>
                        <input type="text" name="role_name" class="form-control" placeholder="<?= $isTr ? 'Örn: Sadece Lisans Görebilen Personel' : 'E.g: License Manager' ?>" style="border-radius:10px;" required>
                    </div>

                    <div class="form-group">
                        <label class="small font-weight-bold text-muted"><?= $isTr ? 'Açıklama' : 'Description' ?></label>
                        <textarea name="description" class="form-control" rows="3" placeholder="<?= $isTr ? 'Bu rolün görev tanımını yazın...' : 'Explain what this role is for...' ?>" style="border-radius:10px;"></textarea>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button type="button" class="btn btn-light mr-2" style="border-radius:10px;" data-dismiss="modal"><?= $isTr ? 'İptal' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;"><?= $isTr ? 'Oluştur' : 'Create' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Silme Onay Formu (Gizli) -->
<form id="deleteRoleForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="delete_role">
    <input type="hidden" name="role_id" id="delete_role_id">
</form>

<script>
    document.querySelectorAll('.select-role-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            // Aktif sınıfını güncelle
            document.querySelectorAll('.select-role-btn').forEach(b => b.classList.remove('active', 'bg-light'));
            this.classList.add('active', 'bg-light');

            // Detayları çek
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const desc = this.getAttribute('data-desc');
            const isSystem = this.getAttribute('data-is-system') === '1';
            const perms = JSON.parse(this.getAttribute('data-perms') || '[]');

            // Form alanlarını güncelle
            document.getElementById('matrix_role_id').value = id;
            document.getElementById('matrix-role-name').innerText = name;
            document.getElementById('matrix-role-desc').innerText = desc || '<?= $isTr ? "Açıklama belirtilmedi." : "No description specified." ?>';

            // Sistem rolleri silinemez
            if (isSystem) {
                document.getElementById('delete-role-btn').style.display = 'none';
            } else {
                document.getElementById('delete-role-btn').style.display = 'inline-block';
            }

            // Checkbox durumlarını güncelle
            document.querySelectorAll('.perm-checkbox').forEach(cb => {
                cb.checked = perms.includes(cb.value);
            });

            // Kartları göster/gizle
            document.getElementById('no-role-selected-msg').classList.add('d-none');
            document.getElementById('permission-matrix-card').classList.remove('d-none');
        });
    });

    <?php if (!empty($saved_role_id)): ?>
    window.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            const savedItem = document.querySelector('.role-item[data-id="<?= htmlspecialchars($saved_role_id) ?>"]');
            if (savedItem) savedItem.click();
        }, 100);
    });
    <?php endif; ?>

    function confirmDeleteRole() {
        const id = document.getElementById('matrix_role_id').value;
        const name = document.getElementById('matrix-role-name').innerText;
        if (!id) return;

        Swal.fire({
            title: '<?= $isTr ? "Emin misiniz?" : "Are you sure?" ?>',
            text: `"${name}" <?= $isTr ? "rolünü silmek istediğinize emin misiniz? Bu role sahip personellerin yetkileri sıfırlanacaktır." : "role will be deleted? Users assigned to this role will lose their authorizations." ?>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#cbd5e1',
            confirmButtonText: '<?= $isTr ? "Evet, Sil" : "Yes, Delete" ?>',
            cancelButtonText: '<?= $isTr ? "Vazgeç" : "Cancel" ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_role_id').value = id;
                document.getElementById('deleteRoleForm').submit();
            }
        });
    }
</script>
