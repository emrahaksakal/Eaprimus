<?php
/**
 * Güvenli Dosya ve Tutanak Gateway Geçidi
 * Yetkisiz kullanıcıların doğrudan URL'den belge indirmesini engeller.
 */

// Giriş yapılmış mı kontrol et (Oturum yoksa login sayfasına atar)
requireLogin();

$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user_role = $_SESSION['role'] ?? 3; // 1: Admin, 3: Tech Support, 2: Personnel
$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

/**
 * Helper function to serve files securely with correct content disposition and MIME type
 */
if (!function_exists('serveFileSecurely')) {
    // ----------------------------------------------------
    // PHASE 0: HELPER FUNCTIONS
    // ----------------------------------------------------
    function serveFileSecurely($fullPath, $dispName = null, $customMime = null) {
        if (ob_get_level()) {
            ob_end_clean();
        }
        if ($dispName === null) {
            $dispName = basename($fullPath);
        }
        
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimeType = $customMime ?: 'application/octet-stream';
        $inline = false;
        
        if ($ext === 'pdf') {
            $mimeType = 'application/pdf';
            $inline = true;
        } elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'])) {
            $mimeType = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
            $inline = true;
        }
        
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $dispName . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
}

/**
 * Retrieves details of a file from the database to map physical names to user-friendly titles and assets.
 */
if (!function_exists('getFileDbDetails')) {
    function getFileDbDetails($file, $pdo) {
        if (empty($file) || strpos($file, '.') === 0) return null;
        
        // 1. Check ticket_attachments
        $stmt = $pdo->prepare("SELECT ta.ticket_id, ta.file_name, t.title as ticket_title 
                               FROM ticket_attachments ta 
                               LEFT JOIN tickets t ON t.id = ta.ticket_id 
                               WHERE ta.file_path LIKE ? OR ta.file_name = ?");
        $stmt->execute(['%' . $file, $file]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'original_name' => $row['file_name'],
                'info' => 'Destek Bileti #' . $row['ticket_id'] . ' (' . ($row['ticket_title'] ?: 'Başlıksız') . ')'
            ];
        }
        
        // 2. Check attachments
        $stmt = $pdo->prepare("SELECT a.file_name, a.entity_type, a.entity_id, a.document_type
                               FROM attachments a 
                               WHERE a.file_path LIKE ? OR a.file_name = ?");
        $stmt->execute(['%' . $file, $file]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $info = '';
            $entity_type = $row['entity_type'];
            $entity_id = intval($row['entity_id']);
            if ($entity_type === 'asset') {
                $stmtAsset = $pdo->prepare("SELECT asset_tag, name FROM assets WHERE id = ?");
                $stmtAsset->execute([$entity_id]);
                $asset = $stmtAsset->fetch(PDO::FETCH_ASSOC);
                $info = 'Zimmetli Varlık #' . ($asset['asset_tag'] ?? $entity_id) . ' (' . ($asset['name'] ?? 'Bilinmeyen Varlık') . ')';
            } elseif ($entity_type === 'accessory') {
                $stmtAcc = $pdo->prepare("SELECT name FROM asset_accessories WHERE id = ?");
                $stmtAcc->execute([$entity_id]);
                $acc = $stmtAcc->fetchColumn();
                $info = 'Aksesuar #' . $entity_id . ' (' . ($acc ?: 'Bilinmeyen') . ')';
            } elseif ($entity_type === 'component') {
                $stmtComp = $pdo->prepare("SELECT name FROM asset_components WHERE id = ?");
                $stmtComp->execute([$entity_id]);
                $comp = $stmtComp->fetchColumn();
                $info = 'Bileşen #' . $entity_id . ' (' . ($comp ?: 'Bilinmeyen') . ')';
            } elseif ($entity_type === 'license') {
                $stmtLic = $pdo->prepare("SELECT software_name FROM asset_licenses WHERE id = ?");
                $stmtLic->execute([$entity_id]);
                $lic = $stmtLic->fetchColumn();
                $info = 'Lisans #' . $entity_id . ' (' . ($lic ?: 'Bilinmeyen') . ')';
            } else {
                $info = ucfirst($entity_type) . ' #' . $entity_id;
            }
            
            $docTypeStr = '';
            if ($row['document_type'] === 'handover') {
                $docTypeStr = ' (Teslim Tutanağı)';
            } elseif ($row['document_type'] === 'return') {
                $docTypeStr = ' (İade Tutanağı)';
            }
            
            return [
                'original_name' => $row['file_name'],
                'info' => $info . $docTypeStr
            ];
        }
        
        return null;
    }
}

// ----------------------------------------------------
// PHASE 1: EARLY FILE DELIVERY
// ----------------------------------------------------
if (isset($view_attachment_delivery_phase) && $view_attachment_delivery_phase === true) {
    
    // 1. Check recovery file downloads
    if (isset($_GET['recover_file'])) {
        if (!($current_user_role == 1 || $current_user_role == 3)) {
            header("HTTP/1.1 403 Forbidden");
            die("Yetkisiz erişim.");
        }
        $rFile = trim($_GET['recover_file']);
        $rFile = str_replace(['..', '\\'], ['', '/'], $rFile);
        $rFile = ltrim($rFile, '/\\');
        $fullPath = __DIR__ . '/../../' . $rFile;
        
        if (file_exists($fullPath) && (strpos($rFile, 'app/storage/') === 0 || strpos($rFile, 'public/uploads/') === 0)) {
            serveFileSecurely($fullPath);
        } else {
            header("HTTP/1.1 404 Not Found");
            die("Dosya bulunamadı veya yetkisiz dizin.");
        }
    }
    
    // 2. Check database attachment downloads
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM attachments WHERE id = ?");
        $stmt->execute([$id]);
        $atch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$atch) {
            $stmtT = $pdo->prepare("SELECT * FROM ticket_attachments WHERE id = ?");
            $stmtT->execute([$id]);
            $atchT = $stmtT->fetch(PDO::FETCH_ASSOC);
            if ($atchT) {
                $atch = [
                    'id' => $atchT['id'],
                    'file_name' => $atchT['file_name'],
                    'file_path' => 'public/' . ltrim($atchT['file_path'], '/'),
                    'file_type' => $atchT['file_type'],
                    'file_size' => $atchT['file_size'],
                    'uploaded_by' => $atchT['uploader_id'],
                    'entity_type' => 'ticket',
                    'entity_id' => $atchT['ticket_id'],
                    'document_type' => ''
                ];
            }
        }
        
        if ($atch) {
            // Permission check
            $has_permission = false;
            if ($current_user_role == 1 || $current_user_role == 3) {
                $has_permission = true;
            } else {
                if ($atch['uploaded_by'] == $current_user_id) {
                    $has_permission = true;
                } elseif ($atch['entity_type'] === 'asset') {
                    $stmtAsset = $pdo->prepare("SELECT user_id, assigned_user_id FROM assets WHERE id = ?");
                    $stmtAsset->execute([$atch['entity_id']]);
                    $asset = $stmtAsset->fetch(PDO::FETCH_ASSOC);
                    if ($asset && ($asset['user_id'] == $current_user_id || $asset['assigned_user_id'] == $current_user_id)) {
                        $has_permission = true;
                    } else {
                        $stmtSig = $pdo->prepare("SELECT id FROM asset_signatures WHERE asset_id = ? AND user_id = ?");
                        $stmtSig->execute([$atch['entity_id'], $current_user_id]);
                        if ($stmtSig->fetch()) {
                            $has_permission = true;
                        }
                    }
                } elseif ($atch['entity_type'] === 'accessory') {
                    $stmtAcc = $pdo->prepare("SELECT id FROM asset_accessory_checkouts WHERE accessory_id = ? AND user_id = ?");
                    $stmtAcc->execute([$atch['entity_id'], $current_user_id]);
                    if ($stmtAcc->fetch()) {
                        $has_permission = true;
                    } else {
                        $stmtSig = $pdo->prepare("SELECT id FROM asset_signatures WHERE accessory_id = ? AND user_id = ?");
                        $stmtSig->execute([$atch['entity_id'], $current_user_id]);
                        if ($stmtSig->fetch()) {
                            $has_permission = true;
                        }
                    }
                } elseif ($atch['entity_type'] === 'license') {
                    $stmtLic = $pdo->prepare("SELECT id FROM asset_license_checkouts WHERE license_id = ? AND user_id = ?");
                    $stmtLic->execute([$atch['entity_id'], $current_user_id]);
                    if ($stmtLic->fetch()) {
                        $has_permission = true;
                    } else {
                        $stmtSig = $pdo->prepare("SELECT id FROM asset_signatures WHERE license_id = ? AND user_id = ?");
                        $stmtSig->execute([$atch['entity_id'], $current_user_id]);
                        if ($stmtSig->fetch()) {
                            $has_permission = true;
                        }
                    }
                } elseif ($atch['entity_type'] === 'component') {
                    $stmtComp = $pdo->prepare("SELECT id FROM asset_components WHERE id = ? AND assigned_user_id = ?");
                    $stmtComp->execute([$atch['entity_id'], $current_user_id]);
                    if ($stmtComp->fetch()) {
                        $has_permission = true;
                    } else {
                        $stmtCompCheckout = $pdo->prepare("SELECT id FROM asset_component_checkouts WHERE component_id = ? AND user_id = ?");
                        $stmtCompCheckout->execute([$atch['entity_id'], $current_user_id]);
                        if ($stmtCompCheckout->fetch()) {
                            $has_permission = true;
                        } else {
                            $stmtSig = $pdo->prepare("SELECT id FROM asset_signatures WHERE component_id = ? AND user_id = ?");
                            $stmtSig->execute([$atch['entity_id'], $current_user_id]);
                            if ($stmtSig->fetch()) {
                                $has_permission = true;
                            }
                        }
                    }
                }
            }
            
            if (!$has_permission) {
                header("HTTP/1.1 403 Forbidden");
                die("Bu belgeyi görüntülemek için yetkiniz bulunmamaktadır.");
            }
            
            $fullPath = __DIR__ . '/../../' . $atch['file_path'];
            if (file_exists($fullPath)) {
                if (!function_exists('convertTurkishToAsciiLocal')) {
                    function convertTurkishToAsciiLocal($str) {
                        $chars = [
                            'Ş' => 'S', 'ş' => 's', 'Ç' => 'C', 'ç' => 'c',
                            'Ğ' => 'G', 'ğ' => 'g', 'İ' => 'I', 'ı' => 'i',
                            'Ö' => 'O', 'ö' => 'o', 'Ü' => 'U', 'ü' => 'u'
                        ];
                        return strtr($str, $chars);
                    }
                }
                $dispName = basename($atch['file_name']);
                $docType = $atch['document_type'] ?? '';
                $entityType = $atch['entity_type'] ?? '';
                $prefix = '';
                if ($docType === 'handover') {
                    if ($entityType === 'accessory') {
                        $prefix = $isTr ? 'Aksesuar Teslim Tutanağı' : 'Accessory Delivery Report';
                    } elseif ($entityType === 'component') {
                        $prefix = $isTr ? 'Bileşen Teslim Tutanağı' : 'Component Delivery Report';
                    } elseif ($entityType === 'license') {
                        $prefix = $isTr ? 'Lisans Teslim Tutanağı' : 'License Delivery Report';
                    } else {
                        $prefix = $isTr ? 'Donanım Teslim Tutanağı' : 'Hardware Delivery Report';
                    }
                } elseif ($docType === 'return') {
                    if ($entityType === 'accessory') {
                        $prefix = $isTr ? 'Aksesuar İade Tutanağı' : 'Accessory Return Report';
                    } elseif ($entityType === 'component') {
                        $prefix = $isTr ? 'Bileşen İade Tutanağı' : 'Component Return Report';
                    } elseif ($entityType === 'license') {
                        $prefix = $isTr ? 'Lisans İade Tutanağı' : 'License Return Report';
                    } else {
                        $prefix = $isTr ? 'Donanım İade Tutanağı' : 'Hardware Return Report';
                    }
                }
                
                $cleanDispName = $dispName;
                $possiblePrefixes = [
                    'Donanim Teslim Tutanagi', 'Donanim_Teslim_Tutanagi', 'Hardware Delivery Report', 'Hardware Handover Protocol',
                    'Donanim Iade Tutanagi', 'Donanim_Iade_Tutanagi', 'Hardware Return Report', 'Hardware Return Protocol',
                    'Aksesuar Teslim Tutanagi', 'Aksesuar_Teslim_Tutanagi', 'Accessory Delivery Report', 'Accessory Handover Protocol',
                    'Aksesuar Iade Tutanagi', 'Aksesuar_Iade_Tutanagi', 'Accessory Return Report', 'Accessory Return Protocol',
                    'Bilesen Teslim Tutanagi', 'Bilesen_Teslim_Tutanagi', 'Component Delivery Report', 'Component Handover Protocol',
                    'Bilesen Iade Tutanagi', 'Bilesen_Iade_Tutanagi', 'Component Return Report', 'Component Return Protocol',
                    'Lisans Teslim Tutanagi', 'Lisans_Teslim_Tutanagi', 'License Delivery Report', 'License Handover Protocol',
                    'Lisans Iade Tutanagi', 'Lisans_Iade_Tutanagi', 'License Return Report', 'License Return Protocol'
                ];
                foreach ($possiblePrefixes as $p) {
                    if (stripos($cleanDispName, $p . ' - ') === 0) {
                        $cleanDispName = substr($cleanDispName, strlen($p . ' - '));
                        break;
                    }
                    if (stripos($cleanDispName, $p) === 0) {
                        $cleanDispName = substr($cleanDispName, strlen($p));
                        break;
                    }
                }
                
                if (!empty($prefix)) {
                    $cleanPrefix = convertTurkishToAsciiLocal($prefix);
                    $dispName = $cleanPrefix . ' - ' . $cleanDispName;
                } else {
                    if ($isTr) {
                        $replacements = [
                            'Hardware Delivery Report' => 'Donanim Teslim Tutanagi',
                            'Hardware Handover Protocol' => 'Donanim Teslim Tutanagi',
                            'Hardware Return Report' => 'Donanim Iade Tutanagi',
                            'Hardware Return Protocol' => 'Donanim Iade Tutanagi',
                            'Accessory Delivery Report' => 'Aksesuar Teslim Tutanagi',
                            'Accessory Return Report' => 'Aksesuar Iade Tutanagi',
                            'Component Delivery Report' => 'Bilesen Teslim Tutanagi',
                            'Component Return Report' => 'Bilesen Iade Tutanagi',
                            'License Delivery Report' => 'Lisans Teslim Tutanagi',
                            'License Return Report' => 'Lisans Iade Tutanagi',
                        ];
                    } else {
                        $replacements = [
                            'Donanim Teslim Tutanagi' => 'Hardware Delivery Report',
                            'Donanim_Teslim_Tutanagi' => 'Hardware Delivery Report',
                            'Donanim Iade Tutanagi' => 'Hardware Return Report',
                            'Donanim_Iade_Tutanagi' => 'Hardware Return Report',
                            'Aksesuar Teslim Tutanagi' => 'Accessory Delivery Report',
                            'Aksesuar_Teslim_Tutanagi' => 'Accessory Delivery Report',
                            'Aksesuar Iade Tutanagi' => 'Accessory Return Report',
                            'Aksesuar_Iade_Tutanagi' => 'Accessory Return Report',
                            'Bilesen Teslim Tutanagi' => 'Component Delivery Report',
                            'Bilesen_Teslim_Tutanagi' => 'Component Delivery Report',
                            'Bilesen Iade Tutanagi' => 'Component Return Report',
                            'Bilesen_Iade_Tutanagi' => 'Component Return Report',
                            'Lisans Teslim Tutanagi' => 'License Delivery Report',
                            'Lisans_Teslim_Tutanagi' => 'License Delivery Report',
                            'Lisans Iade Tutanagi' => 'License Return Report',
                            'Lisans_Iade_Tutanagi' => 'License Return Report',
                        ];
                    }
                    foreach ($replacements as $search => $replace) {
                        if (stripos($dispName, $search) !== false) {
                            $dispName = str_ireplace($search, $replace, $dispName);
                            break;
                        }
                    }
                }
                
                serveFileSecurely($fullPath, $dispName, $atch['file_type']);
            }
        }
    }
    
    // Delivery phase ends here; if not exited, it means file was not found
    return;
}

// ----------------------------------------------------
// PHASE 2: PAGE RENDERING (RECOVERY PANEL CARD)
// ----------------------------------------------------
if ($current_user_role == 1 || $current_user_role == 3) {
    $id = intval($_GET['id'] ?? 0);
    $searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
    $fileTypeFilter = $_GET['file_type'] ?? 'all';
    $foundFiles = [];
    
    $directories = [
        'app/storage/attachments/' => __DIR__ . '/../../app/storage/attachments/',
        'app/storage/signatures/' => __DIR__ . '/../../app/storage/signatures/',
        'public/uploads/tickets/' => __DIR__ . '/../../public/uploads/tickets/'
    ];
    
    foreach ($directories as $webDir => $dirPath) {
        if (is_dir($dirPath)) {
            $files = scandir($dirPath);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                if (strpos($file, '.') === 0) continue; // Skip hidden and .gitkeep files
                if ($searchQuery !== '' && stripos($file, $searchQuery) === false) continue;
                
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if ($fileTypeFilter === 'pdf' && $ext !== 'pdf') continue;
                if ($fileTypeFilter === 'image' && !in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'])) continue;
                
                $filePath = $dirPath . $file;
                if (is_file($filePath)) {
                    global $pdo;
                    $dbDetails = getFileDbDetails($file, $pdo);
                    $originalName = $dbDetails ? $dbDetails['original_name'] : $file;
                    $infoText = $dbDetails ? $dbDetails['info'] : '';
                    
                    $foundFiles[] = [
                        'name' => $file,
                        'original_name' => $originalName,
                        'info' => $infoText,
                        'path' => $webDir . $file,
                        'size' => filesize($filePath),
                        'date' => date('d.m.Y H:i:s', filemtime($filePath))
                    ];
                }
            }
        }
    }
    
    usort($foundFiles, function($a, $b) {
        return filemtime(__DIR__ . '/../../' . $b['path']) - filemtime(__DIR__ . '/../../' . $a['path']);
    });
    
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-12 mt-0">
            <div class="card card-danger card-outline mb-4 shadow-sm border-top-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 font-weight-bold text-danger">
                        <i class="fas fa-file-circle-exclamation mr-2"></i> <?= $isTr ? 'Belge Bulunamadı / Kurtarma Paneli' : 'Document Not Found / Recovery Panel' ?>
                    </h5>
                    <span class="badge badge-danger ml-auto"><?= $isTr ? 'Yönetici Yetkisi' : 'Admin Access' ?></span>
                </div>
                <div class="card-body py-3">
                    <div class="alert alert-warning mb-4">
                        <strong><?= $isTr ? 'Hata:' : 'Error:' ?></strong> <?= $isTr ? 'İstenen dosya kaydı veritabanında veya sunucu diskinde bulunamadı' : 'Requested file record was not found in database or server disk' ?> (ID: <strong><?= $id ?></strong>).
                    </div>
                    
                    <p class="text-muted"><?= $isTr ? 'Aşağıdaki arama alanını kullanarak sunucu üzerindeki fiziksel <strong>attachments</strong> ve <strong>tickets</strong> klasörlerindeki dosyaları arayabilir ve kurtarabilirsiniz:' : 'Use the search filters below to scan and recover files from the physical <strong>attachments</strong> and <strong>tickets</strong> directories on the server:' ?></p>
                    
                    <form method="GET" action="dashboard" class="mb-4">
                        <input type="hidden" name="route" value="view_attachment">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <input type="text" name="search" class="form-control" placeholder="<?= $isTr ? 'Dosya adı ile ara (Örn: tutanak, fatura)...' : 'Search by file name (e.g. protocol, invoice)...' ?>" value="<?= htmlspecialchars($searchQuery) ?>" style="border-radius: 10px;">
                            </div>
                            <div class="col-md-4 mb-2">
                                <select name="file_type" class="form-control" style="border-radius: 10px;">
                                    <option value="all" <?= $fileTypeFilter === 'all' ? 'selected' : '' ?>><?= $isTr ? 'Tüm Dosyalar' : 'All Files' ?></option>
                                    <option value="pdf" <?= $fileTypeFilter === 'pdf' ? 'selected' : '' ?>><?= $isTr ? 'Yalnızca PDF (.pdf)' : 'PDF Only (.pdf)' ?></option>
                                    <option value="image" <?= $fileTypeFilter === 'image' ? 'selected' : '' ?>><?= $isTr ? 'Yalnızca Resim (.png, .jpg, vb.)' : 'Images Only (.png, .jpg, etc.)' ?></option>
                                </select>
                            </div>
                            <div class="col-md-2 mb-2">
                                <button type="submit" class="btn btn-primary btn-block px-4" style="border-radius: 10px;"><i class="fas fa-search"></i> <?= $isTr ? 'Ara' : 'Search' ?></button>
                            </div>
                        </div>
                    </form>
                    
                    <div class="table-responsive text-nowrap">
                        <table class="table table-hover border mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th><?= $isTr ? 'Klasör / Dosya Adı' : 'Directory / File Name' ?></th>
                                    <th><?= $isTr ? 'Boyut' : 'Size' ?></th>
                                    <th><?= $isTr ? 'Değiştirilme Tarihi' : 'Last Modified' ?></th>
                                    <th class="text-right"><?= $isTr ? 'İşlem' : 'Action' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($foundFiles)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4"><?= $isTr ? 'Aranan kriterlere uygun fiziksel dosya bulunamadı.' : 'No matching physical files found.' ?></td>
                                    </tr>
                                <?php else: foreach ($foundFiles as $file): ?>
                                    <?php
                                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                                    $iconClass = 'fa-file-alt text-secondary';
                                    if ($ext === 'pdf') {
                                        $iconClass = 'fa-file-pdf text-danger';
                                    } elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'])) {
                                        $iconClass = 'fa-file-image text-warning';
                                    }
                                    ?>
                                    <tr class="file-row">
                                        <td class="align-middle">
                                            <i class="far <?= $iconClass ?> mr-2 fa-lg"></i>
                                            <span class="font-weight-bold text-dark" style="font-size: 1.05rem;"><?= htmlspecialchars($file['original_name']) ?></span>
                                            <?php if ($file['original_name'] !== $file['name']): ?>
                                                <span class="text-muted ml-1" style="font-size: 85%;">(Fiziksel: <code><?= htmlspecialchars($file['name']) ?></code>)</span>
                                            <?php endif; ?>
                                            <?php if (!empty($file['info'])): ?>
                                                <br><small class="text-primary font-weight-bold" style="font-size: 85%;"><i class="fas fa-info-circle mr-1"></i> <?= htmlspecialchars($file['info']) ?></small>
                                            <?php endif; ?>
                                            <br><small class="text-muted" style="font-size: 80%;"><?= $isTr ? 'Dizin' : 'Directory' ?>: <code><?= htmlspecialchars(dirname($file['path'])) ?></code></small>
                                        </td>
                                        <td class="align-middle font-weight-600 text-dark"><?= round($file['size'] / 1024, 1) ?> KB</td>
                                        <td class="align-middle text-muted" style="font-size: 90%;"><?= $file['date'] ?></td>
                                        <td class="align-middle text-right">
                                            <a href="dashboard?route=view_attachment&recover_file=<?= urlencode($file['path']) ?>" class="btn btn-sm btn-outline-success font-weight-bold px-3 py-1.5" target="_blank" style="border-radius: 6px;">
                                                <i class="fas fa-eye mr-1"></i> <?= $isTr ? 'İndir / Görüntüle' : 'View / Download' ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
} else {
    header("HTTP/1.1 404 Not Found");
    die("Belge bulunamadı.");
}
?>
