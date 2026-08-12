<?php
// pages/tedarikci_detay.php
require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/asset_helpers.php";

$pdo = db();
$supplier_id = intval($_GET['id'] ?? 0);
$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

if ($supplier_id <= 0) {
    echo "<div class='alert alert-danger'>Geçersiz Tedarikçi ID</div>";
    return;
}

// Fetch Supplier Info
$stmt = $pdo->prepare("SELECT * FROM asset_suppliers WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    echo "<div class='alert alert-danger'>Tedarikçi bulunamadı.</div>";
    return;
}

// Fetch Linked Items counts
$counts = [
    'assets' => $pdo->query("SELECT COUNT(*) FROM assets WHERE supplier_id = $supplier_id AND deleted_at IS NULL")->fetchColumn(),
    'licenses' => $pdo->query("SELECT COUNT(*) FROM asset_licenses WHERE supplier_id = $supplier_id AND deleted_at IS NULL")->fetchColumn(),
    'accessories' => $pdo->query("SELECT COUNT(*) FROM asset_accessories WHERE supplier_id = $supplier_id AND deleted_at IS NULL")->fetchColumn(),
    'consumables' => $pdo->query("SELECT COUNT(*) FROM asset_consumables WHERE supplier_id = $supplier_id AND deleted_at IS NULL")->fetchColumn(),
    'components' => $pdo->query("SELECT COUNT(*) FROM asset_components WHERE supplier_id = $supplier_id AND deleted_at IS NULL")->fetchColumn(),
];

// Active Tab
$active_tab = $_GET['tab'] ?? 'info';
?>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0 font-weight-bold text-dark">
                    <i class="fas fa-truck-loading mr-2 text-primary"></i>
                    <?= htmlspecialchars($supplier['name']) ?>
                </h1>
            </div>
            <div class="col-sm-6 text-right">
                <a href="varliklar?view=predefined&type=suppliers" class="btn btn-default btn-sm shadow-sm rounded-pill px-3">
                    <i class="fas fa-arrow-left mr-1"></i> <?= $isTr ? 'Geri Dön' : 'Back' ?>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
        <!-- Supplier Brief Card -->
        <div class="col-md-3">
            <div class="card shadow-sm border-0" style="border-radius:15px;">
                <div class="card-body text-center pt-4">
                    <div class="mb-3">
                        <?php if ($supplier['image']): ?>
                            <img src="public/uploads/suppliers/<?= htmlspecialchars($supplier['image']) ?>" class="img-fluid rounded shadow-sm" style="max-height:120px;">
                        <?php else: ?>
                            <div class="bg-light d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" style="width:100px; height:100px;">
                                <i class="fas fa-building fa-3x text-muted opacity-50"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <h5 class="font-weight-bold mb-1"><?= htmlspecialchars($supplier['name']) ?></h5>
                    <p class="text-muted small mb-3"><?= $isTr ? 'Tedarikçi' : 'Supplier' ?></p>
                    
                    <div class="text-left border-top pt-3 mt-3">
                        <?php if ($supplier['contact_person']): ?>
                            <div class="small mb-2 text-muted">
                                <i class="fas fa-user-tie mr-2 text-primary"></i> <strong><?= $isTr ? 'İlgili Kişi' : 'Contact' ?>:</strong><br>
                                <span class="pl-4 text-dark"><?= htmlspecialchars($supplier['contact_person']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($supplier['phone']): ?>
                            <div class="small mb-2 text-muted">
                                <i class="fas fa-phone-alt mr-2 text-primary"></i> <strong><?= $isTr ? 'Telefon' : 'Phone' ?>:</strong><br>
                                <span class="pl-4 text-dark"><?= htmlspecialchars($supplier['phone']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($supplier['email']): ?>
                            <div class="small mb-2 text-muted">
                                <i class="fas fa-envelope mr-2 text-primary"></i> <strong><?= $isTr ? 'E-Posta' : 'Email' ?>:</strong><br>
                                <span class="pl-4 text-dark"><?= htmlspecialchars($supplier['email']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($supplier['website']): ?>
                            <div class="small mb-2 text-muted">
                                <i class="fas fa-globe mr-2 text-primary"></i> <strong><?= $isTr ? 'Web Sitesi' : 'Website' ?>:</strong><br>
                                <a href="<?= htmlspecialchars($supplier['website']) ?>" target="_blank" class="pl-4"><?= htmlspecialchars($supplier['website']) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="card shadow-sm border-0 mt-3" style="border-radius:15px;">
                <div class="card-header bg-transparent border-0 pt-3">
                    <h6 class="font-weight-bold mb-0"><?= $isTr ? 'Adres Bilgileri' : 'Address Info' ?></h6>
                </div>
                <div class="card-body pt-1">
                    <p class="small text-muted mb-0">
                        <i class="fas fa-map-marker-alt mr-2 text-danger"></i>
                        <?= htmlspecialchars($supplier['address'] ?: '—') ?>
                        <?php if ($supplier['city'] || $supplier['zip']): ?>
                            <br><span class="pl-4"><?= htmlspecialchars($supplier['zip'] . ' ' . $supplier['city']) ?></span>
                        <?php endif; ?>
                        <?php if ($supplier['country']): ?>
                            <br><span class="pl-4"><?= htmlspecialchars($supplier['country']) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Main Content (Tabs) -->
        <div class="col-md-9">
            <div class="card shadow-sm border-0" style="border-radius:15px; overflow:hidden;">
                <div class="card-header p-0 border-bottom-0">
                    <ul class="nav nav-tabs nav-tabs-custom" id="supplier-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link <?= $active_tab == 'info' ? 'active' : '' ?>" href="tedarikci-detay/<?= $supplier_id ?>?tab=info">
                                <i class="fas fa-info-circle mr-1"></i> <?= $isTr ? 'Genel Bakış' : 'Overview' ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $active_tab == 'assets' ? 'active' : '' ?>" href="tedarikci-detay/<?= $supplier_id ?>?tab=assets">
                                <i class="fas fa-laptop mr-1"></i> <?= $isTr ? 'Demirbaşlar' : 'Assets' ?> 
                                <span class="badge badge-pill badge-light ml-1"><?= $counts['assets'] ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $active_tab == 'licenses' ? 'active' : '' ?>" href="tedarikci-detay/<?= $supplier_id ?>?tab=licenses">
                                <i class="fas fa-key mr-1"></i> <?= $isTr ? 'Lisanslar' : 'Licenses' ?>
                                <span class="badge badge-pill badge-light ml-1"><?= $counts['licenses'] ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $active_tab == 'consumables' ? 'active' : '' ?>" href="tedarikci-detay/<?= $supplier_id ?>?tab=consumables">
                                <i class="fas fa-tint mr-1"></i> <?= $isTr ? 'Sarf Malzemeleri' : 'Consumables' ?>
                                <span class="badge badge-pill badge-light ml-1"><?= $counts['consumables'] ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $active_tab == 'accessories' ? 'active' : '' ?>" href="tedarikci-detay/<?= $supplier_id ?>?tab=accessories">
                                <i class="fas fa-keyboard mr-1"></i> <?= $isTr ? 'Aksesuarlar' : 'Accessories' ?>
                                <span class="badge badge-pill badge-light ml-1"><?= $counts['accessories'] ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $active_tab == 'components' ? 'active' : '' ?>" href="tedarikci-detay/<?= $supplier_id ?>?tab=components">
                                <i class="fas fa-microchip mr-1"></i> <?= $isTr ? 'Bileşenler' : 'Components' ?>
                                <span class="badge badge-pill badge-light ml-1"><?= $counts['components'] ?></span>
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="card-body p-0">
                    <div class="tab-content">
                        <!-- TAB: GENEL BAKIŞ -->
                        <div class="tab-pane fade <?= $active_tab == 'info' ? 'show active' : '' ?> p-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="font-weight-bold text-muted text-uppercase small mb-3"><?= $isTr ? 'Tedarikçi Notları' : 'Supplier Notes' ?></h6>
                                    <div class="p-3 bg-light rounded shadow-sm" style="min-height:100px;">
                                        <?= nl2br(htmlspecialchars($supplier['notes'] ?: ($isTr ? 'Henüz not eklenmemiş.' : 'No notes added.'))) ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="font-weight-bold text-muted text-uppercase small mb-3"><?= $isTr ? 'İstatistikler' : 'Statistics' ?></h6>
                                    <div class="list-group list-group-flush rounded border shadow-sm">
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="fas fa-calendar-alt mr-2"></i> <?= $isTr ? 'Eklenme Tarihi' : 'Date Added' ?></span>
                                            <span class="text-dark font-weight-bold"><?= date('d.m.Y', strtotime($supplier['created_at'])) ?></span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="fas fa-shopping-bag mr-2"></i> <?= $isTr ? 'Toplam Ürün Sayısı' : 'Total Items' ?></span>
                                            <span class="badge badge-primary badge-pill" style="font-size:14px;"><?= array_sum($counts) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: LISTS (Dynamic Table) -->
                        <div class="tab-pane fade <?= $active_tab !== 'info' ? 'show active' : '' ?>">
                            <div class="table-responsive">
                                <table class="table table-hover table-striped mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th><?= $isTr ? 'ID' : 'ID' ?></th>
                                            <th><?= $isTr ? 'Görsel' : 'Image' ?></th>
                                            <th><?= $isTr ? 'Adı / Ürün' : 'Name / Product' ?></th>
                                            <th><?= $isTr ? 'Model / Seri' : 'Model / Serial' ?></th>
                                            <th><?= $isTr ? 'Maliyet' : 'Cost' ?></th>
                                            <th><?= $isTr ? 'Tarih' : 'Date' ?></th>
                                            <th class="text-right"><?= $isTr ? 'İşlemler' : 'Actions' ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $view_map = [
                                            'assets' => ['table' => 'assets', 'name' => 'name', 'icon' => 'fa-laptop', 'redir' => 'assets'],
                                            'licenses' => ['table' => 'asset_licenses', 'name' => 'software_name', 'icon' => 'fa-key', 'redir' => 'licenses'],
                                            'consumables' => ['table' => 'asset_consumables', 'name' => 'name', 'icon' => 'fa-tint', 'redir' => 'consumables'],
                                            'accessories' => ['table' => 'asset_accessories', 'name' => 'name', 'icon' => 'fa-keyboard', 'redir' => 'accessories'],
                                            'components' => ['table' => 'asset_components', 'name' => 'name', 'icon' => 'fa-microchip', 'redir' => 'components']
                                        ];
                                        
                                        $current = $view_map[$active_tab] ?? null;
                                        if ($current):
                                            $table = $current['table'];
                                            $nCol = $current['name'];
                                            $sqlItems = "SELECT i.*, m.name as model_name, m.image as model_image 
                                                         FROM $table i 
                                                         LEFT JOIN asset_models m ON (1=0) -- Default join placeholder
                                                         ";
                                            if (tableHasColumn($pdo, $table, 'model_id')) {
                                                $sqlItems = "SELECT i.*, m.name as model_name, m.image as model_image FROM $table i LEFT JOIN asset_models m ON i.model_id = m.id ";
                                            }
                                            
                                            $sqlItems .= " WHERE i.supplier_id = $supplier_id AND i.deleted_at IS NULL ORDER BY i.id DESC";
                                            $items = $pdo->query($sqlItems)->fetchAll(PDO::FETCH_ASSOC);
                                            
                                            if (empty($items)):
                                        ?>
                                            <tr><td colspan="7" class="text-center py-5 text-muted"><?= $isTr ? 'Bu kategoride ürün bulunamadı.' : 'No items found in this category.' ?></td></tr>
                                        <?php 
                                            else:
                                                foreach ($items as $itm):
                                                    $img = $itm['image'] ?? $itm['model_image'] ?? '';
                                                    $displayImg = "https://ui-avatars.com/api/?name=" . urlencode($itm[$nCol]) . "&background=f1f5f9&color=64748b";
                                                    if ($img) {
                                                        $displayImg = "public/uploads/" . (strpos($img, 'models-') === 0 ? 'models' : $current['redir']) . "/" . $img;
                                                    }
                                        ?>
                                            <tr>
                                                <td><?= $itm['id'] ?></td>
                                                <td><img src="<?= $displayImg ?>" style="width:35px; height:35px; object-fit:contain; border-radius:4px; border:1px solid #eee;"></td>
                                                <td class="font-weight-bold text-dark"><?= htmlspecialchars($itm[$nCol]) ?></td>
                                                <td class="small">
                                                    <?= htmlspecialchars($itm['model_name'] ?? $itm['model_no'] ?? '—') ?><br>
                                                    <span class="text-muted"><?= htmlspecialchars($itm['serial_no'] ?? $itm['license_key'] ?? '—') ?></span>
                                                </td>
                                                <td class="font-weight-bold">
                                                    <?= number_format((float)($itm['purchase_cost'] ?? 0), 2) ?> <?= $itm['purchase_currency'] ?? 'TRY' ?>
                                                </td>
                                                <td class="small text-muted"><?= !empty($itm['purchase_date']) ? date('d.m.Y', strtotime($itm['purchase_date'])) : '—' ?></td>
                                                <td class="text-right">
                                                    <a href="varlik-detay/<?= $itm['id'] ?>?view=<?= $current['redir'] ?>" class="btn btn-xs btn-info rounded-circle p-2 shadow-sm" title="<?= $isTr ? 'Detaya Git' : 'View Details' ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php 
                                                endforeach;
                                            endif;
                                        endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.nav-tabs-custom {
    background: #f8fafc;
    border-bottom: 2px solid #edf2f7;
}
.nav-tabs-custom .nav-link {
    border: none;
    border-bottom: 2px solid transparent;
    padding: 12px 20px;
    color: #718096;
    font-weight: 600;
    font-size: 14px;
    transition: 0.3s;
}
.nav-tabs-custom .nav-link:hover {
    color: var(--primary-color);
    background: rgba(var(--primary-color-rgb), 0.05);
}
.nav-tabs-custom .nav-link.active {
    background: transparent !important;
    border-bottom-color: var(--primary-color) !important;
    color: var(--primary-color) !important;
}
.bg-primary-soft { background-color: rgba(0, 123, 255, 0.1); }
</style>
