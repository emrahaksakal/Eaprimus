<?php
// app/pages/toplu_ice_aktar.php - Bulk Excel/CSV Import Module for Eaprimus

if (!defined('PDO') && !isset($pdo)) {
    header("Location: ../public/dashboard.php?route=toplu_ice_aktar");
    exit();
}

// Security Check (Only Admin / Supervisor)
if (($current_user_role ?? 3) == 3) {
    echo '<div class="alert alert-danger m-4">Bu sayfaya erişim yetkiniz bulunmamaktadır.</div>';
    return;
}

$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

// -------------------------------------------------------------
// 1. NATIVE EXCEL (XML SPREADSHEET) TEMPLATE GENERATOR
// Opens in Excel with wide formatted columns (no truncated text!)
// -------------------------------------------------------------
if (isset($_GET['download_template'])) {
    $type = $_GET['download_template'];
    ob_clean();
    
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    
    if ($type === 'users') {
        header('Content-Disposition: attachment; filename="eaprimus_kullanicilar_sablon.xls"');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="HeaderStyle">
   <Font ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#1E293B" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="DataStyle">
   <Alignment ss:Vertical="Center"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Kullanıcı Listesi">
  <Table>
   <Column ss:Width="140"/>
   <Column ss:Width="170"/>
   <Column ss:Width="240"/>
   <Column ss:Width="170"/>
   <Column ss:Width="170"/>
   <Column ss:Width="140"/>
   <Column ss:Width="100"/>
   <Column ss:Width="110"/>
   <Row ss:Height="26">
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Kullanici_Adi</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Ad_Soyad</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Eposta</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Sirket</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Bolum</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Rol</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Durum</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Sifre</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ahmet.yilmaz</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Ahmet Yılmaz</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ahmet.yilmaz@sirket.com</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ABC Teknoloji</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Bilgi Teknolojileri</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Teknik Personel</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Aktif</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String"></Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">mehmet.kaya</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Mehmet Kaya</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">mehmet.kaya@sirket.com</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ABC Teknoloji</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Muhasebe</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Personel</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Aktif</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String"></Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ayse.demir</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Ayşe Demir</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ayse.demir@sirket.com</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ABC Teknoloji</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">İnsan Kaynakları</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Süper Admin</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Aktif</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String"></Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">fatma.sahin</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Fatma Şahin</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">fatma.sahin@sirket.com</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ABC Teknoloji</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Pazarlama</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Personel</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Pasif</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String"></Data></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
        <?php
        exit();
    } elseif ($type === 'assets') {
        header('Content-Disposition: attachment; filename="eaprimus_cihazlar_sablon.xls"');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="HeaderStyle">
   <Font ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#0284C7" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="DataStyle">
   <Alignment ss:Vertical="Center"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Cihaz Listesi">
  <Table>
   <Column ss:Width="140"/>
   <Column ss:Width="140"/>
   <Column ss:Width="170"/>
   <Column ss:Width="130"/>
   <Column ss:Width="160"/>
   <Column ss:Width="160"/>
   <Column ss:Width="160"/>
   <Column ss:Width="240"/>
   <Column ss:Width="120"/>
   <Column ss:Width="90"/>
   <Column ss:Width="110"/>
   <Column ss:Width="140"/>
   <Row ss:Height="26">
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Demirbas_Etiketi</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Seri_No</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Cihaz_Adi</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Kategori</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Sirket</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Bolum</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Lokasyon</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Zimmetli_Kullanici_Eposta</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">CPU</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">RAM</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Disk</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Isletim_Sistemi</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">TAG-1001</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">SN-98765432</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">MacBook Pro 14</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Laptop</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ABC Teknoloji</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Bilgi Teknolojileri</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">İstanbul Ofisi</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ahmet.yilmaz@sirket.com</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Apple M3</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">16GB</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">512GB SSD</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">macOS Sonoma</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">TAG-1002</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">SN-11223344</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ThinkPad T14</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Laptop</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ABC Teknoloji</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Muhasebe</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Ankara Ofisi</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">mehmet.kaya@sirket.com</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Intel i7</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">32GB</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">1TB SSD</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Windows 11 Pro</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">TAG-1003</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">SN-55667788</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">UltraSharp 27</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Monitör</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ABC Teknoloji</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">Tasarım</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">İstanbul Ofisi</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">ayse.demir@sirket.com</Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String"></Data></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
        <?php
        exit();
    }
}

// -------------------------------------------------------------
// 2. FILE PARSER (SUPPORTS BOTH XML SPREADSHEET .XLS AND .CSV)
// -------------------------------------------------------------
function parse_uploaded_file($file_path) {
    $rows_data = [];
    if (!file_exists($file_path)) return $rows_data;
    
    $content = file_get_contents($file_path);
    if (empty($content)) return $rows_data;
    
    // Check if file is XML Spreadsheet 2003
    if (strpos($content, '<Workbook') !== false && strpos($content, '<Table') !== false) {
        $xml = @simplexml_load_string($content);
        if ($xml) {
            $xml->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
            $rows = $xml->xpath('//ss:Table/ss:Row') ?: $xml->xpath('//Table/Row');
            
            if ($rows) {
                foreach ($rows as $row) {
                    $row_arr = [];
                    $cells = $row->xpath('ss:Cell') ?: $row->xpath('Cell');
                    foreach ($cells as $cell) {
                        $data_nodes = $cell->xpath('ss:Data') ?: $cell->xpath('Data');
                        $val = (count($data_nodes) > 0) ? (string)$data_nodes[0] : (string)$cell;
                        $row_arr[] = trim($val);
                    }
                    if (!empty(array_filter($row_arr))) {
                        $rows_data[] = $row_arr;
                    }
                }
            }
        }
    }
    
    // If not XML or XML parsing returned nothing, parse as CSV/TXT
    if (empty($rows_data)) {
        $handle = @fopen($file_path, 'r');
        if ($handle) {
            $first_line = fgets($handle);
            if ($first_line !== false) {
                if (substr($first_line, 0, 3) === "\xEF\xBB\xBF") {
                    $first_line = substr($first_line, 3);
                }
                $delimiter = (substr_count($first_line, ';') >= substr_count($first_line, ',')) ? ';' : ',';
                
                rewind($handle);
                
                while (($r = fgetcsv($handle, 3000, $delimiter)) !== FALSE) {
                    if (!empty(array_filter($r))) {
                        $rows_data[] = array_map('trim', $r);
                    }
                }
            }
            fclose($handle);
        }
    }
    
    return $rows_data;
}

// -------------------------------------------------------------
// 3. FILE UPLOAD & DATABASE PROCESSOR
// -------------------------------------------------------------
$import_results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_import') {
    $import_type = $_POST['import_type'] ?? 'users';
    
    try {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file_path = $_FILES['csv_file']['tmp_name'];
            $parsed_rows = parse_uploaded_file($file_path);
        
        if (!empty($parsed_rows) && count($parsed_rows) > 1) {
            $headers = array_shift($parsed_rows); // First row is header
            
            $added_count = 0;
            $updated_count = 0;
            $unchanged_count = 0;
            $error_rows = [];
            $line_num = 1;
            
            if ($import_type === 'users') {
                foreach ($parsed_rows as $row) {
                    $line_num++;
                    
                    $data = [];
                    foreach ($headers as $idx => $header_name) {
                        $clean_header = mb_strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', $header_name)));
                        $data[$clean_header] = isset($row[$idx]) ? trim($row[$idx]) : '';
                    }
                    
                    $username = $data['kullanici_adi'] ?? $data['username'] ?? $data['kullaniciadi'] ?? '';
                    $fullname = $data['ad_soyad'] ?? $data['fullname'] ?? $data['adsoyad'] ?? '';
                    $email    = $data['eposta'] ?? $data['email'] ?? $data['mail'] ?? '';
                    $sirket   = $data['sirket'] ?? $data['company'] ?? '';
                    $bolum    = $data['bolum'] ?? $data['department'] ?? $data['departman'] ?? '';
                    
                    // Parse Role (Text or Numeric)
                    $role_raw = mb_strtolower(trim($data['rol'] ?? $data['role'] ?? '2'));
                    if (strpos($role_raw, 'admin') !== false || strpos($role_raw, 'yönetici') !== false || $role_raw === '1') {
                        $role = 1; // Admin / Süper Admin
                    } elseif (strpos($role_raw, 'teknik') !== false || strpos($role_raw, 'supervisor') !== false || strpos($role_raw, 'teknisyen') !== false || $role_raw === '3') {
                        $role = 3; // Supervisor / Teknik Personel
                    } else {
                        $role = 2; // Personel / Standart Kullanıcı
                    }
                    
                    // Parse Status (Aktif / Pasif)
                    $status_raw = mb_strtolower(trim($data['durum'] ?? $data['status'] ?? 'aktif'));
                    if (strpos($status_raw, 'pasif') !== false || $status_raw === '0' || strpos($status_raw, 'engelli') !== false || strpos($status_raw, 'hayır') !== false) {
                        $status = 0; // Pasif (Giriş Yapamaz)
                    } else {
                        $status = 1; // Aktif (Giriş Yapabilir)
                    }
                    
                    // Parse Password (Güvenlik Önlemi: Boş bırakılırsa her kullanıcıya benzersiz rastgele güvenli şifre atanır)
                    $password_plain = trim($data['sifre'] ?? $data['password'] ?? '');
                    if (empty($password_plain)) {
                        $password_plain = bin2hex(random_bytes(5)) . '!' . rand(10, 99); // Benzersiz rastgele şifre
                    }
                    $password_hash = password_hash($password_plain, PASSWORD_BCRYPT);
                    
                    if (empty($username) && !empty($email)) {
                        $username = explode('@', $email)[0];
                    }
                    if (empty($username)) {
                        $error_rows[] = "Satır $line_num: Kullanıcı adı veya E-posta boş olamaz.";
                        continue;
                    }
                    
                    // Check if user exists
                    $stmtCheck = $pdo->prepare("SELECT id, fullname, mail, email, sirket_ismi, bolum, role, status FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?) OR (mail IS NOT NULL AND mail = ?) LIMIT 1");
                    $stmtCheck->execute([$username, $email, $email]);
                    $existing_user = $stmtCheck->fetch();
                    
                    if ($existing_user) {
                        // Check if ANY field has changed
                        $is_fullname_changed = (!empty($fullname) && trim($existing_user['fullname'] ?? '') !== $fullname);
                        $is_email_changed    = (!empty($email) && trim($existing_user['email'] ?? '') !== $email && trim($existing_user['mail'] ?? '') !== $email);
                        $is_sirket_changed   = (!empty($sirket) && trim($existing_user['sirket_ismi'] ?? '') !== $sirket);
                        $is_bolum_changed    = (!empty($bolum) && trim($existing_user['bolum'] ?? '') !== $bolum);
                        $is_role_changed     = ((int)$existing_user['role'] !== $role);
                        $is_status_changed   = ((int)$existing_user['status'] !== $status);
                        
                        $has_any_change = ($is_fullname_changed || $is_email_changed || $is_sirket_changed || $is_bolum_changed || $is_role_changed || $is_status_changed);
                        
                        if ($has_any_change) {
                            $stmtUpd = $pdo->prepare("UPDATE users SET fullname = ?, mail = ?, email = ?, sirket_ismi = ?, bolum = ?, role = ?, status = ? WHERE id = ?");
                            $stmtUpd->execute([$fullname, $email, $email, $sirket, $bolum, $role, $status, $existing_user['id']]);
                            $updated_count++;
                            
                            // Log activity ONLY when updated
                            try {
                                $stmtLogU = $pdo->prepare("INSERT INTO asset_timeline (asset_id, item_type, user_id, event_type, event_description) VALUES (?, 'user', ?, 'updated', ?)");
                                $stmtLogU->execute([$existing_user['id'], $current_user_id, "Toplu Excel ile Personel Bilgileri Güncellendi: {$fullname} (@{$username})"]);
                            } catch (Exception $exL) {}
                        } else {
                            // Unchanged, skip update and skip log
                            $unchanged_count++;
                        }
                    } else {
                        // Insert new user (tc_no is NOT NULL in schema)
                        $tc_no = $data['tc_no'] ?? $data['tc'] ?? '';
                        // Varsay\u0131lan profil foto\u011fu: \u015firket logosu (ayarlardan al)
                        $defaultAvatar = s('logo_path') ?: 'logo.png';
                        if (!str_starts_with($defaultAvatar, 'public/') && !filter_var($defaultAvatar, FILTER_VALIDATE_URL)) {
                            $defaultAvatar = 'public/' . $defaultAvatar;
                        }
                        $stmtIns = $pdo->prepare("INSERT INTO users (username, password, tc_no, fullname, mail, email, sirket_ismi, bolum, role, status, profil_fotosu) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmtIns->execute([$username, $password_hash, $tc_no, $fullname, $email, $email, $sirket, $bolum, $role, $status, $defaultAvatar]);
                        $new_u_id = $pdo->lastInsertId();
                        $added_count++;
                        
                        // Log activity to asset_timeline
                        try {
                            $stmtLogU = $pdo->prepare("INSERT INTO asset_timeline (asset_id, item_type, user_id, event_type, event_description) VALUES (?, 'user', ?, 'created', ?)");
                            $stmtLogU->execute([$new_u_id, $current_user_id, "Toplu Excel ile Yeni Personel Eklendi: {$fullname} (@{$username}) - {$sirket} / {$bolum}"]);
                        } catch (Exception $exL) {}
                    }
                }
            } elseif ($import_type === 'assets') {
                foreach ($parsed_rows as $row) {
                    $line_num++;
                    
                    $data = [];
                    foreach ($headers as $idx => $header_name) {
                        $clean_header = mb_strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', $header_name)));
                        $data[$clean_header] = isset($row[$idx]) ? trim($row[$idx]) : '';
                    }
                    
                    $asset_tag   = $data['demirbas_etiketi'] ?? $data['asset_tag'] ?? $data['tag'] ?? '';
                    $serial_no   = $data['seri_no'] ?? $data['serial_no'] ?? $data['serial'] ?? '';
                    $device_name = $data['cihaz_adi'] ?? $data['device_name'] ?? $data['name'] ?? 'Cihaz';
                    $type        = $data['kategori'] ?? $data['category'] ?? $data['type'] ?? 'Diğer';
                    
                    $sirket   = $data['sirket'] ?? $data['company'] ?? '';
                    $bolum    = $data['bolum'] ?? $data['department'] ?? $data['departman'] ?? '';
                    $lokasyon = $data['lokasyon'] ?? $data['location'] ?? '';
                    
                    $full_location = trim(implode(' / ', array_filter([$sirket, $bolum, $lokasyon])));
                    if (empty($full_location)) $full_location = 'Merkez Ofis';
                    
                    $assigned_user_identifier = $data['zimmetli_kullanici_eposta'] ?? $data['zimmetli_kullanici'] ?? $data['assigned_to'] ?? '';
                    
                    $cpu  = $data['cpu'] ?? '';
                    $ram  = $data['ram'] ?? '';
                    $disk = $data['disk'] ?? '';
                    $os   = $data['isletim_sistemi'] ?? $data['os'] ?? '';
                    
                    if (empty($asset_tag) && empty($serial_no)) {
                        $error_rows[] = "Satır $line_num: Demirbaş etiketi veya Seri No boş olamaz.";
                        continue;
                    }
                    
                    // Match assigned user id if provided
                    $assigned_user_id = null;
                    $assigned_user_fullname = '';
                    if (!empty($assigned_user_identifier)) {
                        $stmtU = $pdo->prepare("SELECT id, fullname FROM users WHERE email = ? OR mail = ? OR username = ? LIMIT 1");
                        $stmtU->execute([$assigned_user_identifier, $assigned_user_identifier, $assigned_user_identifier]);
                        $found_user = $stmtU->fetch();
                        if ($found_user) {
                            $assigned_user_id = $found_user['id'];
                            $assigned_user_fullname = $found_user['fullname'];
                        }
                    }
                    
                    // Check if asset exists
                    $stmtCheckA = $pdo->prepare("SELECT id, device_name, serial_no, type, location, cpu, ram, disk, os, assigned_user_id FROM assets WHERE (asset_tag IS NOT NULL AND asset_tag = ?) OR (serial_no IS NOT NULL AND serial_no = ? AND serial_no != '') LIMIT 1");
                    $stmtCheckA->execute([$asset_tag, $serial_no]);
                    $existing_asset = $stmtCheckA->fetch();
                    
                    if ($existing_asset) {
                        $target_asset_id = $existing_asset['id'];
                        
                        $is_name_changed     = (!empty($device_name) && trim($existing_asset['device_name'] ?? '') !== $device_name);
                        $is_serial_changed   = (!empty($serial_no) && trim($existing_asset['serial_no'] ?? '') !== $serial_no);
                        $is_type_changed     = (!empty($type) && trim($existing_asset['type'] ?? '') !== $type);
                        $is_location_changed = (!empty($full_location) && trim($existing_asset['location'] ?? '') !== $full_location);
                        $is_cpu_changed      = (!empty($cpu) && trim($existing_asset['cpu'] ?? '') !== $cpu);
                        $is_ram_changed      = (!empty($ram) && trim($existing_asset['ram'] ?? '') !== $ram);
                        $is_disk_changed     = (!empty($disk) && trim($existing_asset['disk'] ?? '') !== $disk);
                        $is_os_changed       = (!empty($os) && trim($existing_asset['os'] ?? '') !== $os);
                        $is_assigned_changed = ((int)($existing_asset['assigned_user_id'] ?? 0) !== (int)($assigned_user_id ?? 0));
                        
                        $has_asset_change = ($is_name_changed || $is_serial_changed || $is_type_changed || $is_location_changed || $is_cpu_changed || $is_ram_changed || $is_disk_changed || $is_os_changed || $is_assigned_changed);
                        
                        if ($has_asset_change) {
                            $stmtUpdA = $pdo->prepare("UPDATE assets SET device_name = ?, name = ?, serial_no = ?, type = ?, location = ?, cpu = ?, ram = ?, disk = ?, os = ?, assigned_user_id = ?, user_id = ? WHERE id = ?");
                            $stmtUpdA->execute([$device_name, $device_name, $serial_no, $type, $full_location, $cpu, $ram, $disk, $os, $assigned_user_id, $assigned_user_id, $target_asset_id]);
                            $updated_count++;
                            
                            // Log activity ONLY when updated
                            try {
                                $stmtLogA = $pdo->prepare("INSERT INTO asset_timeline (asset_id, item_type, user_id, event_type, event_description, context_id, context_type) VALUES (?, 'asset', ?, 'updated', ?, ?, ?)");
                                $stmtLogA->execute([$target_asset_id, $current_user_id, "Toplu Excel ile Cihaz Bilgileri Güncellendi: {$device_name} ({$asset_tag}) - {$full_location}", $assigned_user_id, $assigned_user_id ? 'user' : null]);
                            } catch (Exception $exL) {}
                            
                            // Log Zimmet / Assignment event IF assignment changed
                            if ($is_assigned_changed && $assigned_user_id) {
                                try {
                                    $assign_msg = "Cihaz Personele Zimmetlendi: {$device_name} ({$asset_tag}) ➔ Zimmetlenen: {$assigned_user_fullname}";
                                    $stmtLogZ = $pdo->prepare("INSERT INTO asset_timeline (asset_id, item_type, user_id, event_type, event_description, context_id, context_type) VALUES (?, 'asset', ?, 'checkout', ?, ?, 'user')");
                                    $stmtLogZ->execute([$target_asset_id, $current_user_id, $assign_msg, $assigned_user_id]);
                                } catch (Exception $exL) {}
                            }
                        } else {
                            // Unchanged, skip update and skip log
                            $unchanged_count++;
                        }
                    } else {
                        // Insert new asset
                        $stmtInsA = $pdo->prepare("INSERT INTO assets (asset_tag, serial_no, device_name, name, type, location, cpu, ram, disk, os, assigned_user_id, user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                        $stmtInsA->execute([$asset_tag, $serial_no, $device_name, $device_name, $type, $full_location, $cpu, $ram, $disk, $os, $assigned_user_id, $assigned_user_id]);
                        $target_asset_id = $pdo->lastInsertId();
                        $added_count++;
                        
                        // Log activity to asset_timeline
                        try {
                            $stmtLogA = $pdo->prepare("INSERT INTO asset_timeline (asset_id, item_type, user_id, event_type, event_description, context_id, context_type) VALUES (?, 'asset', ?, 'created', ?, ?, ?)");
                            $stmtLogA->execute([$target_asset_id, $current_user_id, "Toplu Excel ile Yeni Cihaz Eklendi: {$device_name} ({$asset_tag}) - {$full_location}", $assigned_user_id, $assigned_user_id ? 'user' : null]);
                        } catch (Exception $exL) {}
                        
                        if ($assigned_user_id) {
                            try {
                                $assign_msg = "Cihaz Personele Zimmetlendi: {$device_name} ({$asset_tag}) ➔ Zimmetlenen: {$assigned_user_fullname}";
                                $stmtLogZ = $pdo->prepare("INSERT INTO asset_timeline (asset_id, item_type, user_id, event_type, event_description, context_id, context_type) VALUES (?, 'asset', ?, 'checkout', ?, ?, 'user')");
                                $stmtLogZ->execute([$target_asset_id, $current_user_id, $assign_msg, $assigned_user_id]);
                            } catch (Exception $exL) {}
                        }
                    }
                }
            }
            
            $import_results = [
                'type' => $import_type,
                'added' => $added_count,
                'updated' => $updated_count,
                'unchanged' => $unchanged_count,
                'errors' => $error_rows
            ];
        } else {
            $import_results = ['error' => 'Yüklenen Excel/CSV dosyasından veri okunamadı veya başlık satırı eksik.'];
        }
        } else {
            $import_results = ['error' => 'Lütfen geçerli bir Excel (.xls) veya CSV dosyası seçin.'];
        }
    } catch (Throwable $e) {
        $import_results = ['error' => 'İşlem sırasında bir hata oluştu: ' . $e->getMessage()];
    }
}
?>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0 font-weight-bold text-dark">
                    <i class="fas fa-file-import text-primary mr-2"></i><?= $isTr ? 'Toplu İçe Aktar (Excel / CSV)' : 'Bulk Import (Excel / CSV)' ?>
                </h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="anasayfa"><?= $isTr ? 'Anasayfa' : 'Home' ?></a></li>
                    <li class="breadcrumb-item active"><?= $isTr ? 'Toplu İçe Aktar' : 'Bulk Import' ?></li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if ($import_results): ?>
            <?php if (isset($import_results['error'])): ?>
                <div class="alert alert-danger shadow-sm border-0 mb-4" style="border-radius:12px;">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($import_results['error']) ?>
                </div>
            <?php else: ?>
                <?php 
                $has_changes = (($import_results['added'] ?? 0) > 0 || ($import_results['updated'] ?? 0) > 0);
                $card_border_color = $has_changes ? '#10b981' : '#3b82f6';
                $header_text_color = $has_changes ? 'text-success' : 'text-primary';
                $header_icon       = $has_changes ? 'fa-check-circle' : 'fa-info-circle';
                $header_title      = $has_changes 
                    ? ($isTr ? 'İçe Aktarma Başarıyla Tamamlandı!' : 'Import Completed Successfully!')
                    : ($isTr ? 'İşlem Yapılmadı: Yüklenen tüm veriler sistemde zaten mevcut ve güncel!' : 'No Changes Made: Uploaded data matches existing records!');
                ?>
                <div class="card shadow-sm border-0 mb-4" style="border-radius:12px; border-left: 5px solid <?= $card_border_color ?>;">
                    <div class="card-body">
                        <h5 class="font-weight-bold <?= $header_text_color ?> mb-3">
                            <i class="fas <?= $header_icon ?> mr-2"></i><?= $header_title ?>
                        </h5>
                        <div class="row">
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="p-3 bg-light rounded text-center border">
                                    <h3 class="text-success font-weight-bold mb-0"><?= $import_results['added'] ?></h3>
                                    <span class="text-muted small font-weight-bold"><?= $isTr ? 'Yeni Eklenen' : 'Newly Added' ?></span>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="p-3 bg-light rounded text-center border">
                                    <h3 class="text-info font-weight-bold mb-0"><?= $import_results['updated'] ?></h3>
                                    <span class="text-muted small font-weight-bold"><?= $isTr ? 'Güncellenen (Değişen)' : 'Updated (Changed)' ?></span>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="p-3 bg-light rounded text-center border">
                                    <h3 class="text-secondary font-weight-bold mb-0"><?= $import_results['unchanged'] ?></h3>
                                    <span class="text-muted small font-weight-bold"><?= $isTr ? 'Değişiklik Olmayan (Aynı)' : 'Unchanged (Same)' ?></span>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="p-3 bg-light rounded text-center border">
                                    <h3 class="text-warning font-weight-bold mb-0"><?= count($import_results['errors']) ?></h3>
                                    <span class="text-muted small font-weight-bold"><?= $isTr ? 'Atlanan / Hatalı Satır' : 'Skipped / Error Rows' ?></span>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($import_results['errors'])): ?>
                            <div class="mt-3">
                                <label class="font-weight-bold text-danger mb-1"><i class="fas fa-info-circle mr-1"></i><?= $isTr ? 'Hata / Uyarı Detayları:' : 'Error Details:' ?></label>
                                <ul class="text-danger small pl-3 mb-0" style="max-height: 150px; overflow-y: auto;">
                                    <?php foreach ($import_results['errors'] as $err): ?>
                                        <li><?= htmlspecialchars($err) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- YAN YANA AÇIK KARTLAR (SOL: PERSONEL, SAĞ: CİHAZLAR) -->
        <div class="row mb-4">
            
            <!-- KART 1: PERSONEL / KULLANICI İÇE AKTAR -->
            <div class="col-lg-6 mb-4">
                <div class="card shadow-sm border-0 h-100" style="border-radius:14px;">
                    <div class="card-header bg-white p-3 border-bottom d-flex align-items-center justify-content-between">
                        <h5 class="card-title font-weight-bold text-dark m-0">
                            <i class="fas fa-users text-primary mr-2"></i>1. Personel / Kullanıcı İçe Aktar
                        </h5>
                        <a href="anasayfa?route=toplu_ice_aktar&download_template=users" class="btn btn-success btn-sm font-weight-bold px-3" style="border-radius:8px;">
                            <i class="fas fa-file-excel mr-1"></i> Geniş Excel Şablonunu İndir (.XLS)
                        </a>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted small mb-3">
                            Personelleri toplu yüklemek için geniş şablonu indirin. Güvenlik açığı oluşmaması için her kullanıcıya otomatik olarak benzersiz ve rastgele bir şifre atanır.
                        </p>

                        <!-- ŞABLON SÜTUN AÇIKLAMA TABLOSU -->
                        <div class="table-responsive mb-4">
                            <table class="table table-bordered table-sm text-xs bg-light">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Sütun Adı</th>
                                        <th>Örnek Değer</th>
                                        <th>Açıklama</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code>Kullanici_Adi</code></td>
                                        <td><code>ahmet.yilmaz</code></td>
                                        <td>Benzersiz kullanıcı adı.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Ad_Soyad</code></td>
                                        <td><code>Ahmet Yılmaz</code></td>
                                        <td>Personelin tam adı soyadı.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Eposta</code></td>
                                        <td><code>ahmet.yilmaz@sirket.com</code></td>
                                        <td>E-posta adresi (Benzersiz).</td>
                                    </tr>
                                    <tr>
                                        <td><code>Sirket</code></td>
                                        <td><code>ABC Teknoloji</code></td>
                                        <td>Çalıştığı Şirket.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Bolum</code></td>
                                        <td><code>Bilgi Teknolojileri</code></td>
                                        <td>Departman / Bölüm.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Rol</code></td>
                                        <td><span class="badge badge-info">Personel</span> / <span class="badge badge-warning">Teknik Personel</span> / <span class="badge badge-danger">Süper Admin</span></td>
                                        <td>Metin olarak yazılır. (1, 2, 3 de geçerlidir).</td>
                                    </tr>
                                    <tr>
                                        <td><code>Durum</code></td>
                                        <td><span class="badge badge-success">Aktif</span> / <span class="badge badge-secondary">Pasif</span></td>
                                        <td><strong>Aktif:</strong> Giriş yapabilir. <strong>Pasif:</strong> Giriş yapamaz.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Sifre</code> <span class="badge badge-light text-muted">Opsiyonel</span></td>
                                        <td><em>(Boş)</em></td>
                                        <td>Güvenlik gereği boş bırakılması önerilir. Boş bırakıldığında her kullanıcıya benzersiz rastgele bir şifre atanır.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- DOSYA YÜKLEME FORMU -->
                        <form action="anasayfa?route=toplu_ice_aktar" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="process_import">
                            <input type="hidden" name="import_type" value="users">

                            <div class="form-group mb-3 p-3 text-center border bg-light" style="border-style: dashed !important; border-width: 2px !important; border-color: #cbd5e1 !important; border-radius:12px;">
                                <i class="fas fa-cloud-upload-alt fa-2x text-primary mb-2"></i>
                                <label class="d-block font-weight-bold text-dark small mb-2">Personel Excel (.XLS / .CSV) Dosyasını Seçin</label>
                                <input type="file" name="csv_file" accept=".xls, .xlsx, .csv, .txt" required class="form-control-file d-inline-block" style="max-width: 280px;">
                            </div>

                            <button type="submit" class="btn btn-primary font-weight-bold btn-block py-2" style="border-radius:8px;">
                                <i class="fas fa-upload mr-2"></i> Personel Listesini Yükle ve İşle
                            </button>
                        </form>

                        <!-- GÜVENLİK BİLGİLENDİRME KUTUSU -->
                        <div class="alert border-0 shadow-sm mt-3 mb-0" style="border-left: 4px solid #f6c23e !important; border-radius: 10px; background-color: #fff9e6; color: #856404;">
                            <div class="d-flex align-items-start">
                                <i class="fas fa-shield-alt fa-2x mr-3 text-warning mt-1"></i>
                                <div>
                                    <strong class="d-block mb-1" style="font-size: 13px;"><i class="fas fa-lock mr-1"></i> Güvenlik Uyarısı:</strong>
                                    <span class="small" style="line-height: 1.5;">
                                        Toplu personel aktarımında sabit (<code>123456</code>) şifre kullanımı güvenlik açığı oluşturacağı için varsayılan sabit şifre atanması kaldırılmıştır. Şifre alanı boş bırakıldığında her kullanıcı için otomatik olarak benzersiz ve rastgele bir şifre üretilir.<br>
                                        Personeller sisteme ilk girişlerinde veya şifrelerini almak/değiştirmek istediklerinde giriş ekranındaki <strong>"Şifremi Unuttum"</strong> seçeneğini (E-posta veya Kullanıcı Adı ile) kullanarak kendilerine yeni şifre belirleyebilirler.
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KART 2: CİHAZ / DEMİRBAŞ İÇE AKTAR -->
            <div class="col-lg-6 mb-4">
                <div class="card shadow-sm border-0 h-100" style="border-radius:14px;">
                    <div class="card-header bg-white p-3 border-bottom d-flex align-items-center justify-content-between">
                        <h5 class="card-title font-weight-bold text-dark m-0">
                            <i class="fas fa-laptop text-info mr-2"></i>2. Cihaz / Demirbaş İçe Aktar
                        </h5>
                        <a href="anasayfa?route=toplu_ice_aktar&download_template=assets" class="btn btn-success btn-sm font-weight-bold px-3" style="border-radius:8px;">
                            <i class="fas fa-file-excel mr-1"></i> Geniş Excel Şablonunu İndir (.XLS)
                        </a>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted small mb-3">
                            Envanterdeki cihazları yüklemek için geniş şablonu indirin. Şirket, Bölüm ve Zimmetli Personel E-postasını yazarak otomatik zimmetleyebilirsiniz.
                        </p>

                        <!-- ŞABLON SÜTUN AÇIKLAMA TABLOSU -->
                        <div class="table-responsive mb-4">
                            <table class="table table-bordered table-sm text-xs bg-light">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Sütun Adı</th>
                                        <th>Örnek Değer</th>
                                        <th>Açıklama</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code>Demirbas_Etiketi</code></td>
                                        <td><code>TAG-1001</code></td>
                                        <td>Cihaz Demirbaş Etiketi/Barkodu.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Seri_No</code></td>
                                        <td><code>SN-98765432</code></td>
                                        <td>Cihaz Seri Numarası.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Cihaz_Adi</code></td>
                                        <td><code>MacBook Pro 14</code></td>
                                        <td>Cihazın Model / Tanım Adı.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Kategori</code></td>
                                        <td><code>Laptop</code> / <code>Monitör</code></td>
                                        <td>Cihazın Türü/Kategorisi.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Sirket</code></td>
                                        <td><code>ABC Teknoloji</code></td>
                                        <td>Cihazın Ait Olduğu Şirket.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Bolum</code></td>
                                        <td><code>Bilgi Teknolojileri</code></td>
                                        <td>Cihazın Bulunduğu Bölüm.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Lokasyon</code></td>
                                        <td><code>İstanbul Ofisi</code></td>
                                        <td>Cihazın Bulunduğu Ofis/Konum.</td>
                                    </tr>
                                    <tr>
                                        <td><code>Zimmetli_Kullanici_Eposta</code></td>
                                        <td><code>ahmet.yilmaz@sirket.com</code></td>
                                        <td>Zimmetli personelin e-postası veya kullanıcı adı (Otomatik zimmetler).</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- DOSYA YÜKLEME FORMU -->
                        <form action="anasayfa?route=toplu_ice_aktar" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="process_import">
                            <input type="hidden" name="import_type" value="assets">

                            <div class="form-group mb-3 p-3 text-center border bg-light" style="border-style: dashed !important; border-width: 2px !important; border-color: #cbd5e1 !important; border-radius:12px;">
                                <i class="fas fa-file-csv fa-2x text-info mb-2"></i>
                                <label class="d-block font-weight-bold text-dark small mb-2">Cihaz Excel (.XLS / .CSV) Dosyasını Seçin</label>
                                <input type="file" name="csv_file" accept=".xls, .xlsx, .csv, .txt" required class="form-control-file d-inline-block" style="max-width: 280px;">
                            </div>

                            <button type="submit" class="btn btn-info text-white font-weight-bold btn-block py-2" style="border-radius:8px;">
                                <i class="fas fa-upload mr-2"></i> Cihaz Listesini Yükle ve İşle
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div>

    </div>
</section>
