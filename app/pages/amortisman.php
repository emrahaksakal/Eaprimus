<?php
// app/pages/amortisman.php
if (!defined('EAPRIMUS_KEY')) {
    exit('Erişim Engellendi');
}

$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

// POST İşlemleri (Değerleri Güncelleme)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_depreciation') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $asset_id = intval($_POST['asset_id'] ?? 0);
    $salvage_val = floatval($_POST['salvage_value'] ?? 0);
    $useful_life = intval($_POST['useful_life_months'] ?? 60);

    if ($asset_id > 0 && $useful_life > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE assets SET salvage_value = ?, useful_life_months = ? WHERE id = ?");
            $stmt->execute([$salvage_val, $useful_life, $asset_id]);
            $_SESSION['mesaj'] = $isTr ? "Amortisman ayarları başarıyla güncellendi." : "Depreciation settings updated successfully.";
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Geçersiz parametreler']);
    }
    exit;
}

// Tüm amortismana tabi varlıkları getir
$stmt = $pdo->query("SELECT id, name, asset_tag, purchase_date, purchase_cost, salvage_value, useful_life_months FROM assets WHERE purchase_cost > 0 AND deleted_at IS NULL ORDER BY purchase_date DESC");
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Toplam İstatistikler
$total_cost = 0;
$total_net_value = 0;
$total_accumulated = 0;

$processed_assets = [];
foreach ($assets as $asset) {
    $cost = floatval($asset['purchase_cost']);
    $salvage = floatval($asset['salvage_value']);
    $life = intval($asset['useful_life_months']);
    if ($life <= 0) $life = 60; // Default 5 years

    $p_date = new DateTime($asset['purchase_date'] ?: 'now');
    $now = new DateTime();
    $diff = $p_date->diff($now);
    $months_passed = ($diff->y * 12) + $diff->m;

    if ($months_passed < 0) $months_passed = 0;

    // Doğrusal Amortisman (Straight-Line)
    $depreciable_amount = $cost - $salvage;
    if ($depreciable_amount < 0) $depreciable_amount = 0;

    $monthly_depreciation = $depreciable_amount / $life;
    $accumulated = $monthly_depreciation * $months_passed;

    if ($accumulated > $depreciable_amount) {
        $accumulated = $depreciable_amount;
    }

    $net_value = $cost - $accumulated;
    if ($net_value < $salvage) {
        $net_value = $salvage;
    }

    $total_cost += $cost;
    $total_accumulated += $accumulated;
    $total_net_value += $net_value;

    $asset['net_value'] = $net_value;
    $asset['accumulated'] = $accumulated;
    $asset['months_passed'] = $months_passed;
    $processed_assets[] = $asset;
}
?>

<!-- Content Header -->
<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="m-0 font-weight-bold text-dark"><i class="fas fa-calculator mr-2 text-primary"></i><?= $isTr ? 'Amortisman ve Değer Kaybı' : 'Depreciation & Valuation' ?></h1>
            <p class="text-muted small"><?= $isTr ? 'Demirbaşların satın alma maliyetlerine göre değer kaybı ve mali analizleri.' : 'Depreciation and financial analysis of assets based on purchase costs.' ?></p>
        </div>
    </div>
</div>

<!-- İstatistik Kartları -->
<div class="row">
    <div class="col-lg-3 col-6">
        <div class="card border-0 shadow-sm bg-gradient-primary text-white" style="border-radius:15px;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase small opacity-80"><?= $isTr ? 'Toplam Satın Alma Bedeli' : 'Total Purchase Cost' ?></h6>
                        <h3 class="font-weight-bold mb-0"><?= number_format($total_cost, 2) ?> ₺</h3>
                    </div>
                    <i class="fas fa-wallet fa-2x opacity-30"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="card border-0 shadow-sm bg-gradient-success text-white" style="border-radius:15px;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase small opacity-80"><?= $isTr ? 'Güncel Net Değer' : 'Current Net Value' ?></h6>
                        <h3 class="font-weight-bold mb-0"><?= number_format($total_net_value, 2) ?> ₺</h3>
                    </div>
                    <i class="fas fa-chart-line fa-2x opacity-30"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="card border-0 shadow-sm bg-gradient-danger text-white" style="border-radius:15px;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase small opacity-80"><?= $isTr ? 'Birikmiş Amortisman' : 'Accumulated Depreciation' ?></h6>
                        <h3 class="font-weight-bold mb-0"><?= number_format($total_accumulated, 2) ?> ₺</h3>
                    </div>
                    <i class="fas fa-level-down-alt fa-2x opacity-30"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="card border-0 shadow-sm bg-gradient-info text-white" style="border-radius:15px;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase small opacity-80"><?= $isTr ? 'Toplam Varlık Adedi' : 'Total Assets Count' ?></h6>
                        <h3 class="font-weight-bold mb-0"><?= count($processed_assets) ?></h3>
                    </div>
                    <i class="fas fa-boxes fa-2x opacity-30"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <!-- Varlık Listesi -->
    <div class="col-md-7">
        <div class="card border-0 shadow-sm" style="border-radius:15px;">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="card-title font-weight-bold text-dark mb-0"><i class="fas fa-list mr-2 text-primary"></i><?= $isTr ? 'Demirbaş Finansal Listesi' : 'Asset Financial List' ?></h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-muted">
                            <tr>
                                <th><?= $isTr ? 'Barkod/Kod' : 'Asset Tag/Code' ?></th>
                                <th><?= $isTr ? 'Varlık Adı' : 'Asset Name' ?></th>
                                <th class="text-right"><?= $isTr ? 'Maliyet' : 'Cost' ?></th>
                                <th class="text-right"><?= $isTr ? 'Net Değer' : 'Net Value' ?></th>
                                <th class="text-center"><?= $isTr ? 'İşlemler' : 'Actions' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($processed_assets)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <i class="fas fa-info-circle mr-1"></i> <?= $isTr ? 'Amortisman hesabına uygun (alış fiyatı girilmiş) varlık bulunamadı.' : 'No assets found with a valid purchase cost.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($processed_assets as $a): ?>
                                    <tr style="cursor: pointer;" onclick="viewChart(<?= $a['id'] ?>, <?= htmlspecialchars(json_encode($a['name'])) ?>, <?= $a['purchase_cost'] ?>, <?= $a['salvage_value'] ?>, <?= $a['useful_life_months'] ?>, '<?= $a['purchase_date'] ?>')">
                                        <td class="font-weight-bold text-primary">#<?= htmlspecialchars($a['asset_tag']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($a['name']) ?>
                                            <br><small class="text-muted"><?= $isTr ? 'Satın Alma:' : 'Purchased:' ?> <?= htmlspecialchars($a['purchase_date']) ?></small>
                                        </td>
                                        <td class="text-right font-weight-600"><?= number_format($a['purchase_cost'], 2) ?> ₺</td>
                                        <td class="text-right text-success font-weight-bold"><?= number_format($a['net_value'], 2) ?> ₺</td>
                                        <td class="text-center" onclick="event.stopPropagation();">
                                            <button class="btn btn-sm btn-outline-primary" style="border-radius:10px;" onclick="openEditModal(<?= $a['id'] ?>, <?= $a['salvage_value'] ?>, <?= $a['useful_life_months'] ?>)">
                                                <i class="fas fa-cog"></i>
                                            </button>
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

    <!-- Grafik ve Detay Analiz -->
    <div class="col-md-5">
        <div class="card border-0 shadow-sm" style="border-radius:15px; min-height: 400px;">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h5 class="card-title font-weight-bold text-dark mb-0"><i class="fas fa-chart-area mr-2 text-primary"></i><?= $isTr ? 'Değer Kaybı Projeksiyonu' : 'Valuation Projection' ?></h5>
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
                <div id="no-selection-msg" class="text-center my-auto text-muted">
                    <i class="fas fa-mouse-pointer fa-3x mb-3 text-light"></i>
                    <p class="mb-0"><?= $isTr ? 'Grafik ve değer düşüş projeksiyonunu görmek için sol tablodan bir varlığa tıklayınız.' : 'Click on an asset from the table to view its valuation chart projection.' ?></p>
                </div>
                <div id="chart-container" class="d-none w-100">
                    <h6 id="selected-asset-title" class="font-weight-bold text-center text-primary mb-3"></h6>
                    <div style="position: relative; height: 250px; width: 100%;">
                        <canvas id="depreciationChart"></canvas>
                    </div>
                    <div class="row text-center mt-3 small">
                        <div class="col-4">
                            <span class="text-muted d-block"><?= $isTr ? 'Kalan Ömür (Ay)' : 'Rem. Life (Months)' ?></span>
                            <strong id="rem-life-val" class="text-dark font-weight-bold"></strong>
                        </div>
                        <div class="col-4">
                            <span class="text-muted d-block"><?= $isTr ? 'Hurda Değeri' : 'Salvage Value' ?></span>
                            <strong id="salvage-val" class="text-dark font-weight-bold"></strong>
                        </div>
                        <div class="col-4">
                            <span class="text-muted d-block"><?= $isTr ? 'Aylık Amortisman' : 'Monthly Dep.' ?></span>
                            <strong id="monthly-dep-val" class="text-success font-weight-bold"></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Amortisman Düzenleme Modalı -->
<div class="modal fade" id="editDepreciationModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius:20px; border:none;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title font-weight-bold text-dark"><i class="fas fa-sliders-h mr-2 text-primary"></i><?= $isTr ? 'Amortisman Ayarları' : 'Depreciation Settings' ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="depreciationForm">
                    <input type="hidden" name="asset_id" id="modal_asset_id">
                    <input type="hidden" name="action" value="update_depreciation">
                    
                    <div class="form-group">
                        <label class="small font-weight-bold text-muted"><?= $isTr ? 'Hurda / Kalıntı Değeri (₺)' : 'Salvage / Residual Value (₺)' ?></label>
                        <input type="number" step="0.01" name="salvage_value" id="modal_salvage_value" class="form-control" style="border-radius:10px;" required>
                        <small class="text-muted"><?= $isTr ? 'Amortisman süresi sonunda kalacak olan minimum finansal değer.' : 'Minimum financial value remaining at the end of useful life.' ?></small>
                    </div>

                    <div class="form-group">
                        <label class="small font-weight-bold text-muted"><?= $isTr ? 'Faydalı Ömür (Ay)' : 'Useful Life (Months)' ?></label>
                        <input type="number" name="useful_life_months" id="modal_useful_life_months" class="form-control" style="border-radius:10px;" required>
                        <small class="text-muted"><?= $isTr ? 'Cihazın amortisman düşüleceği toplam süre (Örn: 5 yıl için 60 ay).' : 'Total periods for depreciation (e.g. 60 months for 5 years).' ?></small>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button type="button" class="btn btn-light mr-2" style="border-radius:10px;" data-dismiss="modal"><?= $isTr ? 'İptal' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;"><?= $isTr ? 'Kaydet ve Güncelle' : 'Save & Update' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let currentChart = null;

function viewChart(id, name, cost, salvage, life, purchaseDate) {
    document.getElementById('no-selection-msg').classList.add('d-none');
    document.getElementById('chart-container').classList.remove('d-none');
    document.getElementById('selected-asset-title').innerText = name;

    const pDate = new Date(purchaseDate);
    const today = new Date();
    const monthsPassed = Math.max(0, (today.getFullYear() - pDate.getFullYear()) * 12 + (today.getMonth() - pDate.getMonth()));
    const remLife = Math.max(0, life - monthsPassed);

    const depreciable = cost - salvage;
    const monthlyDep = depreciable > 0 ? (depreciable / life) : 0;

    document.getElementById('rem-life-val').innerText = remLife + ' Ay / Months';
    document.getElementById('salvage-val').innerText = salvage.toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ₺';
    document.getElementById('monthly-dep-val').innerText = monthlyDep.toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ₺';

    // Grafik Noktaları Hesapla
    const labels = [];
    const dataValues = [];

    const years = Math.ceil(life / 12);
    for (let i = 0; i <= years; i++) {
        const currentMonth = i * 12;
        const currentPass = Math.min(currentMonth, life);
        const currentDep = monthlyDep * currentPass;
        const currentNet = Math.max(salvage, cost - currentDep);

        labels.push(i === 0 ? 'Alış / Purchase' : i + '. Yıl / Year');
        dataValues.push(currentNet);

        if (currentPass === life) break;
    }

    if (currentChart) {
        currentChart.destroy();
    }

    const ctx = document.getElementById('depreciationChart').getContext('2d');
    
    // Gradient Background for Line Chart
    const gradient = ctx.createLinearGradient(0, 0, 0, 200);
    gradient.addColorStop(0, 'rgba(59, 130, 246, 0.4)');
    gradient.addColorStop(1, 'rgba(59, 130, 246, 0.0)');

    currentChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: '<?= $isTr ? "Net Değer (₺)" : "Net Value (₺)" ?>',
                data: dataValues,
                borderColor: '#3b82f6',
                borderWidth: 3,
                backgroundColor: gradient,
                fill: true,
                tension: 0.3,
                pointBackgroundColor: '#2563eb',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    grid: { color: '#f3f4f6' },
                    ticks: { callback: value => value.toLocaleString('tr-TR') + ' ₺' }
                },
                x: { grid: { display: false } }
            }
        }
    });
}

function openEditModal(id, salvage, life) {
    document.getElementById('modal_asset_id').value = id;
    document.getElementById('modal_salvage_value').value = salvage;
    document.getElementById('modal_useful_life_months').value = life;
    $('#editDepreciationModal').modal('show');
}

// Form Post İşlemi (AJAX)
document.getElementById('depreciationForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);

    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    })
    .then(data => {
        if (data && data.success) {
            $('#editDepreciationModal').modal('hide');
            location.reload();
        } else {
            Swal.fire({
                icon: 'error',
                title: '<?= $isTr ? "Hata" : "Error" ?>',
                text: (data && data.error) ? data.error : '<?= $isTr ? "İşlem başarısız." : "Operation failed." ?>'
            });
        }
    })
    .catch(err => {
        console.error('Depreciation update error:', err);
        Swal.fire({
            icon: 'error',
            title: '<?= $isTr ? "Hata" : "Error" ?>',
            text: '<?= $isTr ? "Sunucu ile iletişimde bir sorun oluştu." : "Server error." ?>'
        });
    });
});
</script>
