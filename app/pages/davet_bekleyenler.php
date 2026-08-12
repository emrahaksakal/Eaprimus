<?php
// pages/davet_bekleyenler.php

// YETKİ KONTROLÜ (Sadece Admin ve İK)
if (!isset($_SESSION['role']) || ($_SESSION['role'] != 1 && $_SESSION['role'] != 3)) {
    include __DIR__ . "/403.php";
    return;
}

// PHPMailer Sınıfları
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --------------------------------------------------------------------------------
// MAİL GÖNDERME İŞLEMİ
// --------------------------------------------------------------------------------
$swal_script = ""; // SweetAlert Javascript kodunu tutacak değişken

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'davet_gonder') {
    require_csrf_token();

    // Yolların düzeltilmesi: app/pages/ içindeyiz, 2 yukarı çıkıp libs klasörüne gitmeliyiz.
    $libs_dir = __DIR__ . '/../../libs';

    if (file_exists($libs_dir . '/phpmailer/Exception.php')) {
        require_once $libs_dir . '/phpmailer/Exception.php';
        require_once $libs_dir . '/phpmailer/PHPMailer.php';
        require_once $libs_dir . '/phpmailer/SMTP.php';
    } else {
        // Hata bastırma veya loglama gerekebilir, şimdilik sessiz.
    }

    $gonderilecek_idler = [];

    if (isset($_POST['secilenler']) && is_array($_POST['secilenler'])) {
        $gonderilecek_idler = array_map('intval', $_POST['secilenler']);
    } elseif (isset($_POST['user_id'])) {
        $gonderilecek_idler[] = (int) $_POST['user_id'];
    }

    if (empty($gonderilecek_idler)) {
        // Hata durumunda SweetAlert
        $swal_script = "
        <script>
            Swal.fire({
                icon: 'warning',
                title: 'Seçim Yapılmadı',
                text: 'Lütfen listeden en az bir personel seçiniz.',
                confirmButtonColor: '#ffc107',
                confirmButtonText: 'Tamam'
            });
        </script>";
    } else {
        $basarili = 0;

        // PHPMailer Yüklü mü Kontrolü
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $swal_script = "<script>Swal.fire('Hata', 'Mail kütüphanesi yüklenemedi. Lütfen sistem yöneticisine bildirin.', 'error');</script>";
        } else {
            foreach ($gonderilecek_idler as $uid) {
                $stmt = $pdo->prepare("SELECT fullname, mail, username FROM users WHERE id = ?");
                $stmt->execute([$uid]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($u && !empty($u['mail'])) {
                    $token = bin2hex(random_bytes(32));
                    $expires = date("Y-m-d H:i:s", strtotime('+24 hours'));

                    $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?")->execute([$token, $expires, $uid]);


                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
                    $host = $_SERVER['HTTP_HOST'];
                    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
                    if (substr($script_dir, -7) === '/public') { $script_dir = substr($script_dir, 0, -7); }
                    $base_url = $protocol . $host . rtrim($script_dir, '/') . '/';
                    
                    $activation_link = $base_url . "activation?token=" . $token . "&email=" . urlencode($u['mail']);
                    $logo_url = $base_url . "public/assets/img/logo.png";
                    // ----------------------------------------------------------------

                    // Mail At
                    require_once __DIR__ . '/../includes/mailer.php';
                    $lang = $_SESSION['lang'] ?? 'tr';
                    if ($lang !== 'en') $lang = 'tr';
                    $sent = sendTemplatedMail($u['mail'], $u['fullname'], 'user_invitation', [
                        'fullname' => $u['fullname'],
                        'ACTIVATION_LINK' => $activation_link
                    ], '', $lang);
                    if ($sent) $basarili++;
                }
            }

            // Başarılı işlem sonrası SweetAlert
            $swal_script = "
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Gönderim Başarılı!',
                    html: 'Toplam <b>$basarili</b> personele davet maili iletildi.',
                    showConfirmButton: false,
                    timer: 2000
                }).then(() => {
                    window.location.href = 'davet-bekleyenler';
                });
            </script>";
        }
    }
}

// --------------------------------------------------------------------------------
// LİSTEYİ ÇEK VE GRUPLA (ŞİFRE ÇÖZÜCÜLÜ)
// --------------------------------------------------------------------------------
try {
    $sql = "SELECT
                u.id, u.fullname, u.mail, u.reset_token, u.reset_expires,
                CAST(AES_DECRYPT(UNHEX(u.tc_no), '" . EAPRIMUS_KEY . "') AS CHAR) as tc_no,
                COALESCE(d.bolum_adi, u.bolum) as bolum_goster
            FROM users u
            LEFT JOIN bolumler d ON u.bolum = d.id
            WHERE u.role = 2 AND (u.password IS NULL OR u.password = '') AND u.deleted_at IS NULL
            ORDER BY bolum_goster ASC, u.fullname ASC";

    $stmt = $pdo->query($sql);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $users = [];
}

// Veriyi Grupla
$gruplu_users = [];
foreach ($users as $u) {
    $dept = !empty($u['bolum_goster']) ? $u['bolum_goster'] : 'Departman Belirtilmemiş';
    $gruplu_users[$dept][] = $u;
}

$toplam_bekleyen = count($users);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .accordion-header {
        cursor: pointer;
        transition: all 0.2s;
        border-left: 4px solid transparent;
    }

    .accordion-header:hover {
        background-color: #f8f9fa;
        border-left: 4px solid #ffc107;
    }

    body.dark-mode .accordion-header:hover {
        background-color: #2b3035 !important;
    }

    body.dark-mode .accordion-header {
        color: #cdd9e5 !important;
    }

    body.dark-mode .mobil-tablo tr {
        background: #22272e !important;
        border-color: #444c56 !important;
    }

    .custom-control-label::before {
        border-color: #adb5bd;
    }

    .custom-control-input:checked~.custom-control-label::before {
        border-color: #ffc107;
        background-color: #ffc107;
    }

    @media screen and (max-width: 768px) {
        .mobil-tablo thead {
            display: none;
        }

        .mobil-tablo tr {
            display: flex;
            flex-direction: column;
            margin-bottom: 15px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            position: relative;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .mobil-tablo td {
            border: none;
            padding: 5px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mobil-tablo td:first-child {
            position: absolute;
            top: 15px;
            right: 15px;
        }

        .mobil-gizle {
            display: none;
        }
    }
</style>

<?= $swal_script ?>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6 col-8">
                <h1 class="m-0 text-dark font-weight-bold">
                    <i class="fas fa-user-clock mr-2 text-warning"></i> Davet Bekleyenler
                    <span class="badge badge-warning ml-2 shadow-sm"
                        style="font-size: 0.5em; vertical-align: middle;"><?= $toplam_bekleyen ?> Personel</span>
                </h1>
            </div>
            <div class="col-sm-6 col-4 text-right">
                <a href="sistem-ayarlari?tab=personel&edit_template=user_invitation" class="btn btn-outline-warning btn-sm font-weight-bold shadow-sm">
                    <i class="fas fa-envelope-open-text mr-1"></i> <?= ($_SESSION['lang'] ?? 'tr') === 'tr' ? 'Davet Şablonunu Düzenle' : 'Edit Invitation Template' ?>
                </a>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <form method="POST" id="davetForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="davet_gonder">

            <div class="card card-outline card-warning shadow-sm">
                <div class="card-header bg-transparent">
                    <h3 class="card-title pt-1">
                        <div class="custom-control custom-checkbox">
                            <input class="custom-control-input" type="checkbox" id="checkAll">
                            <label class="custom-control-label font-weight-normal text-muted" for="checkAll">Tümünü
                                Seç</label>
                        </div>
                    </h3>
                    <div class="card-tools">
                        <button type="button" id="btnGonder"
                            class="btn btn-warning btn-sm font-weight-bold shadow-sm text-dark">
                            <i class="fas fa-paper-plane mr-1"></i> Seçilenlere Davet Gönder
                        </button>
                    </div>
                </div>
            </div>

            <?php if (empty($gruplu_users)): 
                $isDark = (($_SESSION['theme'] ?? '') === 'dark');
            ?>
                <div class="text-center shadow-sm p-5 border card" style="border-radius: 16px; background: <?= $isDark ? '#2b3035' : '#fff' ?>; border-color: <?= $isDark ? '#444c56' : 'rgba(0,0,0,0.05)' ?> !important; color: <?= $isDark ? '#f8f9fa' : '#1e293b' ?>;">
                    <div class="text-success mb-3"><i class="fas fa-check-circle fa-4x"></i></div>
                    <h4 class="font-weight-bold" style="color: <?= $isDark ? '#f8f9fa' : '#1e293b' ?>;"><?= ($_SESSION['lang'] ?? 'tr') === 'tr' ? 'Her Şey Yolunda!' : 'All Good!' ?></h4>
                    <p class="text-muted mb-0"><?= ($_SESSION['lang'] ?? 'tr') === 'tr' ? 'Tüm personeller sisteme kayıtlı. Bekleyen davet bulunmuyor.' : 'All personnel are registered. No pending invitations.' ?></p>
                </div>
            <?php else: ?>

                <div id="accordionDept">
                    <?php foreach ($gruplu_users as $bolum_adi => $personeller):
                        $deptID = md5($bolum_adi); ?>

                        <div class="card mb-3 border-0 shadow-sm">
                            <div class="card-header accordion-header d-flex justify-content-between align-items-center bg-transparent"
                                data-toggle="collapse" href="#collapse_<?= $deptID ?>">
                                <h5 class="m-0 text-dark font-weight-bold" style="font-size: 1rem;">
                                    <i class="fas fa-layer-group text-secondary mr-2"></i> <?= htmlspecialchars($bolum_adi) ?>
                                </h5>
                                <span class="badge badge-light border"><?= count($personeller) ?> Bekleyen</span>
                            </div>

                            <div id="collapse_<?= $deptID ?>" class="collapse show" data-parent="#accordionDept">
                                <div class="card-body p-0 table-responsive">
                                    <table class="table table-hover mobil-tablo mb-0 text-nowrap">
                                        <thead class="bg-light">
                                            <tr>
                                                <th style="width: 40px;">#</th>
                                                <th>Personel</th>
                                                <th>TC No</th>
                                                <th>Mail Adresi</th>
                                                <th>Durum</th>
                                                <th class="text-right">İşlem</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($personeller as $u): ?>
                                                <tr>
                                                    <td>
                                                        <div class="custom-control custom-checkbox">
                                                            <input class="custom-control-input secim-kutusu" type="checkbox"
                                                                name="secilenler[]" id="cb_<?= $u['id'] ?>" value="<?= $u['id'] ?>">
                                                            <label class="custom-control-label" for="cb_<?= $u['id'] ?>"></label>
                                                        </div>
                                                    </td>

                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="bg-light border rounded-circle d-flex align-items-center justify-content-center mr-2 text-muted"
                                                                style="width:35px; height:35px;">
                                                                <i class="fas fa-user"></i>
                                                            </div>
                                                            <span
                                                                class="font-weight-bold text-dark"><?= htmlspecialchars($u['fullname']) ?></span>
                                                        </div>
                                                    </td>

                                                     <td class="mobil-gizle text-muted">
                                                         <?php
                                                         $tcVal = trim((string)($u['tc_no'] ?? ''));
                                                         if (!empty($tcVal)) {
                                                             echo htmlspecialchars($tcVal);
                                                         } else {
                                                             echo '-';
                                                         }
                                                         ?>
                                                     </td>

                                                    <td><span class="text-muted"><i class="far fa-envelope mr-1"></i>
                                                            <?= $u['mail'] ?></span></td>

                                                    <td>
                                                         <?php if (!empty($u['reset_token'])): 
                                                            $is_expired = strtotime($u['reset_expires']) < time();
                                                            if($is_expired): ?>
                                                                <span class="badge badge-danger px-2 py-1"><i class="fas fa-hourglass-end mr-1"></i> Süresi Doldu</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-info px-2 py-1"><i class="fas fa-paper-plane mr-1"></i> Gönderildi</span>
                                                            <?php endif; ?>
                                                         <?php else: ?>
                                                             <span class="badge badge-secondary px-2 py-1">Bekliyor</span>
                                                         <?php endif; ?>
                                                     </td>

                                                    <td class="text-right">
                                                        <label for="cb_<?= $u['id'] ?>"
                                                            class="btn btn-xs btn-outline-dark shadow-sm mb-0">
                                                            Seç
                                                        </label>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

        </form>
    </div>
</section>

<script>
    // Tümünü Seç / Kaldır
    document.getElementById('checkAll').addEventListener('change', function () {
        var checkboxes = document.querySelectorAll('.secim-kutusu');
        for (var checkbox of checkboxes) {
            checkbox.checked = this.checked;
        }
    });

    // Butona basınca SweetAlert Onayı
    document.getElementById('btnGonder').addEventListener('click', function () {
        // Seçili var mı kontrol et
        var seciliSayisi = document.querySelectorAll('.secim-kutusu:checked').length;

        if (seciliSayisi === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Dikkat',
                text: 'Lütfen listeden en az bir personel seçiniz.',
                confirmButtonColor: '#ffc107',
                confirmButtonText: 'Tamam'
            });
            return;
        }

        Swal.fire({
            title: 'Emin misiniz?',
            text: seciliSayisi + " personele davet maili gönderilecektir.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Evet, Gönder',
            cancelButtonText: 'İptal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Formu gönder
                document.getElementById('davetForm').submit();
            }
        });
    });
</script>