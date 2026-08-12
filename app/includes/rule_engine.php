<?php

function runTicketRules($pdo, $ticketId) {
    // Get ticket data
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) return;

    // Get active rules
    $stmt = $pdo->query("SELECT * FROM ticket_rules WHERE is_active = 1 ORDER BY id ASC");
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updates = [];
    $updateParams = [];
    $logMessages = [];

    foreach ($rules as $rule) {
        $matched = false;
        $val = '';

        if ($rule['condition_field'] === 'subject') {
            $val = $ticket['title'];
        } elseif ($rule['condition_field'] === 'body') {
            $val = $ticket['description'];
        } elseif ($rule['condition_field'] === 'customer_email') {
            // Fetch customer email
            if ($ticket['customer_id']) {
                $cstmt = $pdo->prepare("SELECT email FROM customers WHERE id = ?");
                $cstmt->execute([$ticket['customer_id']]);
                $val = $cstmt->fetchColumn() ?: '';
            }
        }

        $condVal = strtolower($rule['condition_value']);
        $actualVal = strtolower($val);

        if ($rule['condition_operator'] === 'contains' && strpos($actualVal, $condVal) !== false) {
            $matched = true;
        } elseif ($rule['condition_operator'] === 'equals' && $actualVal === $condVal) {
            $matched = true;
        }

        if ($matched) {
            if ($rule['action_type'] === 'set_queue') {
                $updates[] = "queue_id = ?";
                $updateParams[] = $rule['action_value'];
                $logMessages[] = "Otomasyon: Kuyruk atandı (Kural: " . $rule['rule_name'] . ")";
            } elseif ($rule['action_type'] === 'set_priority') {
                $updates[] = "priority = ?";
                $updateParams[] = $rule['action_value'];
                $logMessages[] = "Otomasyon: Öncelik değiştirildi (Kural: " . $rule['rule_name'] . ")";
            }
        }
    }

    if (!empty($updates)) {
        $sql = "UPDATE tickets SET " . implode(", ", $updates) . " WHERE id = ?";
        $updateParams[] = $ticketId;
        
        $updStmt = $pdo->prepare($sql);
        $updStmt->execute($updateParams);

        // Add system messages
        foreach ($logMessages as $msg) {
            $insStmt = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_system_message, created_at) VALUES (?, 0, ?, 1, NOW())");
            $insStmt->execute([$ticketId, $msg]);
        }
    }
}
