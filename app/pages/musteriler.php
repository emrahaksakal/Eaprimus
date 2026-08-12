<?php
// pages/musteriler.php

require_once __DIR__ . '/../includes/session.php';
requireLogin();

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

$current_user_role = $_SESSION['role'];

if ($current_user_role != 1 && $current_user_role != 3) {
    echo '<div class="alert alert-danger m-3">' . __('no_permission') . '</div>';
    return;
}

$hata = '';

// --- AJAX İŞLEMLERİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        if ($action === 'save_contact') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $org_id = ($_POST['organization_id'] !== '') ? (int) $_POST['organization_id'] : null;
            $phone = trim($_POST['phone'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if (empty($email)) {
                echo json_encode(['status' => 'error', 'message' => __('email_required')]);
                exit;
            }

            // Email benzersizliği
            // Email benzersizliği
            $stCheck = $pdo->prepare("SELECT id FROM customers WHERE email = ? AND id != ?");
            $stCheck->execute([$email, $id]);
            if ($stCheck->fetchColumn()) {
                echo json_encode(['status' => 'error', 'message' => __('email_already_exists')]);
                exit;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE customers SET name = ?, email = ?, organization_id = ?, phone = ?, notes = ? WHERE id = ?");
                $stmt->execute([$name, $email, $org_id, $phone, $notes, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO customers (name, email, organization_id, phone, notes, source, created_at) VALUES (?, ?, ?, ?, ?, 'admin', NOW())");
                $stmt->execute([$name, $email, $org_id, $phone, $notes]);
                $id = $pdo->lastInsertId();
            }

            // Avatar Upload
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../../public/uploads/avatars/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (in_array($ext, $allowed)) {
                    $newFileName = 'cust_' . $id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $newFileName)) {
                        $pdo->prepare("UPDATE customers SET avatar = ? WHERE id = ?")->execute([$newFileName, $id]);
                    }
                }
            }

            // --- Dinamik Alanları Kaydet ---
            $custom_fields = $_POST['custom'] ?? [];
            foreach ($custom_fields as $field_id => $value) {
                $pdo->prepare("DELETE FROM customer_field_values WHERE customer_id = ? AND field_id = ?")
                    ->execute([$id, $field_id]);
                if ($value !== '') {
                    $pdo->prepare("INSERT INTO customer_field_values (customer_id, field_id, value) VALUES (?, ?, ?)")
                        ->execute([$id, $field_id, $value]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => __('action_success')]);
            exit;
        }

        if ($action === 'get_contact') {
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $st->execute([$id]);
            $data = $st->fetch(PDO::FETCH_ASSOC);

            $values = $pdo->prepare("SELECT field_id, value FROM customer_field_values WHERE customer_id = ?");
            $values->execute([$id]);
            $data['custom_values'] = $values->fetchAll(PDO::FETCH_KEY_PAIR);

            // Bu müşteriye ait özel alanları da getir
            $stFields = $pdo->prepare("SELECT * FROM customer_fields WHERE target_type = 'contact' AND (customer_ids IS NULL OR customer_ids = '' OR FIND_IN_SET(?, customer_ids)) ORDER BY sort_order ASC");
            $stFields->execute([$id]);
            $data['relevant_fields'] = $stFields->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $data]);
            exit;
        }

        if ($action === 'delete_contact') {
            $id = (int) $_POST['id'];
            $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => __('customer_deleted_success')]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Arama ve sayfalama parametreleri
$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$customers = [];
try {
    $where = 'WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $where = "WHERE (c.name LIKE ? OR c.email LIKE ? OR o.name LIKE ? OR c.phone LIKE ?)";
        $like = "%$q%";
        $params = [$like, $like, $like, $like];
    }

    $countSql = "SELECT COUNT(*) FROM customers c LEFT JOIN organizations o ON c.organization_id = o.id " . $where;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT c.id, c.name, c.email, c.phone, c.avatar, o.name as org_name, o.id as org_id,
               (SELECT COUNT(id) FROM tickets WHERE customer_id = c.id) AS ticket_count,
               (SELECT MAX(created_at) FROM tickets WHERE customer_id = c.id) AS last_ticket_date
            FROM customers c
            LEFT JOIN organizations o ON c.organization_id = o.id
            " . $where . "
            ORDER BY c.id DESC
            LIMIT $perPage OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['ajax_search'])) {
        ob_clean();
        if (empty($customers)) {
            echo '<tr><td colspan="6" class="text-center py-5 text-muted">' . __('no_customers_found') . '</td></tr>';
        } else {
            foreach ($customers as $c) {
                $avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($c['name'] ?: ($c['email'] ?? 'C')) . '&background=3b82f6&color=fff&size=40&bold=true';
                if (!empty($c['avatar']) && file_exists(__DIR__ . '/../../public/uploads/avatars/' . $c['avatar'])) {
                    $avatarUrl = '/public/uploads/avatars/' . $c['avatar'];
                }
                echo '<tr>';
                echo '<td>';
                echo '<div class="d-flex align-items-center">';
                echo '<img src="' . htmlspecialchars($avatarUrl) . '" alt="Avatar" class="rounded-circle mr-3 shadow-sm" style="width:40px; height:40px; object-fit:cover;">';
                echo '<div>';
                echo '<div class="text-bold text-dark">' . htmlspecialchars($c['name'] ?: '-') . '</div>';
                echo '<small class="text-muted"><i class="far fa-clock mr-1"></i>' . ($c['last_ticket_date'] ? htmlspecialchars($c['last_ticket_date']) : '-') . '</small>';
                echo '</div></div></td>';
                echo '<td>' . htmlspecialchars($c['email']) . '</td>';
                echo '<td>' . ($c['org_id'] ? '<span class="badge badge-light p-2 border"><i class="fas fa-building mr-1 text-primary"></i> ' . htmlspecialchars($c['org_name']) . '</span>' : '<span class="text-muted italic">--</span>') . '</td>';
                echo '<td>' . htmlspecialchars($c['phone'] ?: '-') . '</td>';
                echo '<td class="text-center"><span class="badge badge-info">' . $c['ticket_count'] . '</span></td>';
                echo '<td class="text-right">';
                echo '<a href="musteri-detay/' . $c['id'] . '" class="btn btn-sm btn-outline-info mr-1" title="' . __('view') . '"><i class="fas fa-eye"></i></a>';
                echo '<button onclick="editContact(' . $c['id'] . ')" class="btn btn-sm btn-outline-primary mr-1"><i class="fas fa-edit"></i></button>';
                echo '<button onclick="deleteContact(' . $c['id'] . ', \'' . addslashes($c['email']) . '\')" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>';
                echo '</td></tr>';
            }
        }
        exit;
    }
} catch (PDOException $e) {
    $hata = $e->getMessage();
}

// Veriler (Selectler için)
$orgList = $pdo->query("SELECT id, name FROM organizations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$customFields = $pdo->query("SELECT * FROM customer_fields WHERE target_type = 'contact' AND (customer_ids IS NULL OR customer_ids = '') ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
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
                <h1 class="m-0 text-dark"><?= __('contacts') ?></h1>
            </div>
            <div class="col-sm-6 text-right">
                <button onclick="openContactDrawer()" class="btn btn-primary shadow-sm">
                    <i class="fas fa-plus mr-1"></i> <?= __('add_customer') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm m-3">
    <div class="card-header border-0 bg-white d-flex align-items-center py-3">
        <h3 class="card-title text-bold"><i class="fas fa-users mr-2 text-primary"></i> <?= __('contacts') ?></h3>
        <div class="card-tools ml-auto">
            <form method="get" class="form-inline" id="searchForm">
                <input type="hidden" name="route" value="musteriler">
                <div class="input-group input-group-sm rounded-pill border overflow-hidden" style="width: 300px;">
                    <input type="text" name="q" id="liveSearchInput" class="form-control border-0" placeholder="<?= __('customer_search_placeholder') ?>" value="<?= htmlspecialchars($q) ?>">
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
                    <th><?= __('full_name') ?></th>
                    <th><?= __('email') ?></th>
                    <th><?= __('organization') ?></th>
                    <th><?= __('phone') ?></th>
                    <th class="text-center"><?= __('tickets') ?></th>
                    <th class="text-right"><?= __('action') ?></th>
                </tr>
            </thead>
            <tbody id="customersTableBody">
                <?php if (empty($customers)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted"><?= __('no_customers_found') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): 
                        $avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($c['name'] ?: ($c['email'] ?? 'C')) . '&background=3b82f6&color=fff&size=40&bold=true';
                        if (!empty($c['avatar']) && file_exists(__DIR__ . '/../../public/uploads/avatars/' . $c['avatar'])) {
                            $avatarUrl = '/public/uploads/avatars/' . $c['avatar'];
                        }
                    ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="<?= $avatarUrl ?>" alt="Avatar" class="rounded-circle mr-3 shadow-sm" style="width:40px; height:40px; object-fit:cover;">
                                    <div>
                                        <div class="text-bold text-dark"><?= htmlspecialchars($c['name'] ?: '-') ?></div>
                                        <small class="text-muted"><i class="far fa-clock mr-1"></i><?= $c['last_ticket_date'] ? htmlspecialchars($c['last_ticket_date']) : '-' ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($c['email']) ?></td>
                            <td>
                                <?php if($c['org_id']): ?>
                                    <span class="badge badge-light p-2 border"><i class="fas fa-building mr-1 text-primary"></i> <?= htmlspecialchars($c['org_name']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted italic">--</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($c['phone'] ?: '-') ?></td>
                            <td class="text-center"><span class="badge badge-info"><?= $c['ticket_count'] ?></span></td>
                            <td class="text-right">
                                <a href="musteri-detay/<?= $c['id'] ?>" class="btn btn-sm btn-outline-info mr-1" title="<?= __('view') ?>"><i class="fas fa-eye"></i></a>
                                <button onclick="editContact(<?= $c['id'] ?>)" class="btn btn-sm btn-outline-primary mr-1"><i class="fas fa-edit"></i></button>
                                <button onclick="deleteContact(<?= $c['id'] ?>, '<?= addslashes($c['email']) ?>')" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Drawer Overlay -->
<div class="side-drawer-overlay" id="drawerOverlay" onclick="closeContactDrawer()"></div>

<!-- Contact Drawer -->
<div class="side-drawer" id="contactDrawer">
    <div class="side-drawer-header">
        <h4 class="mb-0" id="drawerTitle"><?= __('add_customer') ?></h4>
        <button onclick="closeContactDrawer()" class="btn btn-link text-white p-0"><i class="fas fa-times fa-lg"></i></button>
    </div>
    <div class="side-drawer-body">
        <form id="contactForm">
            <input type="hidden" name="id" id="contact_id">
            <input type="hidden" name="action" value="save_contact">
            <input type="hidden" name="ajax_action" value="1">

            <div class="form-group">
                <label><?= __('full_name') ?></label>
                <input type="text" name="name" id="contact_name" class="form-control" placeholder="Örn: Ali Yılmaz">
            </div>

            <div class="form-group">
                <label>Profil Resmi / Logo (Opsiyonel)</label>
                <input type="file" name="avatar" id="contact_avatar" class="form-control-file" accept="image/*">
                <small class="text-muted">JPG, PNG, GIF, WEBP formatları desteklenir.</small>
            </div>

            <div class="form-group">
                <label><?= __('email_address') ?> *</label>
                <input type="email" name="email" id="contact_email" class="form-control" placeholder="ali@firma.com" required>
            </div>

            <div class="form-group">
                <label><?= __('organization') ?></label>
                <select name="organization_id" id="contact_org_id" class="form-control select2">
                    <option value=""><?= __('select_org_optional') ?></option>
                    <?php foreach ($orgList as $o): ?>
                        <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted"><?= __('auto_org_suggest') ?></small>
            </div>

            <div class="form-group">
                <label><?= __('phone') ?></label>
                <input type="tel" name="phone" id="contact_phone" class="form-control" placeholder="+90 5xx ...">
            </div>

            <div class="form-group">
                <label><?= __('notes') ?></label>
                <textarea name="notes" id="contact_notes" class="form-control" rows="3"></textarea>
            </div>

            <hr>
            <h5><i class="fas fa-tags mr-2"></i> <?= __('custom_fields') ?></h5>
            <div id="dynamicFieldsContainer" class="mt-3">
                <?php foreach ($customFields as $cf): ?>
                    <div class="form-group">
                        <label><?= htmlspecialchars($cf['label']) ?> <?= $cf['required'] ? '*' : '' ?></label>
                        <?php if ($cf['field_type'] === 'textarea'): ?>
                            <textarea name="custom[<?= $cf['id'] ?>]" id="cf_<?= $cf['id'] ?>" class="form-control" <?= $cf['required'] ? 'required' : '' ?>></textarea>
                        <?php elseif ($cf['field_type'] === 'dropdown'): ?>
                            <select name="custom[<?= $cf['id'] ?>]" id="cf_<?= $cf['id'] ?>" class="form-control" <?= $cf['required'] ? 'required' : '' ?>>
                                <option value="">-- <?= __('select') ?> --</option>
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
            </div>
        </form>
    </div>
    <div class="side-drawer-footer">
        <button type="button" onclick="saveContact()" class="btn btn-primary btn-block p-2 shadow-sm">
            <i class="fas fa-save mr-1"></i> <?= __('save') ?>
        </button>
        <button type="button" onclick="closeContactDrawer()" class="btn btn-link btn-block text-muted"><?= __('cancel') ?></button>
    </div>
</div>

<script>
const initialFields = <?= json_encode($customFields) ?>;

function renderDynamicFields(fields, values) {
    let html = '';
    if (!fields || fields.length === 0) {
        html = '<p class="text-muted small italic"><?= __('no_fields_found') ?></p>';
    } else {
        fields.forEach(cf => {
            let val = values[cf.id] || '';
            let requiredAttr = cf.required == 1 ? 'required' : '';
            let requiredStar = cf.required == 1 ? '*' : '';
            
            html += `<div class="form-group">
                        <label>${cf.label} ${requiredStar}</label>`;
            
            if (cf.field_type === 'textarea') {
                html += `<textarea name="custom[${cf.id}]" id="cf_${cf.id}" class="form-control" ${requiredAttr}>${val}</textarea>`;
            } else if (cf.field_type === 'dropdown') {
                html += `<select name="custom[${cf.id}]" id="cf_${cf.id}" class="form-control" ${requiredAttr}>
                            <option value="">-- <?= __('select') ?> --</option>`;
                (cf.options || '').split(',').forEach(opt => {
                    let trimmed = opt.trim();
                    let selected = (val == trimmed) ? 'selected' : '';
                    html += `<option value="${trimmed}" ${selected}>${trimmed}</option>`;
                });
                html += `</select>`;
            } else if (cf.field_type === 'checkbox') {
                let checked = val == '1' ? 'checked' : '';
                html += `<div class="custom-control custom-checkbox">
                            <input type="checkbox" name="custom[${cf.id}]" value="1" id="cf_${cf.id}" class="custom-control-input" ${checked}>
                            <label class="custom-control-label" for="cf_${cf.id}"><?= __('active') ?></label>
                        </div>`;
            } else {
                let typeAttr = (cf.field_type === 'number') ? 'number' : ((cf.field_type === 'date') ? 'date' : 'text');
                html += `<input type="${typeAttr}" name="custom[${cf.id}]" id="cf_${cf.id}" class="form-control" ${requiredAttr} value="${val}" placeholder="${cf.field_type === 'ip' ? '0.0.0.0' : ''}">`;
            }
            html += `</div>`;
        });
    }
    $('#dynamicFieldsContainer').html(html);
}

function openContactDrawer() {
    $('#contactForm')[0].reset();
    $('#contact_id').val('');
    $('#contact_org_id').val('').trigger('change');
    $('#drawerTitle').text('<?= __('add_customer') ?>');
    renderDynamicFields(initialFields, {});
    $('#contactDrawer').addClass('open');
    $('#drawerOverlay').fadeIn();
}

function closeContactDrawer() {
    $('#contactDrawer').removeClass('open');
    $('#drawerOverlay').fadeOut();
}

function editContact(id) {
    $.post('anasayfa?route=musteriler', { action: 'get_contact', id: id, ajax_action: 1 }, function(res) {
        if(res.status === 'success') {
            $('#drawerTitle').text('<?= __('edit') ?>: ' + res.data.email);
            $('#contact_id').val(res.data.id);
            $('#contact_name').val(res.data.name);
            $('#contact_email').val(res.data.email);
            $('#contact_org_id').val(res.data.organization_id).trigger('change');
            $('#contact_phone').val(res.data.phone);
            $('#contact_notes').val(res.data.notes);
            
            renderDynamicFields(res.data.relevant_fields, res.data.custom_values);

            $('#contactDrawer').addClass('open');
            $('#drawerOverlay').fadeIn();
        } else {
            Swal.fire('<?= __('error') ?>', res.message, 'error');
        }
    });
}

function saveContact() {
    const formData = new FormData($('#contactForm')[0]);
    $.ajax({
        url: 'anasayfa?route=musteriler',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.status === 'success') {
                closeContactDrawer();
                Swal.fire('<?= __('success') ?>', res.message, 'success').then(() => { location.reload(); });
            } else {
                Swal.fire('<?= __('error') ?>', res.message, 'error');
            }
        },
        error: function(err) {
            Swal.fire('<?= __('error') ?>', 'Bir hata oluştu.', 'error');
        }
    });
}

function deleteContact(id, email) {
    Swal.fire({
        title: '<?= __('are_you_sure') ?>',
        text: email + ' <?= __('delete_customer_confirm') ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: '<?= __('yes_delete') ?>',
        cancelButtonText: '<?= __('cancel') ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('anasayfa?route=musteriler', { action: 'delete_contact', id: id, ajax_action: 1 }, function(res) {
                if (res.status === 'success') location.reload();
                else Swal.fire('<?= __('error') ?>', res.message, 'error');
            });
        }
    });
}

// Live Search
let searchTimeout;
$('#liveSearchInput').on('input', function() {
    clearTimeout(searchTimeout);
    let query = $(this).val();
    
    // Add loading indicator
    $('#customersTableBody').css('opacity', '0.5');
    
    searchTimeout = setTimeout(function() {
        $.get('anasayfa?route=musteriler&ajax_search=1&q=' + encodeURIComponent(query), function(html) {
            $('#customersTableBody').html(html).css('opacity', '1');
        });
    }, 300); // 300ms delay to avoid spamming
});
</script>
