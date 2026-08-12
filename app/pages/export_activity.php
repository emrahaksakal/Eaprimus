<?php
// pages/export_activity.php
require_once __DIR__ . '/../includes/session.php';
requireLogin();

$type = $_GET['type'] ?? 'excel';
$filename = "aktivite_gecmisi_" . date('Ymd_His');

$stmt = $pdo->query("
    SELECT at.created_at, u.fullname as user, at.event_type, at.item_type, at.asset_id, at.context_id,
           CASE WHEN at.item_type = 'asset' THEN a.name
                WHEN at.item_type = 'accessory' THEN acc.name
                WHEN at.item_type = 'license' THEN lic.software_name
                WHEN at.item_type = 'consumable' THEN c.name
                WHEN at.item_type = 'component' THEN comp.name
                ELSE NULL END as product_name,
           at.event_description
    FROM asset_timeline at
    LEFT JOIN users u ON at.user_id = u.id
    LEFT JOIN assets a ON (at.item_type = 'asset' AND at.asset_id = a.id)
    LEFT JOIN asset_accessories acc ON (at.item_type = 'accessory' AND (at.asset_id = acc.id OR at.context_id = acc.id))
    LEFT JOIN asset_licenses lic ON (at.item_type = 'license' AND at.asset_id = lic.id)
    LEFT JOIN asset_consumables c ON (at.item_type = 'consumable' AND at.asset_id = c.id)
    LEFT JOIN asset_components comp ON (at.item_type = 'component' AND at.asset_id = comp.id)
    ORDER BY at.created_at DESC
    LIMIT 1000
");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($type === 'excel') {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=$filename.xls");
    
    echo '<table border="1">';
    echo '<tr><th>' . __('date') . '</th><th>' . __('user') . '</th><th>' . __('action') . '</th><th>' . __('asset') . '</th><th>' . __('description') . '</th></tr>';
    foreach ($logs as $log) {
        echo '<tr>';
        echo '<td>' . $log['created_at'] . '</td>';
        echo '<td>' . htmlspecialchars($log['user'] ?: 'Sistem') . '</td>';
        $timelineEventLabels = [
            'created' => __('timeline_created'),
            'updated' => __('timeline_updated'),
            'deleted' => __('timeline_deleted'),
            'restored' => __('timeline_restored'),
            'checkin' => __('timeline_checkin'),
            'checkout' => __('timeline_checkout'),
            'archived' => __('archived'),
        ];
        echo '<td>' . ($timelineEventLabels[$log['event_type']] ?? __($log['event_type'])) . '</td>';
        $product = $log['product_name'] ?? null;
        if ($product) {
            $linkId = intval($log['asset_id'] ?: $log['context_id'] ?: 0);
            $href = ($log['item_type'] === 'accessory') ? "varlik-detay/{$linkId}?view=accessories" : "varlik-detay/{$linkId}";
            echo '<td><a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($product) . '</a></td>';
        } else {
            echo '<td>-</td>';
        }
        echo '<td>' . htmlspecialchars(__($log['event_description'])) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
} else {
    // PDF (Simple version using print/HTML table)
    echo '<style>table{width:100%; border-collapse:collapse;} th,td{border:1px solid #ddd; padding:8px; font-size:12px;} th{background:#f4f4f4;}</style>';
    echo '<h2>' . __('recent_activity') . '</h2>';
    echo '<table>';
    echo '<thead><tr><th>' . __('date') . '</th><th>' . __('user') . '</th><th>' . __('action') . '</th><th>' . __('asset') . '</th><th>' . __('description') . '</th></tr></thead>';
    echo '<tbody>';
    foreach ($logs as $log) {
        echo '<tr>';
        echo '<td>' . $log['created_at'] . '</td>';
        echo '<td>' . htmlspecialchars($log['user'] ?: 'Sistem') . '</td>';
        $timelineEventLabels = [
            'created' => __('timeline_created'),
            'updated' => __('timeline_updated'),
            'deleted' => __('timeline_deleted'),
            'restored' => __('timeline_restored'),
            'checkin' => __('timeline_checkin'),
            'checkout' => __('timeline_checkout'),
            'archived' => __('archived'),
        ];
        echo '<td>' . ($timelineEventLabels[$log['event_type']] ?? __($log['event_type'])) . '</td>';
        $product = $log['product_name'] ?? null;
        if ($product) {
            $linkId = intval($log['asset_id'] ?: $log['context_id'] ?: 0);
            $href = ($log['item_type'] === 'accessory') ? "varlik-detay/{$linkId}?view=accessories" : "varlik-detay/{$linkId}";
            echo '<td><a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($product) . '</a></td>';
        } else {
            echo '<td>-</td>';
        }
        echo '<td>' . htmlspecialchars(__($log['event_description'])) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<script>window.print();</script>';
}
exit;
