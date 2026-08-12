<?php
// app/includes/inventory_cron.php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/mailer.php";

$pdo = db();
$isTr = true; // Default to TR

$lowStockItems = [];

// 1. Consumables
$sql = "SELECT id, name, total_qty, 
        (total_qty - (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'checkin' THEN -quantity ELSE quantity END), 0) FROM asset_consumable_checkouts WHERE consumable_id = ac.id AND transaction_type IN ('consume', 'checkin'))) as remaining,
        min_qty
        FROM asset_consumables ac
        WHERE deleted_at IS NULL AND min_qty > 0
        HAVING remaining < min_qty";
$consumables = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
foreach ($consumables as $c) {
    $lowStockItems[] = [
        'type' => $isTr ? 'Sarf Malzeme' : 'Consumable',
        'name' => $c['name'],
        'qty' => $c['remaining'],
        'min' => $c['min_qty']
    ];
}

// 2. Accessories
$sql = "SELECT id, name, total_qty, 
        (total_qty - (SELECT COALESCE(SUM(quantity), 0) FROM asset_accessory_checkouts WHERE accessory_id = aa.id AND (transaction_type = 'assign' OR transaction_type IS NULL))) as remaining,
        min_qty
        FROM asset_accessories aa
        WHERE deleted_at IS NULL AND min_qty > 0
        HAVING remaining < min_qty";
$accessories = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
foreach ($accessories as $a) {
    $lowStockItems[] = [
        'type' => $isTr ? 'Aksesuar' : 'Accessory',
        'name' => $a['name'],
        'qty' => $a['remaining'],
        'min' => $a['min_qty']
    ];
}

// 3. Licenses (Seats check)
$licenses = $pdo->query("SELECT id, software_name, seats, min_qty FROM asset_licenses WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
foreach ($licenses as $l) {
    $assigned = $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM asset_license_checkouts WHERE license_id = " . $l['id'] . " AND (transaction_type = 'assign' OR transaction_type IS NULL)")->fetchColumn();
    $remaining = $l['seats'] - $assigned;
    if ($remaining <= $l['min_qty']) {
        $lowStockItems[] = [
            'type' => $isTr ? 'Lisans' : 'License',
            'name' => $l['software_name'],
            'qty' => $remaining,
            'min' => $l['min_qty']
        ];
    }
}

if (!empty($lowStockItems)) {
    // Fetch Admins
    $admins = $pdo->query("SELECT email, fullname FROM users WHERE role = 1 AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($admins)) {
        $subject = ($isTr ? "⚠️ Envanter Kritik Stok Uyarısı" : "⚠️ Inventory Low Stock Alert");
        
        $tableRows = "";
        foreach ($lowStockItems as $item) {
            $tableRows .= "
            <tr>
                <td style='padding:10px; border-bottom:1px solid #eee;'>{$item['type']}</td>
                <td style='padding:10px; border-bottom:1px solid #eee;'><strong>{$item['name']}</strong></td>
                <td style='padding:10px; border-bottom:1px solid #eee; text-align:center; color:#ef4444; font-weight:bold;'>{$item['qty']}</td>
                <td style='padding:10px; border-bottom:1px solid #eee; text-align:center;'>{$item['min']}</td>
            </tr>";
        }

        $body = "
        <div style='font-family: sans-serif; color:#333;'>
            <h2 style='color:#ef4444; border-bottom:2px solid #ef4444; padding-bottom:10px;'>".($isTr ? "Kritik Stok Bildirimi" : "Critical Stock Alert")."</h2>
            <p>".($isTr ? "Aşağıdaki öğelerin stok seviyesi belirlenen kritik sınırın altına düşmüştür:" : "The following items have dropped below the critical stock threshold:")."</p>
            
            <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #eee; border-radius:8px; overflow:hidden;'>
                <thead style='background:#f8fafc;'>
                    <tr>
                        <th align='left' style='padding:10px;'>Tür</th>
                        <th align='left' style='padding:10px;'>Öğe Adı</th>
                        <th style='padding:10px;'>Mevcut</th>
                        <th style='padding:10px;'>Minimum</th>
                    </tr>
                </thead>
                <tbody>
                    $tableRows
                </tbody>
            </table>
            
            <div style='margin-top:25px; padding:15px; background:#fff7ed; border-radius:10px; border:1px solid #fed7aa; color:#9a3412;'>
                <strong>Not:</strong> ".($isTr ? "Lütfen en kısa sürede stok alımı planlayınız." : "Please plan for stock replenishment as soon as possible.")."
            </div>
            
            <div style='margin-top:20px; text-align:center;'>
                <a href='http://localhost/varliklar?view=consumables' style='background:#3b82f6; color:#fff; padding:12px 25px; text-decoration:none; border-radius:8px; font-weight:bold; display:inline-block;'>".($isTr ? "Envanteri Görüntüle" : "View Inventory")."</a>
            </div>
        </div>";

        foreach ($admins as $admin) {
             sendEaprimusMail($admin['email'], $admin['fullname'], $subject, buildMailTemplate($body));
        }
        
        echo "Alerts sent to " . count($admins) . " admins.";
    } else {
        echo "No active admins found.";
    }
} else {
    echo "Stock levels are healthy.";
}
