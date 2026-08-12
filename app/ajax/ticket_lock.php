<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logger.php';
requireLogin();
require_csrf_token();

header('Content-Type: application/json; charset=utf-8');

// Debug buffer
$debug = [];

try {
    $pdo = db();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $userName = $_SESSION['fullname'] ?? '';
    $userRole = (int) ($_SESSION['role'] ?? 0);
    
    $debug['session'] = ['user_id' => $userId, 'role' => $userRole, 'name' => $userName];

    $ticketId = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
    $action = $_POST['action'] ?? 'ping';
    
    $debug['input'] = ['ticket_id' => $ticketId, 'action' => $action, 'post' => $_POST];

    if (!$ticketId) {
        echo json_encode(['error' => 'missing_ticket', 'debug' => $debug]);
        exit;
    }

    function jsonFail(int $httpCode, array $payload, array $debug = []): void
    {
        http_response_code($httpCode);
        echo json_encode(array_merge($payload, ['debug' => $debug]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($userRole === 2 && in_array($action, ['lock', 'claim', 'unlock'])) {
        $isEn = (($_SESSION['lang'] ?? 'tr') === 'en');
        jsonFail(403, [
            'ok' => false,
            'error' => 'forbidden',
            'message' => $isEn ? 'Personnel cannot perform this action.' : 'Personel bu işlemi gerçekleştiremez.'
        ], $debug);
    }

    function ticketsPersonnelColumn(PDO $pdo): string
    {
        static $col = null;
        if ($col !== null) {
            return $col;
        }
        try {
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'personnel_id'");
            $stmt->execute([$dbName]);
            $hasPersonnel = (int) $stmt->fetchColumn() > 0;
            $col = $hasPersonnel ? 'personnel_id' : 'assigned_to';
            return $col;
        } catch (Throwable $e) {
            $col = 'assigned_to';
            return $col;
        }
    }

    // Lock expiration seconds (8 hours = 28800 seconds)
    $expireSec = 28800;

    // load ticket lock info
    $personnelCol = ticketsPersonnelColumn($pdo);
    $stmt = $pdo->prepare("SELECT locked_by, locked_at, status, $personnelCol as assigned_user_id FROM tickets WHERE id = ?");
    $stmt->execute([$ticketId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $lockedBy = $row ? (int) $row['locked_by'] : 0;
    $lockedAt = $row && $row['locked_at'] ? strtotime($row['locked_at']) : 0;
    $ticketStatus = $row['status'] ?? 'open';
    $isClosedOrResolved = in_array($ticketStatus, ['closed', 'resolved']);
    $assignedUserId = $row ? (int) $row['assigned_user_id'] : 0;
    $isAssigned = ($assignedUserId === $userId);

    $now = time();
    $isExpired = $lockedAt && ($now - $lockedAt) > $expireSec;
    
    $debug['lock_info'] = ['locked_by' => $lockedBy, 'is_expired' => $isExpired, 'is_assigned' => $isAssigned];

    if ($action === 'ping') {
        if ($lockedBy && !$isExpired) {
            if ($lockedBy === $userId) {
                $pdo->prepare('UPDATE tickets SET locked_at = NOW() WHERE id = ?')->execute([$ticketId]);
                echo json_encode(['locked' => true, 'locked_by' => $lockedBy, 'locked_by_name' => $userName]);
                exit;
            }
            $name = $pdo->prepare('SELECT fullname FROM users WHERE id = ?');
            $name->execute([$lockedBy]);
            $lockedName = $name->fetchColumn() ?: '';
            echo json_encode(['locked' => true, 'locked_by' => $lockedBy, 'locked_by_name' => $lockedName]);
            exit;
        }

        // Auto-lock unlocked/expired active ticket for technical staff (role 1 or 3)
        if (in_array($userRole, [1, 3]) && !$isClosedOrResolved) {
            $pdo->prepare('UPDATE tickets SET locked_by = ?, locked_at = NOW() WHERE id = ?')->execute([$userId, $ticketId]);
            echo json_encode(['locked' => true, 'locked_by' => $userId, 'locked_by_name' => $userName]);
            exit;
        }

        echo json_encode(['locked' => false]);
        exit;
    }

    if ($action === 'lock') {
        if ($lockedBy && !$isExpired && $lockedBy !== $userId) {
            $name = $pdo->prepare('SELECT fullname FROM users WHERE id = ?');
            $name->execute([$lockedBy]);
            $lockedName = $name->fetchColumn() ?: '';
            echo json_encode(['ok' => false, 'locked' => true, 'locked_by' => $lockedBy, 'locked_by_name' => $lockedName]);
            exit;
        }
        $pdo->prepare('UPDATE tickets SET locked_by = ?, locked_at = NOW() WHERE id = ?')->execute([$userId, $ticketId]);
        echo json_encode(['ok' => true, 'locked' => true, 'locked_by' => $userId, 'locked_by_name' => $userName]);
        exit;
    }

    if ($action === 'release') {
        // Deprecated/no-op: locks must persist and can only be cleared via explicit unlock or reassignment
        echo json_encode(['ok' => true, 'released' => false]);
        exit;
    }

    if ($action === 'claim') {
        // First check if it's already locked by someone else
        // EXCEPT for Admin (1), Team Leader (3), or the Assigned Agent who can override.
        if ($lockedBy && !$isExpired && $lockedBy !== $userId && !in_array($userRole, [1, 3]) && !$isAssigned) {
            $name = $pdo->prepare('SELECT fullname FROM users WHERE id = ?');
            $name->execute([$lockedBy]);
            $lockedName = $name->fetchColumn() ?: 'Başka bir personel';
            echo json_encode([
                'ok' => false, 
                'error' => 'locked', 
                'locked' => true, 
                'locked_by' => $lockedBy, 
                'locked_by_name' => $lockedName,
                'message' => __("ticket_locked_by", ["user_name" => $lockedName])
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($userRole === 2) {
            $stmtCheck = $pdo->prepare("SELECT q.name FROM tickets t JOIN queues q ON t.queue_id = q.id JOIN teams_users tu ON q.team_id = tu.team_id WHERE t.id = ? AND tu.user_id = ?");
            $stmtCheck->execute([$ticketId, $userId]);
            $qName = $stmtCheck->fetchColumn();
            
            if (!$qName) {
                $stmtQ = $pdo->prepare("SELECT q.name FROM tickets t JOIN queues q ON t.queue_id = q.id WHERE t.id = ?");
                $stmtQ->execute([$ticketId]);
                $qName = $stmtQ->fetchColumn() ?: 'Bilinmeyen';
                
                // Fallback message if translation fails
                $msg = __("unauthorized_ticket_access_msg", ["queue" => $qName]);
                if ($msg === "unauthorized_ticket_access_msg") {
                    $msg = "⚠️ Yetkisiz Erişim: Bu bilet $qName kuyruğuna bağlıdır ve bu kuyruk için atanmış bir takıma dahil değilsiniz.";
                }
                
                jsonFail(403, [
                    'ok' => false, 
                    'error' => 'unauthorized', 
                    'message' => $msg
                ], $debug);
            }
        }

        $stmtT = $pdo->prepare("SELECT ticket_no, status FROM tickets WHERE id = ?");
        $stmtT->execute([$ticketId]);
        $ticketInfo = $stmtT->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticketInfo) {
            jsonFail(404, ['ok' => false, 'error' => 'ticket_not_found', 'message' => __("no_items_found")], $debug);
        }
        
        $tNo = $ticketInfo['ticket_no'] ?: '';
        $debug['ticket'] = ['no' => $tNo, 'status' => $ticketInfo['status']];

        $personnelCol = ticketsPersonnelColumn($pdo);
        $debug['personnel_col'] = $personnelCol;
        
        $pdo->beginTransaction();
        $statusClause = "";
        if ($ticketInfo && in_array($ticketInfo['status'], ['resolved', 'closed'])) {
            $statusClause = ", status = 'open'";
            if (function_exists('ticketLogAl')) {
                @ticketLogAl($pdo, $userId, 'YENIDEN ACILDI', $tNo, "Bilet yanıtla butonuyla sahiplenilirken otomatik olarak yeniden açıldı.");
            }
        }
        $sql = "UPDATE tickets SET {$personnelCol} = ?, assigned_by = ?, locked_by = ?, locked_at = NOW()$statusClause WHERE id = ?";
        $debug['sql'] = $sql;
        
        $stmtU = $pdo->prepare($sql);
        $result = $stmtU->execute([$userId, $userId, $userId, $ticketId]);
        
        if (!$result) {
            $pdo->rollBack();
            $debug['execute_error'] = $stmtU->errorInfo();
            jsonFail(500, ['ok' => false, 'error' => 'database_update_failed', 'message' => __("database_error")], $debug);
        }
        
        $affectedRows = $stmtU->rowCount();
        $debug['affected_rows'] = $affectedRows;

        // Log al - suppress errors
        if (function_exists('ticketLogAl')) {
            @ticketLogAl($pdo, $userId, 'SAHIPLENILDI', $tNo, "Temsilci bilet yanitlama ekranindan biletini sahiplendi.");
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'personnel_id' => $userId, 'locked_by' => $userId, 'ticket_no' => $tNo, 'affected_rows' => $affectedRows, 'debug' => $debug], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'unlock') {
        // Yetki Kontrolü:
        // 1. Admin (role 1) veya Team Leader (role 3)
        // 2. Süresi dolmuş bir kilit
        $isAuthorizedRole = in_array($userRole, [1, 3]);
        
        $allowedToUnlock = $isAuthorizedRole || $isExpired;
        
        $debug['unlock_auth'] = [
            'isAuthorizedRole' => $isAuthorizedRole,
            'isExpired' => $isExpired,
            'final_decision' => $allowedToUnlock
        ];

        if (!$allowedToUnlock) {
            jsonFail(403, ['ok' => false, 'error' => 'forbidden_unlock', 'message' => __("unlock_not_authorized")], $debug);
            exit;
        }

        $pdo->beginTransaction();
        
        $stmtT = $pdo->prepare("SELECT ticket_no, status FROM tickets WHERE id = ?");
        $stmtT->execute([$ticketId]);
        $ticketInfo = $stmtT->fetch(PDO::FETCH_ASSOC);
        $reopened = false;
        
        if ($ticketInfo && in_array($ticketInfo['status'], ['resolved', 'closed'])) {
            $stmtU = $pdo->prepare("UPDATE tickets SET status = 'open', locked_by = ?, locked_at = NOW() WHERE id = ?");
            if (function_exists('ticketLogAl')) {
                @ticketLogAl($pdo, $userId, 'YENIDEN ACILDI', $ticketInfo['ticket_no'], "Bilet kilidi açılırken otomatik olarak yeniden açıldı.");
            }
            $reopened = true;
            $result = $stmtU->execute([$userId, $ticketId]);
        } else {
            $stmtU = $pdo->prepare('UPDATE tickets SET locked_by = NULL, locked_at = NULL WHERE id = ?');
            $result = $stmtU->execute([$ticketId]);
        }

        if (!$result) {
            $pdo->rollBack();
            jsonFail(500, ['ok' => false, 'error' => 'unlock_failed', 'message' => __("operation_failed")], $debug);
            exit;
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => __("ticket_unlocked") ?? 'Bilet kilidi başarıyla açıldı.', 'reopened' => $reopened, 'debug' => $debug], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['error' => 'unknown_action', 'debug' => $debug]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonFail(500, ['ok' => false, 'error' => 'pdo_exception', 'message' => $e->getMessage(), 'code' => $e->getCode()], $debug);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonFail(500, ['ok' => false, 'error' => 'exception', 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()], $debug);
}