<?php
// pages/musteri_fields.php

require_once __DIR__ . '/../includes/session.php';
requireLogin();

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db();
}

$current_user_role = $_SESSION['role'];

if ($current_user_role != 1) {
    echo '<div class="alert alert-danger m-3">' . __('no_permission') . '</div>';
    return;
}

$hata = '';
$mesaj = '';

// Silme işlemi
if (isset($_GET['delete'])) {
    $fid = (int) $_GET['delete'];
    try {
        $pdo->prepare("DELETE FROM customer_fields WHERE id = ?")->execute([$fid]);
        $_SESSION['mesaj'] = __('field_deleted_success');
        header('Location: musteri-fields');
        exit;
    } catch (PDOException $e) {
        $hata = __('database_error') . ': ' . $e->getMessage();
    }
}

// Yeni field ekleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_field'])) {
    require_csrf_token(); // CSRF protection

    $label = trim($_POST['label'] ?? '');
    $field_key = trim($_POST['field_key'] ?? '');
    $field_type = trim($_POST['field_type'] ?? '');
    $manual_type = trim($_POST['manual_type'] ?? '');
    $target_type = trim($_POST['target_type'] ?? 'contact');
    $required = isset($_POST['required']) ? 1 : 0;
    $options = trim($_POST['options'] ?? '');
    $customer_ids = isset($_POST['customer_ids']) ? implode(',', $_POST['customer_ids']) : NULL;

    if (!empty($manual_type)) {
        $field_type = $manual_type;
    }

    if (empty($label) || empty($field_key) || empty($field_type)) {
        $hata = __('fill_required_fields');
    } else {
        // field_key benzersizliği kontrol
        $stmt = $pdo->prepare("SELECT id FROM customer_fields WHERE field_key = ? AND target_type = ?");
        $stmt->execute([$field_key, $target_type]);
        if ($stmt->fetchColumn()) {
            $hata = __('field_key_already_exists');
        } else {
            try {
                $pdo->prepare("INSERT INTO customer_fields (field_key, label, field_type, target_type, required, options, customer_ids) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$field_key, $label, $field_type, $target_type, $required, $options ?: null, $customer_ids]);
                $_SESSION['mesaj'] = __('field_added_success');
                header('Location: musteri-fields');
                exit;
            } catch (PDOException $e) {
                $hata = __('database_error') . ': ' . $e->getMessage();
            }
        }
    }
}

// Tüm fields'leri getir
$fields = [];
try {
    $stmt = $pdo->query("SELECT * FROM customer_fields ORDER BY target_type ASC, sort_order ASC, id ASC");
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hata = __('database_error') . ': ' . $e->getMessage();
}

// Müşterileri getir (Scope Check için)
$customerList = $pdo->query("SELECT id, name, email FROM customers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="content-header">
    <div class="container-fluid">
        <h1 class="m-0"><?= __('customer_custom_fields') ?></h1>
    </div>
</div>

<?php if ($hata): ?>
    <div class="alert alert-danger m-3"><?= htmlspecialchars($hata) ?></div>
<?php endif; ?>

<div class="row m-3">
    <div class="col-md-4">
        <div class="card card-primary shadow-sm border-0" style="border-radius: 12px;">
            <div class="card-header bg-primary" style="border-top-left-radius: 12px; border-top-right-radius: 12px;">
                <h3 class="card-title text-bold"><i class="fas fa-plus-circle mr-2"></i><?= __('add_new_field') ?></h3>
            </div>

            <form method="POST" action="">
                <?= csrf_field() ?>
                <div class="card-body">
                    <div class="form-group">
                        <label class="font-weight-bold text-muted small text-uppercase"><?= __('field_scope') ?> *</label>
                        <select name="target_type" id="target_type" class="form-control form-control-lg bg-light border-0" required onchange="toggleCustomerSelect()">
                            <option value="organization"><?= __('scope_organization') ?></option>
                            <option value="contact" selected><?= __('scope_contact') ?></option>
                            <option value="ticket"><?= __('scope_ticket') ?></option>
                        </select>
                    </div>

                    <div id="customer_selection_div" class="form-group">
                        <label class="font-weight-bold text-muted small text-uppercase"><?= __('only_specific_customers') ?> <small>(<?= __("optional") ?>)</small></label>
                        <select name="customer_ids[]" class="form-control select2" multiple data-placeholder="<?= __("select_customer") ?>">
                            <?php foreach($customerList as $cl): ?>
                                <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name'] ?: $cl['email']) ?> (<?= htmlspecialchars($cl['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted d-block mt-1"><i class="fas fa-info-circle"></i> <?= __("all_customers_hint") ?? 'Boş bırakılırsa tüm müşterilerde görünür.' ?></small>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold text-muted small text-uppercase"><?= __('field_label') ?> *</label>
                        <input type="text" name="label" id="field_label_input" class="form-control form-control-lg bg-light border-0" placeholder="<?= __('example_sector') ?>" required oninput="generateSlug()">
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold text-muted small text-uppercase"><?= __('field_key') ?> *</label>
                        <input type="text" name="field_key" id="field_key_input" class="form-control form-control-lg bg-light border-0" placeholder="industry (küçük harf, boşluksuz)" required pattern="[a-z0-9_]{1,100}">
                        <small class="text-muted d-block mt-1"><i class="fas fa-magic"></i> Alan adına göre otomatik oluşturulur, dilerseniz değiştirebilirsiniz.</small>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold text-muted small text-uppercase"><?= __('field_type') ?> *</label>
                        <select name="field_type" id="field_type" class="form-control form-control-lg bg-light border-0" required onchange="checkManualType()">
                            <option value="">-- <?= __('select_type') ?> --</option>
                            <option value="text"><?= __('text') ?></option>
                            <option value="textarea">Textarea (Geniş Metin)</option>
                            <option value="number"><?= __('number') ?></option>
                            <option value="ip">IP Adresi</option>
                            <option value="dropdown"><?= __('dropdown') ?> (Açılır Liste)</option>
                            <option value="checkbox">Checkbox (Onay Kutusu)</option>
                            <option value="date"><?= __('date') ?></option>
                            <option value="url"><?= __('url') ?></option>
                            <option value="manual">-- Özel HTML Tipi (Manuel) --</option>
                        </select>
                        <div id="fieldTypeHelp" class="alert alert-info mt-2 p-2 small border-0" style="display:none; background: #e0f3ff; color: #0056b3;"></div>
                    </div>

                    <div id="manual_type_div" class="form-group d-none">
                        <label class="font-weight-bold text-muted small text-uppercase"><?= __("manual_type_name") ?></label>
                        <input type="text" name="manual_type" class="form-control bg-light border-0" placeholder="<?= __("manual_type_placeholder") ?>">
                    </div>

                    <div id="options_div" class="form-group" style="display: none;">
                        <label class="font-weight-bold text-muted small text-uppercase"><?= __('field_options') ?> <small class="text-danger">(Virgülle ayırın)</small></label>
                        <textarea name="options" class="form-control bg-light border-0" rows="2" placeholder="Seçenek 1, Seçenek 2, Seçenek 3"></textarea>
                    </div>

                    <div class="custom-control custom-checkbox mt-4 p-3 bg-light rounded border-0">
                        <input type="checkbox" name="required" id="required" class="custom-control-input">
                        <label class="custom-control-label font-weight-bold" style="cursor: pointer;" for="required"><?= __('required_field') ?> <small class="text-muted d-block font-weight-normal">Bu alanın doldurulması zorunlu olsun mu?</small></label>
                    </div>
                </div>

                <div class="card-footer bg-white border-0">
                    <button type="submit" name="add_field" class="btn btn-primary btn-block p-2 shadow-sm">
                        <i class="fas fa-save mr-1"></i> <?= __('add_field') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card card-info shadow-sm border-0" style="border-radius: 12px;">
            <div class="card-header bg-info" style="border-top-left-radius: 12px; border-top-right-radius: 12px;">
                <h3 class="card-title text-bold"><i class="fas fa-list mr-2"></i><?= __('existing_custom_fields') ?></h3>
            </div>

            <div class="card-body table-responsive p-0">
                <table class="table table-hover text-nowrap align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th><?= __('field_scope') ?></th>
                            <th><?= __('field_label') ?></th>
                            <th><?= __('field_key') ?></th>
                            <th><?= __('type') ?></th>
                            <th class="text-center"><?= __('required') ?></th>
                            <th><?= __("field_scope") ?></th>
                            <th class="text-right"><?= __('action') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fields)): ?>
                            <tr><td colspan="7" class="text-center py-4"><?= __('no_fields_found') ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($fields as $f): ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-secondary p-2">
                                            <?= __('scope_' . $f['target_type']) ?>
                                        </span>
                                    </td>
                                    <td><span class="font-weight-bold"><?= htmlspecialchars($f['label']) ?></span></td>
                                    <td><code><?= htmlspecialchars($f['field_key']) ?></code></td>
                                    <td><span class="badge badge-outline-primary"><?= htmlspecialchars($f['field_type']) ?></span></td>
                                    <td class="text-center"><?= $f['required'] ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-minus text-muted"></i>' ?></td>
                                    <td>
                                        <?php if($f['target_type'] === 'contact' && !empty($f['customer_ids'])): ?>
                                            <span class="badge badge-warning" title="<?= htmlspecialchars($f['customer_ids']) ?>"><?= __("scope_private") ?> (<?= count(explode(',', $f['customer_ids'])) ?> <?= __("customer") ?>)</span>
                                        <?php else: ?>
                                            <span class="badge badge-light border"><?= __("scope_public") ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?= $f['id'] ?>, '<?= addslashes(htmlspecialchars($f['label'])) ?>')"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function toggleCustomerSelect() {
    const target = document.getElementById('target_type').value;
    const div = document.getElementById('customer_selection_div');
    if (target === 'contact') {
        div.classList.remove('d-none');
    } else {
        div.classList.add('d-none');
    }
}

function checkManualType() {
    const type = document.getElementById('field_type').value;
    const div = document.getElementById('manual_type_div');
    const optionsDiv = document.getElementById('options_div');
    const helpBox = document.getElementById('fieldTypeHelp');
    
    if (type === 'manual') {
        div.classList.remove('d-none');
    } else {
        div.classList.add('d-none');
    }
    
    if (type === 'dropdown') {
        optionsDiv.style.display = 'block';
    } else {
        optionsDiv.style.display = 'none';
    }
    
    const fieldHelpTexts = {
        'text': '<?= __('help_text') ?? 'Kısa metin girişleri için uygundur (Örn: Vergi No, Sicil No, Departman).' ?>',
        'textarea': '<?= __('help_textarea') ?? 'Uzun açıklamalar veya notlar için geniş metin alanı sağlar.' ?>',
        'number': '<?= __('help_number') ?? 'Sadece rakamsal (sayısal) veri girmek için kullanılır.' ?>',
        'ip': '<?= __('help_ip') ?? 'Sunucu, bilgisayar veya cihaz IP adreslerini (192.168.x.x) girmek içindir.' ?>',
        'dropdown': '<?= __('help_dropdown') ?? 'Açılır listeden çoklu seçenek sunar. Lütfen "Seçenekler" kutusuna aralarına virgül koyarak yazınız (Örn: Seçenek A, Seçenek B).' ?>',
        'checkbox': '<?= __('help_checkbox') ?? 'Evet/Hayır (Aktif/Pasif) şeklinde tekli onay seçeneği sunar.' ?>',
        'date': '<?= __('help_date') ?? 'Takvim arayüzü ile belirli bir tarih (sözleşme tarihi, doğum tarihi vb.) seçtirmek içindir.' ?>',
        'url': '<?= __('help_url') ?? 'Tıklanabilir bir web veya sistem bağlantısı (link) eklemek içindir.' ?>',
        'manual': '<?= __('help_manual') ?? 'Sisteme özel HTML tipi belirlemek içindir (Geliştiriciler için).' ?>'
    };
    
    if(fieldHelpTexts[type]) {
        helpBox.innerHTML = '<i class="fas fa-info-circle mr-1"></i> ' + fieldHelpTexts[type];
        helpBox.style.display = 'block';
    } else {
        helpBox.style.display = 'none';
    }
}

function generateSlug() {
    const labelInput = document.getElementById('field_label_input').value;
    const keyInput = document.getElementById('field_key_input');
    
    // Turkish characters replacement and formatting
    let slug = labelInput.toLowerCase()
        .replace(/ğ/g, 'g')
        .replace(/ü/g, 'u')
        .replace(/ş/g, 's')
        .replace(/ı/g, 'i')
        .replace(/ö/g, 'o')
        .replace(/ç/g, 'c')
        .replace(/[^a-z0-9\s]/g, '') // Remove special characters
        .replace(/\s+/g, '_')        // Replace spaces with underscores
        .trim();                     // Remove leading/trailing spaces
        
    keyInput.value = slug;
}

function confirmDelete(id, label) {
    Swal.fire({
        title: '<?= __('are_you_sure') ?>',
        text: label + ' <?= __('delete_field_confirm') ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<?= __('yes_delete') ?>',
        cancelButtonText: '<?= __('cancel') ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'musteri-fields?delete=' + id;
        }
    });
}

// Initial check
$(document).ready(function() {
    toggleCustomerSelect();
    checkManualType();
});
</script>
