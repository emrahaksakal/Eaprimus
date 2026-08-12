<?php
require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/db.php";
header('Content-Type: text/html; charset=utf-8');
requireLogin();

$pdo = db();
$cid = (int)($_GET['customer_id'] ?? 0);
$oid = (int)($_GET['organization_id'] ?? 0);

if ($cid == 0 && $oid == 0) {
    echo '<div class="alert alert-warning">' . __("not_found") . '</div>';
    exit;
}

// Function to get custom fields and values
function getCustomData($pdo, $type, $id) {
    if (!$id) return ['fields' => [], 'values' => []];
    $col = ($type == 'contact') ? 'customer_id' : (($type == 'organization') ? 'organization_id' : 'ticket_id');
    
    // Get fields
    if ($type === 'contact') {
        $stmt = $pdo->prepare("SELECT * FROM customer_fields WHERE target_type = ? AND (customer_ids IS NULL OR customer_ids = '' OR FIND_IN_SET(?, customer_ids)) ORDER BY sort_order ASC");
        $stmt->execute([$type, $id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM customer_fields WHERE target_type = ? ORDER BY sort_order ASC");
        $stmt->execute([$type]);
    }
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get values
    $stmtVal = $pdo->prepare("SELECT field_id, value FROM customer_field_values WHERE $col = ?");
    $stmtVal->execute([($id ?: 0)]);
    $values = $stmtVal->fetchAll(PDO::FETCH_KEY_PAIR);

    return ['fields' => $fields, 'values' => $values];
}

if ($cid > 0) {
    $c = $pdo->prepare("SELECT c.*, o.name as org_name FROM customers c LEFT JOIN organizations o ON o.id = c.organization_id WHERE c.id = ?");
    $c->execute([$cid]);
    $cust = $c->fetch(PDO::FETCH_ASSOC);
    if ($cust) {
        $custom = getCustomData($pdo, 'contact', $cid);
        ?>
        <div class="mb-4">
            <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px;">
                <h6 style="color:#1e3a8a; font-weight:700; margin:0; font-size:16px;">
                    <i class="fas fa-user-circle mr-2"></i> <?= __("contact_customer") ?>
                </h6>
                <a href="musteri-detay/<?= $cid ?>" target="_blank" style="font-size:12px; font-weight:600; text-decoration:none; color:#2563eb;"><i class="fas fa-external-link-alt mr-1"></i><?= __("detail") ?></a>
            </div>
            
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
                <div style="margin-bottom:8px;"><strong style="color:#475569; font-size:13px; font-weight:700; display:inline-block; width:80px;"><?= __("full_name") ?>:</strong> <span style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($cust['name'] ?: '-') ?></span></div>
                <div style="margin-bottom:8px;"><strong style="color:#475569; font-size:13px; font-weight:700; display:inline-block; width:80px;"><?= __("email") ?>:</strong> <a href="mailto:<?= htmlspecialchars($cust['email']) ?>" style="color:#2563eb; font-weight:500; text-decoration:none;"><?= htmlspecialchars($cust['email']) ?></a></div>
                <?php if($cust['org_name'] || $cust['company']): ?>
                <div style="margin-bottom:8px;"><strong style="color:#475569; font-size:13px; font-weight:700; display:inline-block; width:80px;"><?= __("organization") ?>:</strong> <span style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($cust['org_name'] ?: ($cust['company'] ?: '-')) ?></span></div>
                <?php endif; ?>
                <div style="margin-bottom:8px;"><strong style="color:#475569; font-size:13px; font-weight:700; display:inline-block; width:80px;"><?= __("phone") ?>:</strong> <?= htmlspecialchars($cust['phone'] ?: '-') ?></div>
                <div style="margin-top:12px; padding-top:12px; border-top:1px dashed #cbd5e1;">
                    <strong style="color:#475569; font-size:13px; font-weight:700; display:block; margin-bottom:4px;"><?= __("notes") ?>:</strong>
                    <div style="font-size:13px; color:#64748b; line-height:1.4;"><?= nl2br(htmlspecialchars($cust['notes'] ?: '-')) ?></div>
                </div>
            </div>
            
            <?php if (!empty($custom['fields'])): ?>
                <div class="mt-3 pl-3 border-left" style="border-width: 4px !important; border-color: #3b82f6 !important;">
                <?php foreach ($custom['fields'] as $f): 
                    $val = $custom['values'][$f['id']] ?? '';
                    if ($val === '' && $f['field_type'] !== 'checkbox') continue;
                ?>
                    <div class="mb-2">
                        <small class="text-muted d-block text-uppercase font-weight-bold" style="font-size: 10px; letter-spacing: 0.5px;"><?= htmlspecialchars($f['label']) ?></small>
                        <div class="font-weight-bold text-dark" style="font-size: 13px;"><?= ($f['field_type'] === 'checkbox') ? ($val == '1' ? __("yes") : __("no")) : htmlspecialchars($val) ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

if ($oid > 0) {
    $o = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
    $o->execute([$oid]);
    $org = $o->fetch(PDO::FETCH_ASSOC);
    if ($org) {
        $custom = getCustomData($pdo, 'organization', $oid);
        ?>
        <div class="mb-4">
            <h6 class="text-info font-weight-bold mb-3 d-flex align-items-center">
                <i class="fas fa-building fa-lg mr-2"></i> <?= __("organization") ?>
            </h6>
            <div class="card border-0 shadow-none" style="background: rgba(23, 162, 184, 0.05); border-radius: 10px;">
                <div class="card-body p-3">
                    <p class="mb-1"><strong><?= __("organization_name_label") ?>:</strong> <?= htmlspecialchars($org['name']) ?></p>
                    <p class="mb-1"><strong>Domain:</strong> <code><?= htmlspecialchars($org['domain'] ?: '-') ?></code></p>
                    <p class="mb-0"><strong><?= __("notes") ?>:</strong> <small><?= htmlspecialchars($org['notes'] ?: '-') ?></small></p>
                </div>
            </div>

            <?php if (!empty($custom['fields'])): ?>
                <div class="mt-3 pl-3 border-left" style="border-width: 4px !important; border-color: #17a2b8 !important;">
                <?php foreach ($custom['fields'] as $f): 
                    $val = $custom['values'][$f['id']] ?? '';
                    if ($val === '' && $f['field_type'] !== 'checkbox') continue;
                ?>
                    <div class="mb-2">
                        <small class="text-muted d-block text-uppercase font-weight-bold" style="font-size: 10px; letter-spacing: 0.5px;"><?= htmlspecialchars($f['label']) ?></small>
                        <div class="font-weight-bold text-dark" style="font-size: 13px;"><?= ($f['field_type'] === 'checkbox') ? ($val == '1' ? __("yes") : __("no")) : htmlspecialchars($val) ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
