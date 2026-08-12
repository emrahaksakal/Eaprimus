<?php
// automations_ui.php
$queues = $pdo->query("SELECT id, name FROM queues")->fetchAll(PDO::FETCH_ASSOC);
$rules = $pdo->query("SELECT * FROM ticket_rules ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="settings-section <?= $active_tab === 'automations' ? 'active' : '' ?>" id="section-automations">
    <div class="card shadow-sm mb-4" style="border-radius:12px; border:none;">
        <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-bottom:1px solid #f0f0f0; padding:20px 24px;">
            <h5 class="mb-0 font-weight-bold"><i class="fas fa-robot mr-2 text-primary"></i><?= $isTr ? 'Otomasyon Kuralları (Tetikleyiciler)' : 'Automation Rules (Triggers)' ?></h5>
            <button type="button" class="btn btn-sm btn-primary" onclick="showAddRuleModal()"><i class="fas fa-plus mr-1"></i><?= $isTr ? 'Yeni Kural Ekle' : 'Add New Rule' ?></button>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="bg-light">
                    <tr>
                        <th><?= $isTr ? 'Kural Adı' : 'Rule Name' ?></th>
                        <th><?= $isTr ? 'Koşul' : 'Condition' ?></th>
                        <th><?= $isTr ? 'Aksiyon' : 'Action' ?></th>
                        <th><?= $isTr ? 'Durum' : 'Status' ?></th>
                        <th class="text-right"><?= $isTr ? 'İşlem' : 'Action' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($rules)): ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted"><?= $isTr ? 'Henüz kural eklenmemiş.' : 'No rules added yet.' ?></td></tr>
                    <?php else: foreach($rules as $r): ?>
                    <tr>
                        <td class="font-weight-bold"><?= htmlspecialchars($r['rule_name']) ?></td>
                        <td>
                            <span class="badge badge-info"><?= $r['condition_field'] ?></span>
                            <span class="badge badge-secondary"><?= $r['condition_operator'] ?></span>
                            <strong><?= htmlspecialchars($r['condition_value']) ?></strong>
                        </td>
                        <td>
                            <span class="badge badge-primary"><?= $r['action_type'] ?></span> ->
                            <strong><?= htmlspecialchars($r['action_value']) ?></strong>
                        </td>
                        <td>
                            <?php if($r['is_active']): ?>
                                <span class="badge badge-success"><?= $isTr ? 'Aktif' : 'Active' ?></span>
                            <?php else: ?>
                                <span class="badge badge-danger"><?= $isTr ? 'Pasif' : 'Inactive' ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteRule(<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAddRule" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="anasayfa?route=sistem-ayarlari" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="section" value="automations_add">
                <div class="modal-header">
                    <h5 class="modal-title"><?= $isTr ? 'Yeni Kural Ekle' : 'Add New Rule' ?></h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label><?= $isTr ? 'Kural Adı' : 'Rule Name' ?></label>
                        <input type="text" name="rule_name" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><?= $isTr ? 'Hangi Alanda?' : 'Field' ?></label>
                                <select name="condition_field" class="form-control" required>
                                    <option value="subject"><?= $isTr ? 'Bilet Konusu (Subject)' : 'Ticket Subject' ?></option>
                                    <option value="body"><?= $isTr ? 'Bilet İçeriği (Body)' : 'Ticket Body' ?></option>
                                    <option value="customer_email"><?= $isTr ? 'Müşteri E-postası' : 'Customer Email' ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><?= $isTr ? 'Operatör' : 'Operator' ?></label>
                                <select name="condition_operator" class="form-control" required>
                                    <option value="contains"><?= $isTr ? 'İçeriyorsa (Contains)' : 'Contains' ?></option>
                                    <option value="equals"><?= $isTr ? 'Eşitse (Equals)' : 'Equals' ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><?= $isTr ? 'Değer' : 'Value' ?></label>
                                <input type="text" name="condition_value" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= $isTr ? 'Ne Yapılsın? (Aksiyon)' : 'Action' ?></label>
                                <select name="action_type" id="action_type" class="form-control" onchange="updateActionValue()" required>
                                    <option value="set_queue"><?= $isTr ? 'Kuyruğa Ata' : 'Assign to Queue' ?></option>
                                    <option value="set_priority"><?= $isTr ? 'Önceliği Değiştir' : 'Change Priority' ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= $isTr ? 'Aksiyon Değeri' : 'Action Value' ?></label>
                                <select name="action_value" id="action_value" class="form-control" required>
                                    <!-- Populated via JS -->
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __("cancel") ?></button>
                    <button type="submit" class="btn btn-primary"><?= __("save") ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const queues = <?= json_encode($queues) ?>;
const priorities = {
    'low': '<?= $isTr ? 'Düşük' : 'Low' ?>',
    'normal': '<?= $isTr ? 'Normal' : 'Normal' ?>',
    'high': '<?= $isTr ? 'Yüksek' : 'High' ?>',
    'critical': '<?= $isTr ? 'Kritik' : 'Critical' ?>'
};

function updateActionValue() {
    const type = document.getElementById('action_type').value;
    const valSelect = document.getElementById('action_value');
    valSelect.innerHTML = '';
    
    if (type === 'set_queue') {
        queues.forEach(q => {
            valSelect.innerHTML += `<option value="${q.id}">${q.name}</option>`;
        });
    } else if (type === 'set_priority') {
        Object.keys(priorities).forEach(k => {
            valSelect.innerHTML += `<option value="${k}">${priorities[k]}</option>`;
        });
    }
}

function showAddRuleModal() {
    updateActionValue();
    $('#modalAddRule').modal('show');
}

function deleteRule(id) {
    if(confirm('<?= $isTr ? "Bu kuralı silmek istediğinize emin misiniz?" : "Are you sure you want to delete this rule?" ?>')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'anasayfa?route=sistem-ayarlari';
        
        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = document.querySelector('input[name="csrf_token"]').value;
        
        const sec = document.createElement('input');
        sec.type = 'hidden';
        sec.name = 'section';
        sec.value = 'automations_delete';
        
        const ruleId = document.createElement('input');
        ruleId.type = 'hidden';
        ruleId.name = 'rule_id';
        ruleId.value = id;
        
        form.appendChild(csrf);
        form.appendChild(sec);
        form.appendChild(ruleId);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
