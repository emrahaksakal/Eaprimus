<?php
// pages/musteri_ekle.php

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

// Kaydet
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
        // Email benzersizliği kontrol
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            $hata = __('email_already_exists');
        } else {
            try {
                $pdo->prepare("INSERT INTO customers (name, email, company, phone, notes, source, created_at) VALUES (?, ?, ?, ?, ?, 'admin', NOW())")
                    ->execute([$name, $email, $company ?: null, $phone ?: null, $notes ?: null]);
                
                $newCid = $pdo->lastInsertId();

                // Handle Avatar Upload
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../../public/uploads/avatars/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    if (in_array($ext, $allowed)) {
                        $newFileName = 'cust_' . $newCid . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $newFileName)) {
                            $pdo->prepare("UPDATE customers SET avatar = ? WHERE id = ?")->execute([$newFileName, $newCid]);
                        }
                    }
                }
                $mesaj = __('customer_added_success');
                // Yönlendir
                header('Location: musteriler');
                exit;
            } catch (PDOException $e) {
                $hata = __('database_error') . ': ' . $e->getMessage();
            }
        }
    }
}
?>

<div class="content-header">
    <div class="container-fluid">
        <h1 class="m-0"><?= __('add_customer') ?></h1>
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
                        <input type="text" name="name" class="form-control" placeholder="<?= __('full_name') ?>" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Profil Resmi / Logo (Opsiyonel)</label>
                        <input type="file" name="avatar" class="form-control-file" accept="image/*">
                        <small class="text-muted">JPG, PNG, GIF, WEBP formatları desteklenir.</small>
                    </div>

                    <div class="form-group">
                        <label><?= __('email_address') ?> *</label>
                        <input type="email" name="email" class="form-control" placeholder="<?= __('email_address') ?>" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <small class="form-text text-muted"><?= __('email_required') ?></small>
                    </div>

                    <div class="form-group">
                        <label><?= __('company') ?></label>
                        <input type="text" name="company" class="form-control" placeholder="<?= __('company') ?>" value="<?= htmlspecialchars($_POST['company'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label><?= __('phone') ?></label>
                        <input type="tel" name="phone" class="form-control" placeholder="<?= __('phone') ?>" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label><?= __('notes') ?></label>
                        <textarea name="notes" class="form-control" rows="4" placeholder="<?= __('notes') ?>"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="card-footer">
                    <button type="submit" class="btn btn-primary"><?= __('save') ?></button>
                    <a href="musteriler" class="btn btn-secondary"><?= __('cancel') ?></a>
                </div>
            </form>
        </div>
    </div>
</div>
