<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
requireLogin();

$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';
$pdo = db();

// Fetch personnel column name dynamically
$personnelCol = 'assigned_to';
try {
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmtCol = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'personnel_id'");
    $stmtCol->execute([$dbName]);
    $hasPersonnel = (int) $stmtCol->fetchColumn() > 0;
    $personnelCol = $hasPersonnel ? 'personnel_id' : 'assigned_to';
} catch (Throwable $e) {}

// Fetch all tickets
$stmt = $pdo->prepare("SELECT t.*, u.fullname as creator_name, p.fullname as agent_name, q.team_id 
    FROM tickets t 
    LEFT JOIN users u ON t.creator_id = u.id 
    LEFT JOIN users p ON t.{$personnelCol} = p.id 
    LEFT JOIN queues q ON t.queue_id = q.id
    ORDER BY t.priority DESC, t.created_at DESC");
$stmt->execute();
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stEntries = $pdo->query("SELECT * FROM ticket_statuses ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);

$columns = [];
if (!empty($stEntries)) {
    foreach ($stEntries as $st) {
        $key = $st['id_name'];
        $label = __("ticket_status_" . $key);
        if ($label === "ticket_status_" . $key) {
            $label = $st['label'];
        }
        $columns[$key] = [
            'title' => $label,
            'color' => $st['color'] ?? '#64748b',
            'items' => []
        ];
    }
}

if (empty($columns)) {
    $columns = [
        'open' => ['title' => $isTr ? 'Açık' : 'Open', 'color' => '#3b82f6', 'items' => []],
        'assigned' => ['title' => $isTr ? 'Atanmış' : 'Assigned', 'color' => '#6366f1', 'items' => []],
        'waiting_customer' => ['title' => $isTr ? 'Müşteri Yanıtı Bekleniyor' : 'Waiting on Customer', 'color' => '#8b5cf6', 'items' => []],
        'closed' => ['title' => $isTr ? 'Kapalı' : 'Closed', 'color' => '#64748b', 'items' => []]
    ];
}

foreach ($tickets as $t) {
    $s = strtolower($t['status'] ?? 'open');
    if (isset($columns[$s])) {
        $columns[$s]['items'][] = $t;
    } else {
        $firstKey = array_key_first($columns) ?: 'open';
        if (isset($columns[$firstKey])) {
            $columns[$firstKey]['items'][] = $t;
        }
    }
}
?>
<div class="container-fluid pt-3 px-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="font-weight-bold"><i class="fas fa-columns text-primary mr-2"></i><?= $isTr ? 'Kanban Pano' : 'Kanban Board' ?></h4>
        <a href="anasayfa?panel=ticket" class="btn btn-sm btn-outline-secondary"><i class="fas fa-list mr-1"></i><?= $isTr ? 'Liste Görünümü' : 'List View' ?></a>
    </div>

    <!-- Kanban Advanced Filtering & Search -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:15px;">
        <div class="card-body p-3">
            <div class="row align-items-center">
                <!-- Search by No/Title -->
                <div class="col-md-3 mb-2 mb-md-0">
                    <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Arama (No / Başlık)' : 'Search (No / Title)' ?></label>
                    <div class="input-group input-group-sm">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white border-right-0" style="border-radius: 8px 0 0 8px; border: 1px solid #e2e8f0;"><i class="fas fa-search text-muted"></i></span>
                        </div>
                        <input type="text" id="kanbanSearch" class="form-control border-left-0 bg-light" placeholder="<?= $isTr ? 'Ticket Ara...' : 'Search Ticket...' ?>" style="border-radius:0 8px 8px 0; border: 1px solid #e2e8f0;">
                    </div>
                </div>
                <!-- Status Filter -->
                <div class="col-md-2 mb-2 mb-md-0">
                    <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Durum' : 'Status' ?></label>
                    <select id="filterStatus" class="form-control form-control-sm bg-light" style="border-radius:8px; border: 1px solid #e2e8f0; height:31px;">
                        <option value="all"><?= $isTr ? 'Tüm Durumlar' : 'All Statuses' ?></option>
                        <?php foreach ($columns as $sKey => $col): ?>
                            <option value="<?= $sKey ?>"><?= htmlspecialchars($col['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Priority Filter -->
                <div class="col-md-2 mb-2 mb-md-0">
                    <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Öncelik' : 'Priority' ?></label>
                    <select id="filterPriority" class="form-control form-control-sm bg-light" style="border-radius:8px; border: 1px solid #e2e8f0; height:31px;">
                        <option value="all"><?= $isTr ? 'Tüm Öncelikler' : 'All Priorities' ?></option>
                        <option value="low"><?= $isTr ? 'Düşük' : 'Low' ?></option>
                        <option value="normal"><?= $isTr ? 'Normal' : 'Normal' ?></option>
                        <option value="high"><?= $isTr ? 'Yüksek' : 'High' ?></option>
                        <option value="urgent"><?= $isTr ? 'Acil' : 'Urgent' ?></option>
                        <option value="critical"><?= $isTr ? 'Kritik' : 'Critical' ?></option>
                    </select>
                </div>
                <!-- Agent Filter -->
                <div class="col-md-2 mb-2 mb-md-0">
                    <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Sorumlu Temsilci' : 'Assigned Agent' ?></label>
                    <select id="filterAgent" class="form-control form-control-sm bg-light" style="border-radius:8px; border: 1px solid #e2e8f0; height:31px;">
                        <option value="all"><?= $isTr ? 'Tüm Temsilciler' : 'All Agents' ?></option>
                        <option value="unassigned"><?= $isTr ? 'Atanmamış' : 'Unassigned' ?></option>
                        <?php
                        $agents = $pdo->query("SELECT DISTINCT u.id, u.fullname FROM users u LEFT JOIN teams_users tu ON u.id = tu.user_id WHERE u.status = 1 AND (u.role IN (1,3) OR tu.team_id IS NOT NULL) ORDER BY u.fullname ASC")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($agents as $ag):
                        ?>
                            <option value="<?= $ag['id'] ?>"><?= htmlspecialchars($ag['fullname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Date Range Filter -->
                <div class="col-md-3">
                    <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'Oluşturma Tarih Aralığı' : 'Created Date Range' ?></label>
                    <div class="d-flex align-items-center">
                        <input type="date" id="filterStartDate" class="form-control form-control-sm bg-light mr-1" style="border-radius:8px; border: 1px solid #e2e8f0; height:31px;">
                        <span class="mx-1 text-muted small">-</span>
                        <input type="date" id="filterEndDate" class="form-control form-control-sm bg-light ml-1" style="border-radius:8px; border: 1px solid #e2e8f0; height:31px;">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Kanban columns -->
    <div class="kanban-board d-flex align-items-start" style="overflow-x: auto; padding-bottom: 20px;">
        <?php foreach ($columns as $statusKey => $col): ?>
        <div class="kanban-column mr-3 shadow-sm" style="min-width: 300px; max-width: 300px; background: #f8fafc; border-radius: 12px; padding: 12px; border: 1px solid #e2e8f0;">
            <div class="kanban-header d-flex justify-content-between align-items-center mb-3 px-1">
                <h6 class="font-weight-bold mb-0" style="color: <?= $col['color'] ?>;">
                    <i class="fas fa-circle mr-1" style="font-size:10px;"></i> <?= htmlspecialchars($col['title']) ?>
                </h6>
                <span class="badge badge-light shadow-sm border" style="border-radius: 8px; font-size:12px;"><?= count($col['items']) ?></span>
            </div>
            
            <div class="kanban-items" data-status="<?= $statusKey ?>" style="min-height: 500px;">
                <?php foreach ($col['items'] as $item): 
                    $prio = strtolower($item['priority'] ?? 'normal');
                    $prioClass = match($prio) {
                        'critical', 'urgent' => 'badge-danger',
                        'high' => 'badge-warning',
                        'low' => 'badge-secondary',
                        default => 'badge-info'
                    };
                    $prioLabel = match($prio) {
                        'critical' => $isTr ? 'Kritik' : 'Critical',
                        'urgent' => $isTr ? 'Acil' : 'Urgent',
                        'high' => $isTr ? 'Yüksek' : 'High',
                        'low' => $isTr ? 'Düşük' : 'Low',
                        default => $isTr ? 'Normal' : 'Normal'
                    };

                    // SLA / Due Date tracking
                    $now = time();
                    $dueTime = $item['sla_due_date'] ? strtotime($item['sla_due_date']) : null;
                    $statusLower = strtolower($item['status'] ?? 'open');
                    $isOverdue = $dueTime && ($now > $dueTime) && !in_array($statusLower, ['resolved', 'closed']);
                    $isApproaching = $dueTime && (!$isOverdue) && (($dueTime - $now) < 86400) && !in_array($statusLower, ['resolved', 'closed']);

                    $borderStyle = '';
                    if ($statusLower === 'resolved') {
                        $borderStyle = 'border-left: 4px solid #10b981 !important;';
                    } elseif ($statusLower === 'closed') {
                        $borderStyle = 'border-left: 4px solid #64748b !important;';
                    } elseif ($isOverdue) {
                        $borderStyle = 'border-left: 4px solid #ef4444 !important;';
                    } elseif ($isApproaching) {
                        $borderStyle = 'border-left: 4px solid #f59e0b !important;';
                    } else {
                        $borderStyle = 'border-left: 4px solid #3b82f6 !important;'; // default left border accent
                    }
                ?>
                    <div class="card kanban-item mb-2 shadow-sm border" 
                         data-id="<?= $item['id'] ?>" 
                         data-ticket-no="<?= htmlspecialchars($item['ticket_no'] ?? '') ?>"
                         data-title="<?= htmlspecialchars(strtolower($item['title'] ?? '')) ?>" 
                         data-priority="<?= htmlspecialchars(strtolower($item['priority'] ?? 'normal')) ?>" 
                         data-status="<?= htmlspecialchars(strtolower($item['status'] ?? 'open')) ?>"
                         data-agent-id="<?= (int)($item[$personnelCol] ?? 0) ?>"
                         data-team-id="<?= (int)($item['team_id'] ?? 0) ?>"
                         data-date="<?= date('Y-m-d', strtotime($item['created_at'])) ?>"
                         style="border-radius: 10px; cursor: grab; <?= $borderStyle ?>">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="badge <?= $prioClass ?>"><?= htmlspecialchars($prioLabel) ?></span>
                                <div class="d-flex align-items-center">
                                    <small class="text-muted font-weight-bold">#<?= htmlspecialchars($item['ticket_no'] ?: $item['id']) ?></small>
                                    <?php if (in_array($statusLower, ['closed', 'resolved'])): ?>
                                        <i class="fas fa-lock text-muted ml-2" title="<?= $isTr ? 'Salt Okunur / Kilitli' : 'Read-Only / Locked' ?>"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <h6 class="font-weight-bold mb-2">
                                <a href="<?= $base_url ?>bilet-detay/<?= $item['id'] ?>" class="text-dark hover-primary" style="text-decoration:none;"><?= htmlspecialchars($item['title'] ?: 'Destek Talebi') ?></a>
                            </h6>
                            <p class="text-muted small mb-2 text-truncate"><i class="far fa-user mr-1"></i><?= htmlspecialchars($item['creator_name'] ?? '-') ?></p>
                            
                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                <?php if($item['agent_name']): ?>
                                    <small class="text-primary font-weight-bold"><i class="fas fa-user-shield mr-1"></i> <?= htmlspecialchars($item['agent_name']) ?></small>
                                <?php else: ?>
                                    <small class="text-muted"><i class="fas fa-user-slash mr-1"></i> <?= $isTr ? 'Atanmamış' : 'Unassigned' ?></small>
                                <?php endif; ?>
                            </div>

                            <!-- SLA deadline info -->
                            <?php if ($item['sla_due_date']): ?>
                                <div class="mt-2 text-muted" style="font-size: 11px;">
                                    <i class="far fa-calendar-alt mr-1"></i> 
                                    <span class="sla-date-text"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($item['sla_due_date']))) ?></span>
                                    <span class="sla-badge-span">
                                        <?php if ($statusLower === 'resolved'): ?>
                                            <span class="text-success font-weight-bold ml-1"><i class="fas fa-check-circle"></i> <?= $isTr ? 'Çözüldü' : 'Resolved' ?></span>
                                        <?php elseif ($statusLower === 'closed'): ?>
                                            <span class="text-secondary font-weight-bold ml-1"><i class="fas fa-check-double"></i> <?= $isTr ? 'Kapatıldı' : 'Closed' ?></span>
                                        <?php elseif ($isOverdue): ?>
                                            <span class="text-danger font-weight-bold ml-1"><i class="fas fa-exclamation-circle"></i> <?= $isTr ? 'Süresi Geçti' : 'Overdue' ?></span>
                                        <?php elseif ($isApproaching): ?>
                                            <span class="text-warning font-weight-bold ml-1"><i class="fas fa-clock"></i> <?= $isTr ? 'Yaklaşıyor' : 'Approaching' ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
<script>
$(document).ready(function() {
    // Swal Toast definition
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });

    // Update ticket status via POST
    function updateTicketStatus(ticketId, newStatus, assigneeId, assigneeName, evt, itemEl, customerMsg = '') {
        $.post('<?= $base_url ?>ajax/update_ticket_status.php', {
            id: ticketId,
            status: newStatus,
            assignee_id: assigneeId,
            customer_message: customerMsg
        }, function(response) {
            if(response.success) {
                Toast.fire({
                    icon: 'success',
                    title: '<?= $isTr ? "Bilet durumu güncellendi." : "Ticket status updated." ?>'
                });
                
                // Update local data attribute
                itemEl.setAttribute('data-status', newStatus);
                
                if (newStatus === 'assigned' && assigneeId) {
                    itemEl.setAttribute('data-agent-id', assigneeId);
                    // Update agent name in card HTML
                    const agentContainer = itemEl.querySelector('.border-top');
                    if (agentContainer) {
                        agentContainer.innerHTML = '<small class="text-primary font-weight-bold"><i class="fas fa-user-shield mr-1"></i> ' + assigneeName + '</small>';
                    }
                } else if (newStatus === 'open') {
                    itemEl.setAttribute('data-agent-id', '0');
                    const agentContainer = itemEl.querySelector('.border-top');
                    if (agentContainer) {
                        agentContainer.innerHTML = '<small class="text-muted"><i class="fas fa-user-slash mr-1"></i> <?= $isTr ? "Atanmamış" : "Unassigned" ?></small>';
                    }
                }
                
                // Update border and SLA badge dynamically
                if (newStatus === 'resolved') {
                    itemEl.style.setProperty('border-left', '4px solid #10b981', 'important');
                    const badgeSpan = itemEl.querySelector('.sla-badge-span');
                    if (badgeSpan) {
                        badgeSpan.innerHTML = '<span class="text-success font-weight-bold ml-1"><i class="fas fa-check-circle"></i> <?= $isTr ? "Çözüldü" : "Resolved" ?></span>';
                    }
                } else if (newStatus === 'closed') {
                    itemEl.style.setProperty('border-left', '4px solid #64748b', 'important');
                    const badgeSpan = itemEl.querySelector('.sla-badge-span');
                    if (badgeSpan) {
                        badgeSpan.innerHTML = '<span class="text-secondary font-weight-bold ml-1"><i class="fas fa-check-double"></i> <?= $isTr ? "Kapatıldı" : "Closed" ?></span>';
                    }
                } else {
                    const dateTextEl = itemEl.querySelector('.sla-date-text');
                    if (dateTextEl) {
                        const dateText = dateTextEl.innerText.trim();
                        if (dateText) {
                            const parts = dateText.split(' ');
                            if (parts.length === 2) {
                                const dateParts = parts[0].split('.');
                                const timeParts = parts[1].split(':');
                                if (dateParts.length === 3 && timeParts.length === 2) {
                                    const dueTime = new Date(dateParts[2], dateParts[1] - 1, dateParts[0], timeParts[0], timeParts[1]);
                                    const now = new Date();
                                    const isOverdue = now > dueTime;
                                    const isApproaching = !isOverdue && ((dueTime - now) < 86400000);
                                    
                                    const badgeSpan = itemEl.querySelector('.sla-badge-span');
                                    if (badgeSpan) {
                                        if (isOverdue) {
                                            itemEl.style.setProperty('border-left', '4px solid #ef4444', 'important');
                                            badgeSpan.innerHTML = '<span class="text-danger font-weight-bold ml-1"><i class="fas fa-exclamation-circle"></i> <?= $isTr ? "Süresi Geçti" : "Overdue" ?></span>';
                                        } else if (isApproaching) {
                                            itemEl.style.setProperty('border-left', '4px solid #f59e0b', 'important');
                                            badgeSpan.innerHTML = '<span class="text-warning font-weight-bold ml-1"><i class="fas fa-clock"></i> <?= $isTr ? "Yaklaşıyor" : "Approaching" ?></span>';
                                        } else {
                                            itemEl.style.setProperty('border-left', '4px solid #3b82f6', 'important');
                                            badgeSpan.innerHTML = '';
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        itemEl.style.setProperty('border-left', '4px solid #3b82f6', 'important');
                    }
                }

                // If closed or resolved, append a lock icon if not already present
                const headerContainer = itemEl.querySelector('.d-flex.align-items-center');
                if (headerContainer) {
                    let lockIcon = headerContainer.querySelector('.fa-lock');
                    if (newStatus === 'closed' || newStatus === 'resolved') {
                        if (!lockIcon) {
                            headerContainer.insertAdjacentHTML('beforeend', '<i class="fas fa-lock text-muted ml-2" title="<?= $isTr ? "Salt Okunur / Kilitli" : "Read-Only / Locked" ?>"></i>');
                        }
                    } else {
                        if (lockIcon) {
                            lockIcon.remove();
                        }
                    }
                }
                
                // Update badge counts
                const oldBadge = evt.from.parentElement.querySelector('.badge');
                const newBadge = evt.to.parentElement.querySelector('.badge');
                oldBadge.innerText = parseInt(oldBadge.innerText) - 1;
                newBadge.innerText = parseInt(newBadge.innerText) + 1;
            } else {
                // Revert Sortable UI changes on failure
                evt.from.appendChild(itemEl);
                Swal.fire({
                    icon: 'error',
                    title: '<?= $isTr ? "Hata" : "Error" ?>',
                    text: response.message || '<?= $isTr ? "İşlem başarısız oldu." : "Operation failed." ?>',
                    background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                    color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
                });
            }
        }, 'json').fail(function() {
            // Revert Sortable UI changes on connection error
            evt.from.appendChild(itemEl);
            Swal.fire({
                icon: 'error',
                title: '<?= $isTr ? "Bağlantı Hatası" : "Connection Error" ?>',
                text: '<?= $isTr ? "Sunucuyla iletişim kurulamadı." : "Failed to communicate with server." ?>',
                background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b'
            });
        });
    }

    const columns = document.querySelectorAll('.kanban-items');
    columns.forEach(col => {
        new Sortable(col, {
            group: 'shared',
            animation: 150,
            ghostClass: 'bg-light',
            onEnd: function (evt) {
                const itemEl = evt.item;
                const newStatus = evt.to.getAttribute('data-status');
                const ticketId = itemEl.getAttribute('data-id');
                
                if(evt.from !== evt.to) {
                    if (newStatus === 'assigned') {
                        let loadingHtml = '<option value=""><?= $isTr ? "Yükleniyor..." : "Loading..." ?></option>';
                        
                        Swal.fire({
                            title: '<?= $isTr ? "Bileti Ata" : "Assign Ticket" ?>',
                            html: '<p class="small text-muted mb-2"><?= $isTr ? "Bu bileti hangi temsilciye atamak istiyorsunuz?" : "Which agent do you want to assign this ticket to?" ?></p>' +
                                  '<select id="assigneeSelect" class="form-control select2-no-search" style="border-radius:10px;" disabled>' + loadingHtml + '</select>',
                            showCancelButton: true,
                            confirmButtonText: '<?= $isTr ? "Atama Yap" : "Assign" ?>',
                            cancelButtonText: '<?= $isTr ? "İptal" : "Cancel" ?>',
                            confirmButtonColor: 'var(--primary-color, #3b82f6)',
                            cancelButtonColor: '#6b7280',
                            background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                            color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b',
                            didOpen: () => {
                                Swal.showLoading();
                                const teamId = itemEl.getAttribute('data-team-id');
                                $.getJSON('<?= $base_url ?>ajax/get_team_assignees.php', { team_id: teamId }, function(data) {
                                    let optionsHtml = '<option value=""><?= $isTr ? "Seçiniz..." : "Select..." ?></option>';
                                    if (data && data.length > 0) {
                                        data.forEach(function(ag) {
                                            const escapedName = $('<div>').text(ag.fullname).html();
                                            optionsHtml += '<option value="' + ag.id + '">' + escapedName + '</option>';
                                        });
                                    } else {
                                        optionsHtml = '<option value=""><?= $isTr ? "-- Takımda Temsilci Yok --" : "-- No Agents in Team --" ?></option>';
                                    }
                                    const selectEl = document.getElementById('assigneeSelect');
                                    selectEl.innerHTML = optionsHtml;
                                    selectEl.removeAttribute('disabled');
                                    Swal.hideLoading();
                                }).fail(function() {
                                    const selectEl = document.getElementById('assigneeSelect');
                                    selectEl.innerHTML = '<option value=""><?= $isTr ? "Hata oluştu" : "Error loading" ?></option>';
                                    Swal.hideLoading();
                                });
                            },
                            preConfirm: () => {
                                const val = document.getElementById('assigneeSelect').value;
                                if (!val) {
                                    Swal.showValidationMessage('<?= $isTr ? "Lütfen bir temsilci seçin!" : "Please select an agent!" ?>');
                                }
                                return val;
                            }
                        }).then((result) => {
                            if (result.isConfirmed) {
                                const selectedAgentId = result.value;
                                const selectEl = document.getElementById('assigneeSelect');
                                const selectedAgentName = selectEl.options[selectEl.selectedIndex].text;
                                updateTicketStatus(ticketId, newStatus, selectedAgentId, selectedAgentName, evt, itemEl);
                            } else {
                                // Revert Sortable UI changes if cancelled
                                evt.from.appendChild(itemEl);
                            }
                        });
                    } else if (newStatus === 'waiting_customer') {
                        Swal.fire({
                            title: '<?= $isTr ? "Müşteriye Mesaj Gönder" : "Send Message to Customer" ?>',
                            html: '<p class="small text-muted mb-2"><?= $isTr ? "Müşterinin yanıtını beklemek için bir açıklama veya mesaj yazın (bu mesaj biletin altında görünecektir):" : "Write a reply/explanation to wait for the customer\'s response (this will appear under the ticket):" ?></p>' +
                                  '<textarea id="customerMessageText" class="form-control" rows="4" style="border-radius:10px;" placeholder="<?= $isTr ? "Mesajınızı buraya yazın..." : "Type your message here..." ?>"></textarea>',
                            showCancelButton: true,
                            confirmButtonText: '<?= $isTr ? "Gönder ve Durumu Güncelle" : "Send & Update Status" ?>',
                            cancelButtonText: '<?= $isTr ? "İptal" : "Cancel" ?>',
                            confirmButtonColor: 'var(--primary-color, #3b82f6)',
                            cancelButtonColor: '#6b7280',
                            background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                            color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b',
                            preConfirm: () => {
                                const val = document.getElementById('customerMessageText').value;
                                if (!val.trim()) {
                                    Swal.showValidationMessage('<?= $isTr ? "Lütfen müşteri için bir mesaj yazın!" : "Please write a message for the customer!" ?>');
                                }
                                return val;
                            }
                        }).then((result) => {
                            if (result.isConfirmed) {
                                const customerMsg = result.value;
                                updateTicketStatus(ticketId, newStatus, '', '', evt, itemEl, customerMsg);
                            } else {
                                // Revert Sortable UI changes if cancelled
                                evt.from.appendChild(itemEl);
                            }
                        });
                    } else if (newStatus === 'resolved' || newStatus === 'closed') {
                        const isResolved = newStatus === 'resolved';
                        const titleText = isResolved ? 
                            '<?= $isTr ? "Bileti Çözüldü Olarak İşaretle" : "Mark Ticket as Resolved" ?>' : 
                            '<?= $isTr ? "Bileti Kapat" : "Close Ticket" ?>';
                        const labelText = isResolved ? 
                            '<?= $isTr ? "Müşteriye çözüm hakkında bir bilgilendirme mesajı yazın (bu mesaj biletin altında görünecektir):" : "Write an info message to the customer about the solution (this will appear under the ticket):" ?>' : 
                            '<?= $isTr ? "Müşteriye biletin kapatılması hakkında bir açıklama yazın (bu mesaj biletin altında görünecektir):" : "Write an explanation to the customer about closing the ticket (this will appear under the ticket):" ?>';
                        
                        Swal.fire({
                            title: titleText,
                            html: '<p class="small text-muted mb-2">' + labelText + '</p>' +
                                  '<textarea id="customerMessageText" class="form-control" rows="4" style="border-radius:10px;" placeholder="<?= $isTr ? "Mesajınızı buraya yazın..." : "Type your message here..." ?>"></textarea>',
                            showCancelButton: true,
                            confirmButtonText: '<?= $isTr ? "Kaydet ve Mesaj Gönder" : "Save & Send Message" ?>',
                            cancelButtonText: '<?= $isTr ? "İptal" : "Cancel" ?>',
                            confirmButtonColor: 'var(--primary-color, #3b82f6)',
                            cancelButtonColor: '#6b7280',
                            background: document.body.classList.contains('dark-mode') ? '#1e293b' : '#fff',
                            color: document.body.classList.contains('dark-mode') ? '#f1f5f9' : '#1e293b',
                            preConfirm: () => {
                                const val = document.getElementById('customerMessageText').value;
                                if (!val.trim()) {
                                    Swal.showValidationMessage('<?= $isTr ? "Lütfen müşteri için bir açıklama yazın!" : "Please write an explanation for the customer!" ?>');
                                }
                                return val;
                            }
                        }).then((result) => {
                            if (result.isConfirmed) {
                                const customerMsg = result.value;
                                updateTicketStatus(ticketId, newStatus, '', '', evt, itemEl, customerMsg);
                            } else {
                                // Revert Sortable UI changes if cancelled
                                evt.from.appendChild(itemEl);
                            }
                        });
                    } else {
                        updateTicketStatus(ticketId, newStatus, '', '', evt, itemEl);
                    }
                }
            }
        });
    });

    // Client-side filtering implementation
    function applyKanbanFilters() {
        const search = ($('#kanbanSearch').val() || '').toLowerCase().trim();
        const status = $('#filterStatus').val();
        const priority = $('#filterPriority').val();
        const agent = $('#filterAgent').val();
        const start = $('#filterStartDate').val();
        const end = $('#filterEndDate').val();

        $('.kanban-item').each(function() {
            const card = $(this);
            const cardId = String(card.data('id'));
            const cardNo = String(card.data('ticket-no')).toLowerCase();
            const cardTitle = String(card.data('title'));
            const cardPriority = String(card.data('priority'));
            const cardStatus = String(card.data('status'));
            const cardAgentId = String(card.data('agent-id'));
            const cardDate = card.data('date');

            const matchesSearch = search === '' || 
                                  cardId.indexOf(search) > -1 || 
                                  cardNo.indexOf(search) > -1 || 
                                  cardTitle.indexOf(search) > -1;

            const matchesStatus = status === 'all' || cardStatus === status;
            const matchesPriority = priority === 'all' || cardPriority === priority;

            let matchesAgent = true;
            if (agent === 'unassigned') {
                matchesAgent = cardAgentId === '0';
            } else if (agent !== 'all') {
                matchesAgent = cardAgentId === agent;
            }

            let matchesDate = true;
            if (start !== '') {
                matchesDate = matchesDate && (cardDate >= start);
            }
            if (end !== '') {
                matchesDate = matchesDate && (cardDate <= end);
            }

            const visible = matchesSearch && matchesStatus && matchesPriority && matchesAgent && matchesDate;
            card.toggle(visible);
        });

        // Update column counts dynamically
        $('.kanban-column').each(function() {
            const col = $(this);
            const visibleItems = col.find('.kanban-item:visible').length;
            col.find('.kanban-header .badge').text(visibleItems);
        });
    }

    // Bind filters
    $('#kanbanSearch').on('keyup', applyKanbanFilters);
    $('#filterStatus, #filterPriority, #filterAgent, #filterStartDate, #filterEndDate').on('change', applyKanbanFilters);
});
</script>
<style>
.kanban-item:active { cursor: grabbing !important; }
.hover-primary:hover { color: var(--primary-color, #007bff) !important; }

/* Dark Mode styles for Kanban board */
body.dark-mode .kanban-column {
    background: #1e293b !important;
    border: 1px solid rgba(255, 255, 255, 0.05) !important;
}
body.dark-mode .kanban-column .kanban-header .badge {
    background-color: #334155 !important;
    color: #f8fafc !important;
}
body.dark-mode .kanban-item {
    background: #0f172a !important;
    color: #f8fafc !important;
    border: 1px solid rgba(255, 255, 255, 0.05) !important;
}
body.dark-mode .kanban-item h6 a.text-dark {
    color: #f8fafc !important;
}
body.dark-mode .kanban-item .text-muted {
    color: #94a3b8 !important;
}
body.dark-mode .kanban-items .bg-light {
    background-color: #334155 !important;
}
body.dark-mode .kanban-item .border-top {
    border-top: 1px solid rgba(255, 255, 255, 0.05) !important;
}
</style>
