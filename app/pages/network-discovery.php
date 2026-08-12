<?php
require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/db.php";
requireLogin();

$pdo = db();
$current_user_role = $_SESSION['role'] ?? 2;

if (!in_array((int)$current_user_role, [1, 3])) {
    $_SESSION['mesaj'] = __("Hata") . ": " . __("no_permission_page");
    header("Location: anasayfa");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();
    if ($_POST['action'] === 'clear_discovery') {
        $pdo->exec("DELETE FROM discovered_assets");
        $_SESSION['mesaj'] = __("Keşfedilen cihaz geçmişi başarıyla temizlendi.");
        header("Location: network-discovery");
        exit;
    } elseif ($_POST['action'] === 'delete_id' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("DELETE FROM discovered_assets WHERE id = ?");
        $stmt->execute([(int) $_POST['id']]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// Geçmiş taramalar
$pending = $pdo->query("
    SELECT d.*, 
           (SELECT COUNT(*) FROM assets a WHERE (a.ip_address COLLATE utf8mb4_unicode_ci = d.ip_address COLLATE utf8mb4_unicode_ci AND d.ip_address != '') OR (a.mac_address COLLATE utf8mb4_unicode_ci = d.mac_address COLLATE utf8mb4_unicode_ci AND d.mac_address != '')) as is_added 
    FROM discovered_assets d 
    ORDER BY d.discovered_at DESC 
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .scan-card {
        border-radius: 15px;
        border: none;
    }

    .pulse-dot {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #28a745;
        animation: pulse 1.2s infinite;
    }

    @keyframes pulse {

        0%,
        100% {
            transform: scale(1);
            opacity: 1;
        }

        50% {
            transform: scale(1.4);
            opacity: 0.5;
        }
    }

    #progressBar {
        transition: width 0.2s;
    }

    .device-row td {
        vertical-align: middle;
    }

    .badge-pending {
        background: #ffc107;
        color: #333;
    }

    .badge-added {
        background: #28a745;
        color: #fff;
    }
</style>

<div class="row">
    <!-- Tarama Paneli -->
    <div class="col-lg-4 mb-3">
        <div class="card scan-card shadow-sm">
            <div class="card-header bg-primary text-white" style="border-radius:15px 15px 0 0;">
                <h5 class="mb-0"><i class="fas fa-wifi mr-2"></i><?= __("network_scanner") ?></h5>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="font-weight-bold small text-muted"><?= __("subnet_placeholder") ?></label>
                    <div class="input-group">
                        <input type="text" id="subnetInput" class="form-control border-0 bg-light" value="192.168.1.0/24"
                            placeholder="192.168.1.0/24">
                    </div>
                    <small class="text-muted"><?= __("subnet_hint") ?></small>
                </div>

                <div id="scanProgress" class="d-none mb-3">
                    <div class="d-flex align-items-center mb-2">
                        <span class="pulse-dot mr-2"></span>
                        <span class="text-sm font-weight-bold text-primary" id="scanStatus"><?= __("scanning") ?></span>
                    </div>
                    <div class="progress" style="height:8px; border-radius:4px;">
                        <div id="progressBar" class="progress-bar bg-primary progress-bar-striped progress-bar-animated"
                            style="width:0%"></div>
                    </div>
                </div>

                <div class="row px-3">
                    <button class="btn btn-primary shadow-sm flex-fill mr-2" id="startScanBtn" onclick="startScan()"
                        style="border-radius:10px;">
                        <i class="fas fa-search-location mr-2"></i><?= __("start_scan") ?>
                    </button>
                    <button class="btn btn-danger shadow-sm d-none" id="stopScanBtn" onclick="stopScan()"
                        style="border-radius:10px;">
                        <i class="fas fa-stop mr-2"></i><?= __("stop_scan") ?>
                    </button>
                </div>

                <div id="scanSummary" class="mt-3 d-none">
                    <div class="alert alert-success border-0 py-2 shadow-sm" style="border-radius:10px;">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span id="summaryText"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sonuçlar -->
    <div class="col-lg-8">
        <div class="card scan-card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center"
                style="border-radius:15px 15px 0 0;">
                <h5 class="mb-0 font-weight-bold"><i
                        class="fas fa-list-ul text-primary mr-2"></i><?= __("discovered_devices") ?>
                </h5>
                <div class="d-flex align-items-center">
                    <div class="input-group input-group-sm mr-3" style="width: 200px;">
                        <input type="text" id="tableSearch" class="form-control"
                            placeholder="<?= __("search_ip_host") ?>" onkeyup="filterTable()">
                        <div class="input-group-append">
                            <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        </div>
                    </div>
                    <span class="badge badge-primary px-3 py-2 mr-2" id="deviceCount">
                        <?= count($pending) ?> <?= __("record") ?>
                    </span>
                    <?php if (!empty($pending)): ?>
                        <form method="POST" class="d-inline"
                            onsubmit="return confirm('<?= __("clear_discovery_confirm") ?>');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="clear_discovery">
                            <button type="submit" class="btn btn-sm btn-outline-danger shadow-sm"><i
                                    class="fas fa-trash-alt mr-1"></i><?= __("delete_all") ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="deviceTable">
                        <thead class="bg-light">
                            <tr>
                                <th class="pl-3"><?= __("ip_address") ?></th>
                                <th><?= __("hostname") ?></th>
                                <th><?= __("mac_address") ?></th>
                                <th><?= __("last_seen") ?></th>
                                <th><?= __("status") ?></th>
                                <th class="text-right pr-3"><?= __("action") ?></th>
                            </tr>
                        </thead>
                        <tbody id="deviceTableBody">
                            <?php if (empty($pending)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-5"><?= __("no_scans_done") ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pending as $d): ?>
                                    <tr class="device-row">
                                        <td class="pl-3 font-weight-bold text-primary">
                                            <?= htmlspecialchars($d['ip_address']) ?>
                                        </td>
                                        <td class="text-muted">
                                            <?= htmlspecialchars($d['hostname'] ?: '—') ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($d['mac_address'] ?: '—') ?></code></td>
                                        <td><small>
                                                <?= date('d.m.Y H:i', strtotime($d['discovered_at'])) ?>
                                            </small></td>
                                        <td><span class="badge badge-<?= $d['is_added'] > 0 ? 'added' : 'pending' ?> px-2 py-1"
                                                style="border-radius:8px;">
                                                <?= $d['is_added'] > 0 ? __("added") : __("pending") ?>
                                            </span></td>
                                        <td class="text-right pr-3">
                                            <div class="btn-group">
                                                <?php if ($d['is_added'] == 0): ?>
                                                    <button class="btn btn-sm btn-success shadow-sm"
                                                        style="border-radius:8px 0 0 8px;"
                                                        onclick="addToAssets('<?= htmlspecialchars($d['ip_address']) ?>', '<?= htmlspecialchars($d['mac_address']) ?>', '<?= htmlspecialchars($d['hostname']) ?>', <?= $d['id'] ?>)">
                                                        <i class="fas fa-plus mr-1"></i><?= __("add") ?>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-success disabled"
                                                        style="border-radius:8px 0 0 8px;">
                                                        <i class="fas fa-check mr-1"></i><?= __("added") ?>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-danger" style="border-radius:0 8px 8px 0;"
                                                    onclick="deleteSingle(<?= $d['id'] ?>, this)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>



<script>
    let isScanning = false;
    let scanCancelled = false;

    // A baglantilarina tiklayinca ozel uyarimizi goster (modern popup)
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', function(e) {
                if (isScanning && !this.hasAttribute('target')) {
                    e.preventDefault();
                    const href = this.getAttribute('href');
                    if (href && href !== '#' && !href.startsWith('javascript:')) {
                        Swal.fire({
                            icon: 'warning',
                            title: '<?= __("Hata") ?>',
                            text: '<?= __("Yaptığınız değişiklikler kaydedilmemiş olabilir. Tarama devam ediyor, çıkmak istiyor musunuz?") ?>',
                            showCancelButton: true,
                            confirmButtonText: '<?= __("evet") ?>',
                            cancelButtonText: '<?= __("iptal") ?>',
                            confirmButtonColor: '#d33'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                isScanning = false;
                                window.location.href = href;
                            }
                        });
                    }
                }
            });
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (isScanning) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    
    function stopScan() {
        scanCancelled = true;
        isScanning = false;
        document.getElementById('startScanBtn').classList.remove('d-none');
        document.getElementById('stopScanBtn').classList.add('d-none');
        document.getElementById('scanStatus').textContent = '<?= __("scan_stopped") ?>';
    }

    async function startScan() {
        const subnet = document.getElementById('subnetInput').value.trim();
        if (!subnet) {
            Swal.fire({ icon: 'warning', title: '<?= __("Hata") ?>', text: '<?= __("enter_subnet_msg") ?>', confirmButtonText: '<?= __("ok") ?>' });
            return;
        }

        document.getElementById('startScanBtn').disabled = true;
        document.getElementById('startScanBtn').classList.add('d-none');
        document.getElementById('stopScanBtn').classList.remove('d-none');
        
        document.getElementById('scanProgress').classList.remove('d-none');
        document.getElementById('scanSummary').classList.add('d-none');
        document.getElementById('progressBar').style.width = '0%';
        document.getElementById('scanStatus').textContent = '0% <?= __("scanning") ?>';
        
        isScanning = true;
        scanCancelled = false;
        let tbody = document.getElementById('deviceTableBody');

        try {
            // 1. Gerekli IP listesini al
            const ipReq = await fetch('<?= $base_url ?>ajax/network-scan', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_ips&range=' + encodeURIComponent(subnet) + '&csrf_token=<?= $_SESSION["csrf_token"] ?? "" ?>'
            });
            const ipData = await ipReq.json();
            
            if (ipData.error) throw new Error(ipData.error);
            const allIps = ipData.ips || [];
            if (allIps.length === 0) throw new Error('Ağ aralığı hesaplanamadı.');

            // Eger ilk defa tarama yapiliyorsa "henuz yapilmadi" yazisini temizle
            if (tbody.innerHTML.includes('<?= __("no_scans_done") ?>')) {
                tbody.innerHTML = '';
            }

            let foundCount = 0;
            const chunkSize = 15; // Her defasında 15 cihaz tara
            let totalScanned = 0;
            
            // 2. Ipleri chunklar halinde tara
            for (let i = 0; i < allIps.length; i += chunkSize) {
                if (scanCancelled) break; // Kullanici durdurduysa donguden cik
                if (!isScanning) break; // Sayfa degistirildiyse cik
                
                const chunk = allIps.slice(i, i + chunkSize);
                
                const chunkReq = await fetch('<?= $base_url ?>ajax/network-scan', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=scan_chunk&ips=' + encodeURIComponent(JSON.stringify(chunk)) + '&csrf_token=<?= $_SESSION["csrf_token"] ?? "" ?>'
                });
                
                const chunkData = await chunkReq.json();
                if (chunkData.discovered && chunkData.discovered.length > 0) {
                    chunkData.discovered.forEach(d => {
                        foundCount++;
                        // Yeni cihazi tabloya aninda (ustten) ekle
                        tbody.innerHTML = `<tr class="device-row" style="background:#f4fdf8;">
                        <td class="pl-3 font-weight-bold text-primary">${d.ip}</td>
                        <td class="text-muted">${d.hostname || '—'}</td>
                        <td><code>${d.mac || '—'}</code></td>
                        <td><small><?= __("now") ?></small></td>
                        <td><span class="badge badge-pending px-2 py-1" style="border-radius:8px;"><?= __("pending") ?></span></td>
                        <td class="text-right pr-3">
                            <div class="btn-group">
                                <button class="btn btn-sm btn-success shadow-sm" style="border-radius:8px 0 0 8px;" onclick="addToAssets('${d.ip}', '${d.mac}', '${d.hostname}', ${d.id || 0})">
                                    <i class="fas fa-plus mr-1"></i><?= __("add") ?>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" style="border-radius:0 8px 8px 0;" onclick="deleteSingle(${d.id || 0}, this)">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </td>
                    </tr>` + tbody.innerHTML;
                    });
                    
                    document.getElementById('summaryText').textContent = foundCount + ' <?= __("active_devices_found") ?>';
                    document.getElementById('scanSummary').classList.remove('d-none');
                    document.getElementById('deviceCount').textContent = foundCount + ' <?= __("new_device") ?>';
                }
                
                totalScanned += chunk.length;
                let prog = (totalScanned / allIps.length) * 100;
                document.getElementById('progressBar').style.width = prog + '%';
                document.getElementById('scanStatus').textContent = Math.round(prog) + '% <?= __("scanning") ?>';
            }
            
            if (scanCancelled) {
                Swal.fire({
                    icon: 'info',
                    title: '<?= __("scan_stopped") ?>',
                    text: foundCount + ' cihaz bulundu ve kaydedildi.',
                    confirmButtonText: '<?= __("ok") ?>'
                });
            } else {
                document.getElementById('scanStatus').textContent = '<?= __("completed") ?>';
                
                if (foundCount === 0) {
                    Swal.fire({ icon: 'info', title: '<?= __("Sonuç") ?>', text: subnet + ' subnet\'inde aktif cihaz bulunamadı.', confirmButtonText: '<?= __("ok") ?>' });
                } else {
                    Swal.fire({
                        icon: 'success',
                        title: '<?= __("completed") ?>',
                        html: `<b>${foundCount}</b> <?= __("active_devices_discovered") ?><br><small class="text-muted"><?= __("see_devices_in_table") ?></small>`,
                        confirmButtonText: '<?= __("great") ?>',
                        timer: 4000,
                        timerProgressBar: true
                    });
                }
            }

        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: '<?= __("Hata") ?>',
                html: 'Tarama hatası.<br><small class="text-muted">' + err.message + '</small>',
                confirmButtonText: '<?= __("ok") ?>'
            });
        } finally {
            isScanning = false;
            document.getElementById('startScanBtn').disabled = false;
            document.getElementById('startScanBtn').classList.remove('d-none');
            document.getElementById('stopScanBtn').classList.add('d-none');
        }
    }

    function addToAssets(ip, mac, hostname, discoveredId) {
        // Doğrudan varliklar.php'ye yönlendir ve parametreleri ver
        let url = '<?= $base_url ?>varliklar?view=assets&auto_add=1' +
                  '&ip=' + encodeURIComponent(ip) +
                  '&mac=' + encodeURIComponent(mac) +
                  '&hostname=' + encodeURIComponent(hostname || ip) +
                  '&disc_id=' + discoveredId;
        window.location.href = url;
    }

    function deleteSingle(id, btn) {
        if (!confirm('<?= __("Bu cihazı listeden silmek istediğinize emin misiniz?") ?>')) return;

        const row = btn.closest('tr');
        const formData = new FormData();
        formData.append('action', 'delete_id');
        formData.append('id', id);
        formData.append('csrf_token', '<?= $_SESSION["csrf_token"] ?? "" ?>');

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                }
            });
    }

    function filterTable() {
        const input = document.getElementById('tableSearch');
        const filter = input.value.toLowerCase();
        const rows = document.querySelectorAll('#deviceTableBody tr.device-row');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    }
</script>