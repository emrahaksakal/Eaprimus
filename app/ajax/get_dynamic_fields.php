<?php
require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/db.php";
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
requireLogin();

if (!isset($pdo)) {
    $pdo = db();
}

if (isset($_GET['search_select2'])) {
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? 2;
    
    $type = trim($_GET['search_select2']);
    $q = trim($_GET['q'] ?? '');
    $page = intval($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $results = [];
    $more = false;
    
    $log_file = __DIR__ . '/../logs/select2_debug.log';
    $log_data = sprintf(
        "[%s] Req: type=%s, q=%s, page=%d, org=%s, cust=%s\n",
        date('Y-m-d H:i:s'),
        $type,
        $q,
        $page,
        $_GET['organization_id'] ?? 'none',
        $_GET['customer_id'] ?? 'none'
    );
    file_put_contents($log_file, $log_data, FILE_APPEND);
    
    try {
        if ($type === 'customers') {
        $orgId = !empty($_GET['organization_id']) ? (int)$_GET['organization_id'] : null;
        $where = " WHERE 1=1";
        $params = [];
        if ($orgId) {
            $where .= " AND organization_id = ?";
            $params[] = $orgId;
        }
        if (!empty($q)) {
            $where .= " AND (name LIKE ? OR email LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        
        $sql = "SELECT id, CONCAT(COALESCE(name, email, ''), CASE WHEN email IS NOT NULL AND email != '' THEN CONCAT(' (', email, ')') ELSE '' END) as text, organization_id FROM customers $where ORDER BY COALESCE(name, email) ASC LIMIT " . ($limit + 1) . " OFFSET $offset";
        file_put_contents($log_file, sprintf("[%s] Customers SQL: %s | Params: %s\n", date('Y-m-d H:i:s'), $sql, json_encode($params)), FILE_APPEND);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($log_file, sprintf("[%s] Customers result count: %d\n", date('Y-m-d H:i:s'), count($rows)), FILE_APPEND);
        if (count($rows) > $limit) {
            $more = true;
            array_pop($rows);
        }
        $results = $rows;
    } elseif ($type === 'assets') {
        $where = " WHERE deleted_at IS NULL";
        $params = [];
        
        $orgId = !empty($_GET['organization_id']) ? (int)$_GET['organization_id'] : null;
        $custId = !empty($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
        
        if ($current_user_role == 2) {
            $where .= " AND assigned_user_id = ?";
            $params[] = $current_user_id;
        } else {
            if ($custId || $orgId) {
                $customerUserId = null;
                if ($custId) {
                    $stmtCu = $pdo->prepare("SELECT u.id FROM users u JOIN customers c ON c.email COLLATE utf8mb4_general_ci = u.mail COLLATE utf8mb4_general_ci WHERE c.id = ?");
                    $stmtCu->execute([$custId]);
                    $customerUserId = $stmtCu->fetchColumn();
                }
                
                $orConditions = [];
                if ($orgId) {
                    $orConditions[] = "organization_id = " . $orgId;
                }
                if ($customerUserId) {
                    $orConditions[] = "assigned_user_id = " . $customerUserId;
                }
                
                if (!empty($orConditions)) {
                    $where .= " AND (" . implode(" OR ", $orConditions) . ")";
                }
            } elseif ($current_user_role != 1) {
                $where .= " AND (assigned_user_id = ? OR assigned_user_id IS NULL)";
                $params[] = $current_user_id;
            }
        }
        
        if (!empty($q)) {
            $where .= " AND (device_name LIKE ? OR name LIKE ? OR ip_address LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        
        $sql = "
            SELECT id, 
                CONCAT(
                    COALESCE(device_name, name, ''), 
                    CASE WHEN ip_address IS NOT NULL AND ip_address != '' THEN CONCAT(' (', ip_address, ')') ELSE '' END,
                    CASE WHEN assigned_user_id = " . (int)$current_user_id . " THEN ' (Kendi Cihazı)' ELSE '' END
                ) as text 
            FROM assets 
            $where 
            ORDER BY (assigned_user_id = " . (int)$current_user_id . ") DESC, COALESCE(device_name, name, '') ASC 
            LIMIT " . ($limit + 1) . " OFFSET $offset
        ";
        file_put_contents($log_file, sprintf("[%s] Assets SQL: %s | Params: %s\n", date('Y-m-d H:i:s'), $sql, json_encode($params)), FILE_APPEND);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($log_file, sprintf("[%s] Assets result count: %d\n", date('Y-m-d H:i:s'), count($rows)), FILE_APPEND);
        if (count($rows) > $limit) {
            $more = true;
            array_pop($rows);
        }
        $results = $rows;
    }
    
    } catch (Throwable $t) {
        file_put_contents($log_file, sprintf("[%s] ERROR: %s\n%s\n", date('Y-m-d H:i:s'), $t->getMessage(), $t->getTraceAsString()), FILE_APPEND);
    }
    
    file_put_contents($log_file, sprintf("[%s] Res count: %d, more: %s\n", date('Y-m-d H:i:s'), count($results), $more ? 'true' : 'false'), FILE_APPEND);
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'results' => $results,
        'pagination' => ['more' => $more]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$target_type = $_GET['target_type'] ?? '';
$target_id = (int)($_GET['target_id'] ?? 0);

if (!in_array($target_type, ['contact', 'organization', 'ticket'])) {
    echo json_encode(['error' => 'Invalid target type']);
    exit;
}

// Fetch active fields for this target type
if ($target_type === 'contact' && $target_id > 0) {
    // General fields + specific fields for this contact
    $stmt = $pdo->prepare("SELECT * FROM customer_fields WHERE target_type = ? AND (customer_ids IS NULL OR customer_ids = '' OR FIND_IN_SET(?, customer_ids)) ORDER BY sort_order ASC");
    $stmt->execute([$target_type, $target_id]);
} else if ($target_type === 'contact') {
    // New contact case: only general
    $stmt = $pdo->prepare("SELECT * FROM customer_fields WHERE target_type = ? AND (customer_ids IS NULL OR customer_ids = '') ORDER BY sort_order ASC");
    $stmt->execute([$target_type]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM customer_fields WHERE target_type = ? ORDER BY sort_order ASC");
    $stmt->execute([$target_type]);
}
$fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing values if target_id is provided
$values = [];
if ($target_id > 0) {
    $valCol = ($target_type == 'contact') ? 'customer_id' : (($target_type == 'organization') ? 'organization_id' : 'ticket_id');
    try {
        $stmtVal = $pdo->prepare("SELECT field_id, value FROM customer_field_values WHERE $valCol = ?");
        $stmtVal->execute([$target_id]);
        $values = $stmtVal->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        // Fallback for older schema if needed, but we ensured 'value' via migration
    }
}

echo json_encode([
    'fields' => $fields,
    'values' => $values
]);
