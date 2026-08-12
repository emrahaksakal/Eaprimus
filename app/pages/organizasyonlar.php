<?php
// pages/organizasyonlar.php

require_once __DIR__ . '/../includes/session.php';
requireLogin();

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

$current_user_role = $_SESSION['role'];

// Yetki kontrolü (Admin görebilir)
if ($current_user_role != 1) {
    echo '<div class="alert alert-danger m-3">' . __('no_permission') . '</div>';
    return;
}

$hata = '';
$mesaj = '';

// Arama ve sayfalama
$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// --- AJAX İŞLEMLERİ (KAYDET / GÜNCELLE / SİL) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        if ($action === 'save_org') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $domain = trim($_POST['domain'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $tax_office = trim($_POST['tax_office'] ?? '');
            $tax_number = trim($_POST['tax_number'] ?? '');
            $website = trim($_POST['website'] ?? '');

            if (empty($name)) {
                echo json_encode(['status' => 'error', 'message' => __('fill_required_fields')]);
                exit;
            }

            $logoPath = null;
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $logoName = 'org_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    $uploadDir = __DIR__ . '/../../public/uploads/orgs/';
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $logoName)) {
                        $logoPath = $logoName;
                    }
                }
            }

            if ($id > 0) {
                if ($logoPath) {
                    $stmt = $pdo->prepare("UPDATE organizations SET name = ?, domain = ?, notes = ?, phone = ?, email = ?, address = ?, tax_office = ?, tax_number = ?, website = ?, logo = ? WHERE id = ?");
                    $stmt->execute([$name, $domain, $notes, $phone, $email, $address, $tax_office, $tax_number, $website, $logoPath, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE organizations SET name = ?, domain = ?, notes = ?, phone = ?, email = ?, address = ?, tax_office = ?, tax_number = ?, website = ? WHERE id = ?");
                    $stmt->execute([$name, $domain, $notes, $phone, $email, $address, $tax_office, $tax_number, $website, $id]);
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO organizations (name, domain, notes, phone, email, address, tax_office, tax_number, website, logo, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$name, $domain, $notes, $phone, $email, $address, $tax_office, $tax_number, $website, $logoPath]);
                $id = $pdo->lastInsertId();
            }

            // --- Dinamik Alanları Kaydet ---
            $custom_fields = $_POST['custom'] ?? [];
            foreach ($custom_fields as $field_id => $value) {
                // Önce varsa sil
                $pdo->prepare("DELETE FROM customer_field_values WHERE organization_id = ? AND field_id = ?")
                    ->execute([$id, $field_id]);
                // Ekle
                if ($value !== '') {
                    $pdo->prepare("INSERT INTO customer_field_values (organization_id, field_id, value) VALUES (?, ?, ?)")
                        ->execute([$id, $field_id, $value]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => __('action_success')]);
            exit;
        }

        if ($action === 'delete_org') {
            $id = (int) $_POST['id'];
            $pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => __('customer_deleted_success')]);
            exit;
        }

        if ($action === 'get_org') {
            $id = (int) $_POST['id'];
            $orgBuf = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
            $orgBuf->execute([$id]);
            $data = $orgBuf->fetch(PDO::FETCH_ASSOC);

            // Dinamik değerleri çek
            $values = $pdo->prepare("SELECT field_id, value FROM customer_field_values WHERE organization_id = ?");
            $values->execute([$id]);
            $data['custom_values'] = $values->fetchAll(PDO::FETCH_KEY_PAIR);

            echo json_encode(['status' => 'success', 'data' => $data]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Organizasyon listesini getir
$orgs = [];
try {
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = "WHERE (name LIKE ? OR domain LIKE ? OR notes LIKE ?)";
        $like = "%$q%";
        $params = [$like, $like, $like];
    }

    $countSql = "SELECT COUNT(*) FROM organizations " . $where;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT o.*, 
               (SELECT COUNT(id) FROM customers WHERE organization_id = o.id) AS contact_count,
               (SELECT COUNT(id) FROM tickets WHERE organization_id = o.id) AS ticket_count
            FROM organizations o
            " . $where . "
            ORDER BY o.name ASC
            LIMIT $perPage OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $hata = $e->getMessage();
}

// Organizasyon scope'lu özel alanları getir
$customFields = $pdo->query("SELECT * FROM customer_fields WHERE target_type = 'organization' ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
/* Right Drawer */
.side-drawer {
    position: fixed;
    top: 0;
    right: -500px;
    width: 500px;
    height: 100vh;
    background: #fff;
    box-shadow: -5px 0 15px rgba(0,0,0,0.1);
    z-index: 1051;
    transition: right 0.3s cubic-bezier(0.7, 0, 0.3, 1);
    display: flex;
    flex-direction: column;
}
.side-drawer.open { right: 0; }
.side-drawer-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.3);
    z-index: 1050;
    display: none;
    backdrop-filter: blur(2px);
}
.side-drawer-header { padding: 1.5rem; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; background: var(--primary-color); color: #fff; }
.side-drawer-body { padding: 1.5rem; overflow-y: auto; flex-grow: 1; }
.side-drawer-footer { padding: 1.5rem; border-top: 1px solid #eee; background: #f8f9fa; }
body.dark-mode .side-drawer { background: #343a40; color: #fff; }
body.dark-mode .side-drawer-header { background: #212529; }
body.dark-mode .side-drawer-footer { background: #2c3136; }
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 text-dark"><?= __('organizations') ?></h1>
            </div>
            <div class="col-sm-6 text-right">
                <button onclick="openOrgDrawer()" class="btn btn-primary shadow-sm">
                    <i class="fas fa-plus mr-1"></i> <?= __('add_organization') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm m-3">
    <div class="card-header border-0 bg-white d-flex align-items-center py-3">
        <h3 class="card-title text-bold"><i class="fas fa-building mr-2 text-primary"></i> <?= __('organization_list') ?></h3>
        <div class="card-tools ml-auto">
            <form method="get" class="form-inline">
                <input type="hidden" name="route" value="organizasyonlar">
                <div class="input-group input-group-sm rounded-pill border overflow-hidden" style="width: 300px;">
                    <input type="text" name="q" class="form-control border-0" placeholder="<?= __('search') ?>..." value="<?= htmlspecialchars($q) ?>">
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-white"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="card-body p-0 text-nowrap table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th style="width: 40%"><?= __('organization_name') ?></th>
                    <th><?= __('domain') ?></th>
                    <th class="text-center"><?= __('contacts') ?></th>
                    <th class="text-center"><?= __('tickets') ?></th>
                    <th class="text-right"><?= __('actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orgs)): ?>
                    <tr><td colspan="5" class="text-center py-5 text-muted"><?= __('no_organizations_found') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($orgs as $o): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if(!empty($o['logo'])): ?>
                                    <img src="public/uploads/orgs/<?= htmlspecialchars($o['logo']) ?>" class="mr-3 rounded border" style="width:38px; height:38px; object-fit:cover;">
                                    <?php else: ?>
                                    <div class="avatar-sm bg-primary-soft text-primary mr-3 rounded-circle d-flex align-items-center justify-content-center" style="width:38px; height:38px; background: rgba(var(--primary-color-rgb), 0.1);">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="text-bold"><?= htmlspecialchars($o['name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars(mb_substr($o['notes'] ?? '', 0, 50)) ?>...</small>
                                    </div>
                                </div>
                            </td>
                            <td><code><?= htmlspecialchars($o['domain'] ?: '-') ?></code></td>
                            <td class="text-center"><span class="badge badge-light border"><?= $o['contact_count'] ?></span></td>
                            <td class="text-center"><span class="badge badge-info"><?= $o['ticket_count'] ?></span></td>
                            <td class="text-right">
                                <button onclick="editOrg(<?= $o['id'] ?>)" class="btn btn-sm btn-outline-primary mr-1"><i class="fas fa-edit"></i></button>
                                <button onclick="deleteOrg(<?= $o['id'] ?>, '<?= addslashes($o['name']) ?>')" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > $perPage): ?>
    <div class="card-footer bg-white border-0">
        <div class="float-right text-muted small">
            <?= $page ?> / <?= ceil($total / $perPage) ?> Sayfa
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Drawer Overlay -->
<div class="side-drawer-overlay" id="drawerOverlay" onclick="closeOrgDrawer()"></div>

<!-- Organization Drawer -->
<div class="side-drawer" id="orgDrawer">
    <div class="side-drawer-header">
        <h4 class="mb-0" id="drawerTitle"><?= __('add_organization') ?></h4>
        <button onclick="closeOrgDrawer()" class="btn btn-link text-white p-0"><i class="fas fa-times fa-lg"></i></button>
    </div>
    <div class="side-drawer-body">
        <form id="orgForm" enctype="multipart/form-data">
            <input type="hidden" name="id" id="org_id">
            <input type="hidden" name="action" value="save_org">
            <input type="hidden" name="ajax_action" value="1">

            <div class="form-group text-center mb-4">
                <div id="org_logo_preview" class="mb-3" style="display:none;">
                    <img src="" style="width: 100px; height: 100px; border-radius: 50%; border: 3px solid #f1f3f5; padding: 2px; background:#fff; object-fit: cover; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                </div>
                <div class="custom-file" style="text-align: left;">
                    <input type="file" class="custom-file-input" name="logo" id="org_logo" accept="image/*">
                    <label class="custom-file-label" for="org_logo"><?= __('logo') ?></label>
                </div>
            </div>

            <div class="form-group">
                <label class="font-weight-bold text-muted small text-uppercase"><?= __('organization_name') ?> *</label>
                <input type="text" name="name" id="org_name" class="form-control form-control-lg border-0 bg-light" placeholder="Örn: ACME Gıda Ltd. Şti." required>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold text-muted small text-uppercase"><?= __('tax_office') ?></label>
                    <input type="text" name="tax_office" id="org_tax_office" class="form-control bg-light border-0">
                </div>
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold text-muted small text-uppercase"><?= __('tax_number') ?></label>
                    <input type="text" name="tax_number" id="org_tax_number" class="form-control bg-light border-0">
                </div>
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold text-muted small text-uppercase"><?= __('phone') ?></label>
                    <input type="text" name="phone" id="org_phone" class="form-control bg-light border-0">
                </div>
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold text-muted small text-uppercase"><?= __('email') ?></label>
                    <input type="email" name="email" id="org_email" class="form-control bg-light border-0">
                </div>
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold text-muted small text-uppercase"><?= __('domain') ?></label>
                    <input type="text" name="domain" id="org_domain" class="form-control bg-light border-0" placeholder="acme.com">
                </div>
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold text-muted small text-uppercase"><?= __('website') ?></label>
                    <input type="text" name="website" id="org_website" class="form-control bg-light border-0" placeholder="https://...">
                </div>
            </div>

            <div class="form-group">
                <label class="font-weight-bold text-muted small text-uppercase"><?= __('address') ?></label>
                <textarea name="address" id="org_address" class="form-control bg-light border-0" rows="2"></textarea>
            </div>

            <div class="form-group">
                <label class="font-weight-bold text-muted small text-uppercase"><?= __('notes') ?></label>
                <textarea name="notes" id="org_notes" class="form-control bg-light border-0" rows="2"></textarea>
            </div>

            <hr>
            <h5><i class="fas fa-tags mr-2"></i> <?= __('org_fields') ?></h5>
            <div id="dynamicFieldsContainer" class="mt-3">
                <?php foreach ($customFields as $cf): ?>
                    <div class="form-group">
                        <label class="font-weight-bold"><?= htmlspecialchars($cf['label']) ?> <?= $cf['required'] ? '*' : '' ?></label>
                        <?php if ($cf['field_type'] === 'textarea'): ?>
                            <textarea name="custom[<?= $cf['id'] ?>]" id="cf_<?= $cf['id'] ?>" class="form-control" <?= $cf['required'] ? 'required' : '' ?>></textarea>
                        <?php elseif ($cf['field_type'] === 'dropdown'): ?>
                            <select name="custom[<?= $cf['id'] ?>]" id="cf_<?= $cf['id'] ?>" class="form-control" <?= $cf['required'] ? 'required' : '' ?>>
                                <option value="">-- Seçiniz --</option>
                                <?php foreach (explode(',', $cf['options']) as $opt): ?>
                                    <option value="<?= trim($opt) ?>"><?= trim($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($cf['field_type'] === 'checkbox'): ?>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" name="custom[<?= $cf['id'] ?>]" value="1" id="cf_<?= $cf['id'] ?>" class="custom-control-input">
                                <label class="custom-control-label" for="cf_<?= $cf['id'] ?>"><?= __('active') ?></label>
                            </div>
                        <?php else: ?>
                            <input type="<?= ($cf['field_type'] === 'number') ? 'number' : (($cf['field_type'] === 'date') ? 'date' : 'text') ?>" 
                                   name="custom[<?= $cf['id'] ?>]" id="cf_<?= $cf['id'] ?>" class="form-control" 
                                   <?= $cf['required'] ? 'required' : '' ?> 
                                   placeholder="<?= ($cf['field_type'] === 'ip') ? '0.0.0.0' : '' ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($customFields)): ?>
                    <p class="text-muted small italic"><?= __('no_fields_found') ?></p>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="side-drawer-footer">
        <button type="button" onclick="saveOrg()" class="btn btn-primary btn-block p-2 shadow-sm">
            <i class="fas fa-save mr-1"></i> <?= __('save') ?>
        </button>
        <button type="button" onclick="closeOrgDrawer()" class="btn btn-link btn-block text-muted"><?= __('cancel') ?></button>
    </div>
</div>

<script>
function openOrgDrawer() {
    $('#orgForm')[0].reset();
    $('#org_id').val('');
    $('#org_logo_preview').hide();
    $('.custom-file-label').html('<?= __('logo') ?? 'Logo Yükle' ?>');
    $('#drawerTitle').text('<?= __('add_organization') ?>');
    $('#orgDrawer').addClass('open');
    $('#drawerOverlay').fadeIn();
}

function closeOrgDrawer() {
    $('#orgDrawer').removeClass('open');
    $('#drawerOverlay').fadeOut();
}

function editOrg(id) {
    $.post('anasayfa?route=organizasyonlar', { action: 'get_org', id: id, ajax_action: 1 }, function(res) {
        if(res.status === 'success') {
            $('#drawerTitle').text('<?= __('edit') ?>: ' + res.data.name);
            $('#org_id').val(res.data.id);
            $('#org_name').val(res.data.name);
            $('#org_domain').val(res.data.domain);
            $('#org_notes').val(res.data.notes);
            $('#org_phone').val(res.data.phone || '');
            $('#org_email').val(res.data.email || '');
            $('#org_address').val(res.data.address || '');
            $('#org_tax_office').val(res.data.tax_office || '');
            $('#org_tax_number').val(res.data.tax_number || '');
            $('#org_website').val(res.data.website || '');
            
            if (res.data.logo) {
                $('#org_logo_preview img').attr('src', 'public/uploads/orgs/' + res.data.logo);
                $('#org_logo_preview').show();
            } else {
                $('#org_logo_preview').hide();
            }
            
            // Dinamik alanları doldur
            const vals = res.data.custom_values;
            $('input[name^="custom"], textarea[name^="custom"], select[name^="custom"]').each(function() {
                const nameMatch = $(this).attr('name').match(/\[(\d+)\]/);
                if (nameMatch) {
                    const fid = nameMatch[1];
                    if (vals[fid]) {
                        if ($(this).attr('type') === 'checkbox') {
                            $(this).prop('checked', vals[fid] == '1');
                        } else {
                            $(this).val(vals[fid]);
                        }
                    } else {
                        if ($(this).attr('type') === 'checkbox') $(this).prop('checked', false);
                        else $(this).val('');
                    }
                }
            });

            $('#orgDrawer').addClass('open');
            $('#drawerOverlay').fadeIn();
        } else {
            Swal.fire('Hata', res.message, 'error');
        }
    });
}

function saveOrg() {
    const formEl = document.getElementById('orgForm');
    const formData = new FormData(formEl);
    $.ajax({
        url: 'anasayfa?route=organizasyonlar',
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                closeOrgDrawer();
                Swal.fire('Başarılı', res.message, 'success').then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Hata', res.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Hata', 'Sunucu ile iletişim kurulamadı.', 'error');
        }
    });
}

function deleteOrg(id, name) {
    Swal.fire({
        title: '<?= __("are_you_sure") ?>',
        text: '<?= __("delete_org_confirm") ?>'.replace(':name', name),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: '<?= __("yes_delete") ?>',
        cancelButtonText: '<?= __("cancel") ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('anasayfa?route=organizasyonlar', { action: 'delete_org', id: id, ajax_action: 1 }, function(res) {
                if (res.status === 'success') {
                    location.reload();
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            });
        }
    });
}
</script>
