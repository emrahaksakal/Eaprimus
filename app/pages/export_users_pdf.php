<?php
// pages/export_users_pdf.php

// 1. YETKİ KONTROLÜ (Sadece Admin ve İK Girebilir)
if (!isset($_SESSION['role']) || ($_SESSION['role'] != 1 && $_SESSION['role'] != 3)) {
    echo "<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>Bu sayfayı görüntüleme yetkiniz yok. / Access Denied</div>";
    exit;
}

$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

// PARAMETRELER (Yön, Kağıt, TC Gösterimi, Kapsam)
$orientation = strtolower($_GET['orientation'] ?? 'landscape');
if (!in_array($orientation, ['landscape', 'portrait'])) $orientation = 'landscape';

$paper = strtolower($_GET['paper'] ?? 'a4');
if (!in_array($paper, ['a4', 'a3'])) $paper = 'a4';

$show_tc = isset($_GET['show_tc']) && $_GET['show_tc'] == '1';
$scope_all = isset($_GET['scope']) && $_GET['scope'] == 'all';

// TC KİMLİK ÇÖZME MANTIĞI (Admin - Role 1 & İK - Role 3 için çözülür)
if ($show_tc) {
    if ($_SESSION['role'] == 1 || $_SESSION['role'] == 3) {
        $tc_sql_part = "CAST(AES_DECRYPT(UNHEX(u.tc_no), '" . EAPRIMUS_KEY . "') AS CHAR)";
    } else {
        $tc_sql_part = "'********'";
    }
} else {
    $tc_sql_part = "NULL";
}

$view_deleted = isset($_GET['view_deleted']) && $_GET['view_deleted'] == '1';
$deletedCondition = $view_deleted ? "u.deleted_at IS NOT NULL" : "u.deleted_at IS NULL";

$limit_clause = "";
if (!$scope_all) {
    $limit = intval($_GET['limit'] ?? 50);
    if ($limit < 5 || $limit > 1000) $limit = 50;
    $page = intval($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $limit;
    $limit_clause = "LIMIT $limit OFFSET $offset";
}

$sql = "
    SELECT
        u.fullname,
        u.username,
        u.mail,
        u.role,
        u.status,
        $tc_sql_part as tc_no,
        COALESCE(b.bolum_adi, u.bolum) as bolum_adi,
        GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') as team_names
    FROM users u
    LEFT JOIN bolumler b ON u.bolum = b.id
    LEFT JOIN teams_users tu ON u.id = tu.user_id
    LEFT JOIN teams t ON tu.team_id = t.id
    WHERE $deletedCondition AND u.username != 'customer_gateway'
    GROUP BY u.id
    ORDER BY u.fullname ASC
    $limit_clause
";

try {
    $stmt = $pdo->query($sql);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

// Dimensions for preview container
$width_mm = ($paper === 'a3') ? ($orientation === 'landscape' ? '420mm' : '297mm') : ($orientation === 'landscape' ? '297mm' : '210mm');
$min_height_mm = ($paper === 'a3') ? ($orientation === 'landscape' ? '297mm' : '420mm') : ($orientation === 'landscape' ? '210mm' : '297mm');
?>
<!DOCTYPE html>
<html lang="<?= $isTr ? 'tr' : 'en' ?>">

<head>
    <meta charset="utf-8">
    <title><?= $isTr ? 'Personel Listesi Raporu' : 'Personnel List Report' ?> | Eaprimus</title>
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">

    <style>
        @page {
            size: <?= $paper ?> <?= $orientation ?>;
            margin: 8mm;
        }

        body {
            background-color: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #1f2937;
        }

        /* PAGE CONTAINER */
        .page-container {
            background-color: white;
            width: <?= $width_mm ?>;
            min-height: <?= $min_height_mm ?>;
            padding: 12mm;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            box-sizing: border-box;
            position: relative;
            border-radius: 4px;
        }

        /* HEADER */
        .report-header {
            border-bottom: 2px solid #2563eb;
            padding-bottom: 12px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .report-header h1 {
            margin: 0;
            font-size: 22px;
            color: #1e40af;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .report-header .sub-title {
            font-size: 14px;
            color: #4b5563;
            font-weight: 600;
            margin-top: 4px;
        }

        .report-header .meta {
            text-align: right;
            font-size: 11px;
            color: #6b7280;
            line-height: 1.5;
        }

        /* TABLE */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            table-layout: fixed;
        }

        th, td {
            border: 1px solid #e5e7eb;
            padding: 7px 10px;
            text-align: left;
            word-wrap: break-word;
        }

        th {
            background-color: #f3f4f6;
            color: #1f2937;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #cbd5e1;
        }

        tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .badge-role {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
        }

        .badge-admin { background: #fee2e2; color: #991b1b; }
        .badge-hr { background: #fef3c7; color: #92400e; }
        .badge-staff { background: #f3f4f6; color: #374151; }

        /* PRINT STYLES */
        @media print {
            body {
                background: none !important;
                padding: 0 !important;
            }

            .page-container {
                box-shadow: none !important;
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border-radius: 0 !important;
            }
        }
    </style>
</head>

<body>

    <!-- PAGE CONTAINER -->
    <div class="page-container">

        <!-- HEADER -->
        <div class="report-header">
            <div>
                <h1><i class="fas fa-building mr-2"></i> EAPRIMUS</h1>
                <div class="sub-title">
                    <?= $isTr ? 'Personel & Kullanıcı Listesi Raporu' : 'Personnel & User List Report' ?>
                    <?php if ($view_deleted): ?>
                        <span style="color:#ef4444; font-size:12px;">(<?= $isTr ? 'Çöp Kutusu / Silinenler' : 'Deleted Users' ?>)</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="meta">
                <div><strong><?= $isTr ? 'Rapor Tarihi:' : 'Report Date:' ?></strong> <?= date("d.m.Y - H:i") ?></div>
                <div><strong><?= $isTr ? 'Toplam Kayıt:' : 'Total Records:' ?></strong> <?= count($users) ?></div>
                <div><strong><?= $isTr ? 'Oluşturan:' : 'Generated By:' ?></strong> <?= htmlspecialchars($_SESSION['fullname'] ?? 'Sistem Admin') ?></div>
            </div>
        </div>

        <!-- TABLE -->
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 22%;"><?= $isTr ? 'İsim Soyisim' : 'Full Name' ?></th>
                    <?php if ($show_tc): ?>
                        <th style="width: 15%;"><?= $isTr ? 'TC Kimlik No' : 'ID Number' ?></th>
                    <?php endif; ?>
                    <th style="width: 15%;"><?= $isTr ? 'Kullanıcı Adı' : 'Username' ?></th>
                    <th style="width: 18%;"><?= $isTr ? 'Bölüm / Departman' : 'Department' ?></th>
                    <th style="width: 20%;"><?= $isTr ? 'E-Posta' : 'Email' ?></th>
                    <th style="width: 10%;"><?= $isTr ? 'Rol' : 'Role' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="<?= $show_tc ? '7' : '6' ?>" style="text-align:center; padding:30px; color:#6b7280;">
                            <?= $isTr ? 'Gösterilecek kullanıcı bulunamadı.' : 'No users found.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($users as $u): ?>
                        <tr>
                            <td style="color:#6b7280; text-align:center; font-weight:600;"><?= $idx++ ?></td>
                            <td style="font-weight:700; color:#111827;"><?= htmlspecialchars($u['fullname'] ?? '—') ?></td>
                            <?php if ($show_tc): ?>
                                <td style="font-family: 'Courier New', monospace; font-weight:700; letter-spacing:0.5px; color:#374151;">
                                    <?= htmlspecialchars($u['tc_no'] ?? '—') ?>
                                </td>
                            <?php endif; ?>
                            <td style="color:#4b5563;">@<?= htmlspecialchars($u['username'] ?? '—') ?></td>
                            <td style="font-weight:600; color:#1e40af;"><?= htmlspecialchars($u['bolum_adi'] ?: '—') ?></td>
                            <td style="font-size:10px; color:#4b5563;"><?= htmlspecialchars($u['mail'] ?: '—') ?></td>
                            <td>
                                <?php
                                $r = $u['role'] ?? 2;
                                if ($r == 1) {
                                    echo '<span class="badge-role badge-admin">' . ($isTr ? 'Admin' : 'Admin') . '</span>';
                                } elseif ($r == 3) {
                                    echo '<span class="badge-role badge-hr">' . ($isTr ? 'İK' : 'HR') . '</span>';
                                } else {
                                    echo '<span class="badge-role badge-staff">' . ($isTr ? 'Personel' : 'Staff') . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

    </div>

    <script>
        window.onload = function () {
            setTimeout(function () {
                window.print();
            }, 400);
        };
    </script>

</body>

</html>