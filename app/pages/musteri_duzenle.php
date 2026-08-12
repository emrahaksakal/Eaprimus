<?php
// pages/musteri_duzenle.php

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

if (!isset($_GET['id'])) {
    echo '<div class="alert alert-warning m-3">' . __('invalid_request') . '</div>';
    return;
}

$cid = (int) $_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$cid]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    echo '<div class="alert alert-danger m-3">' . __('customer_not_found') . '</div>';
    return;
}

$hata = '';
$mesaj = '';

// Fetch custom fields config and current values
$cfs_stmt = $pdo->prepare("SELECT cf.*, cfv.value, cfv.id as val_id 
                           FROM customer_fields cf 
                           LEFT JOIN customer_field_values cfv ON cf.id = cfv.field_id AND cfv.customer_id = ? 
                           ORDER BY cf.sort_order ASC");
$cfs_stmt->execute([$cid]);
$customFields = $cfs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Güncelle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (empty($name)) {
        $hata = 'Ad Soyad alanı zorunludur.'; // Explicit error to prevent DB 1048 crash
    } elseif (empty($email)) {
        $hata = __('email_required');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $hata = __('invalid_email_format');
    } else {
        // Email benzersizliği kontrol (kendisi hariç)
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE email = ? AND id != ?");
        $stmt->execute([$email, $cid]);
        if ($stmt->fetchColumn()) {
            $hata = __('email_already_exists');
        } else {
            try {
                $pdo->prepare("UPDATE customers SET name=?, email=?, company=?, phone=?, notes=? WHERE id=?")
                    ->execute([$name, $email, $company ?: null, $phone ?: null, $notes ?: null, $cid]);

                // Handle Avatar Upload
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../../public/uploads/avatars/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    if (in_array($ext, $allowed)) {
                        $newFileName = 'cust_' . $cid . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $newFileName)) {
                            // Update DB
                            $pdo->prepare("UPDATE customers SET avatar = ? WHERE id = ?")->execute([$newFileName, $cid]);
                            $customer['avatar'] = $newFileName;
                        }
                    }
                }

                // Save custom fields
                $customValues = $_POST['custom_fields'] ?? [];
                foreach ($customFields as $cf) {
                    $fid = $cf['id'];
                    $val = trim($customValues[$fid] ?? '');
                    if ($cf['val_id']) {
                        $pdo->prepare("UPDATE customer_field_values SET value = ? WHERE id = ?")->execute([$val, $cf['val_id']]);
                    } elseif ($val !== '') {
                        $pdo->prepare("INSERT INTO customer_field_values (customer_id, field_id, value) VALUES (?,?,?)")->execute([$cid, $fid, $val]);
                    }
                }

                $mesaj = __('customer_updated_success');
                // Verileri yenile
                $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
                $stmt->execute([$cid]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);

                $cfs_stmt->execute([$cid]);
                $customFields = $cfs_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $hata = __('database_error') . ': ' . $e->getMessage();
            }
        }
    }
}
?>

<div class="content-header">
    <div class="container-fluid">
        <h1 class="m-0"><?= htmlspecialchars($customer['name'] ?: $customer['email']) ?> - <?= __('edit') ?></h1>
    </div>
</div>

<?php if ($hata): ?>
    <div class="alert alert-danger m-3"><?= htmlspecialchars($hata) ?></div>
<?php endif; ?>

<?php if ($mesaj): ?>
    <div class="alert alert-success m-3"><?= htmlspecialchars($mesaj) ?></div>
<?php endif; ?>

<div class="row m-3">
    <div class="col-md-6">
        <div class="card card-primary">
            <div class="card-header">
                <h3 class="card-title"><?= __('customer_information') ?></h3>
            </div>

            <form method="POST" action="" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="card-body">
                    <div class="form-group">
                        <label><?= __('full_name') ?></label>
                        <input type="text" name="name" class="form-control" placeholder="<?= __('full_name') ?>"
                            value="<?= htmlspecialchars($customer['name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Profil Resmi / Logo (Opsiyonel)</label><br>
                        <?php if(!empty($customer['avatar'])): ?>
                            <div class="mb-2">
                                <img src="/public/uploads/avatars/<?= htmlspecialchars($customer['avatar']) ?>" alt="Avatar" style="width:80px; height:80px; border-radius:50%; object-fit:cover; border:2px solid #ddd;">
                            </div>
                        <?php endif; ?>
                        <input type="file" name="avatar" class="form-control-file" accept="image/*">
                        <small class="text-muted">JPG, PNG, GIF, WEBP formatları desteklenir.</small>
                    </div>

                    <div class="form-group">
                        <label><?= __('email_address') ?> *</label>
                        <input type="email" name="email" class="form-control" placeholder="<?= __('email_address') ?>"
                            required value="<?= htmlspecialchars($customer['email']) ?>">
                    </div>

                    <div class="form-group">
                        <label><?= __('company') ?></label>
                        <input type="text" name="company" class="form-control" placeholder="<?= __('company') ?>"
                            value="<?= htmlspecialchars($customer['company'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label><?= __('phone') ?></label>
                        <input type="tel" name="phone" class="form-control" placeholder="<?= __('phone') ?>"
                            value="<?= htmlspecialchars($customer['phone'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label><?= __('notes') ?></label>
                        <textarea name="notes" class="form-control" rows="4"
                            placeholder="<?= __('notes') ?>"><?= htmlspecialchars($customer['notes'] ?? '') ?></textarea>
                    </div>

                    <?php if (!empty($customFields)): ?>
                        <hr>
                        <h5 class="mb-3">Ek Bilgiler (CRM)</h5>
                        <?php foreach($customFields as $cf): ?>
                            <div class="form-group">
                                <label><?= htmlspecialchars($cf['label']) ?> <?= $cf['required'] ? '*' : '' ?></label>
                                <?php if($cf['field_type'] === 'dropdown'): 
                                    $options = array_filter(array_map('trim', explode(',', $cf['options'] ?? '')));
                                ?>
                                    <select name="custom_fields[<?= $cf['id'] ?>]" class="form-control" <?= $cf['required'] ? 'required' : '' ?>>
                                        <option value="">— Seçiniz —</option>
                                        <?php foreach($options as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt) ?>" <?= ($cf['value'] == $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif($cf['field_type'] === 'date'): ?>
                                    <input type="date" name="custom_fields[<?= $cf['id'] ?>]" class="form-control" value="<?= htmlspecialchars($cf['value'] ?? '') ?>" <?= $cf['required'] ? 'required' : '' ?>>
                                <?php elseif($cf['field_type'] === 'number'): ?>
                                    <input type="number" name="custom_fields[<?= $cf['id'] ?>]" class="form-control" value="<?= htmlspecialchars($cf['value'] ?? '') ?>" <?= $cf['required'] ? 'required' : '' ?>>
                                <?php elseif($cf['field_type'] === 'url'): ?>
                                    <input type="url" name="custom_fields[<?= $cf['id'] ?>]" class="form-control" placeholder="https://..." value="<?= htmlspecialchars($cf['value'] ?? '') ?>" <?= $cf['required'] ? 'required' : '' ?>>
                                <?php else: // text ?>
                                    <input type="text" name="custom_fields[<?= $cf['id'] ?>]" class="form-control" value="<?= htmlspecialchars($cf['value'] ?? '') ?>" <?= $cf['required'] ? 'required' : '' ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="card-footer">
                    <button type="submit" class="btn btn-primary"><?= __('save') ?></button>
                    <a href="musteri-detay/<?= $cid ?>" class="btn btn-secondary"><?= __('cancel') ?></a>
                </div>
            </form>
        </div>
    </div>
</div>