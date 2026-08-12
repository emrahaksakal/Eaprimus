<?php
// app/pages/sayim.php
if (!defined('EAPRIMUS_KEY')) {
    exit('Erişim Engellendi');
}

$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';

// AJAX Endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'get_audit_stats') {
        try {
            $totalAssets = intval($pdo->query("SELECT COUNT(*) FROM assets WHERE deleted_at IS NULL")->fetchColumn());
            $auditedAssets = intval($pdo->query("SELECT COUNT(DISTINCT asset_id) FROM asset_timeline WHERE event_type = 'audit' AND item_type = 'asset' AND is_deleted = 0")->fetchColumn());
            $pendingAssets = max(0, $totalAssets - $auditedAssets);
            $percent = $totalAssets > 0 ? round(($auditedAssets / $totalAssets) * 100, 1) : 0;
            echo json_encode([
                'success' => true,
                'total' => $totalAssets,
                'audited' => $auditedAssets,
                'pending' => $pendingAssets,
                'percent' => $percent
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_all_assets') {
        try {
            $stmt = $pdo->prepare("
                SELECT id, name, asset_tag, serial_no, 
                       SUBSTR(MD5(CONCAT(COALESCE(NULLIF(asset_tag, ''), CAST(id AS CHAR)), 'inventory_secure_2024_super_salt')), 1, 16) as public_token 
                FROM assets 
                WHERE deleted_at IS NULL 
                ORDER BY asset_tag ASC, id ASC
            ");
            $stmt->execute();
            $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'assets' => $assets]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_recent_audits') {
        try {
            $stmt = $pdo->prepare("
                SELECT at.created_at, at.asset_id, a.asset_tag, a.name as asset_name, u.fullname as auditor_name 
                FROM asset_timeline at
                JOIN assets a ON at.asset_id = a.id
                LEFT JOIN users u ON at.user_id = u.id
                WHERE at.event_type = 'audit' AND at.item_type = 'asset' AND at.is_deleted = 0
                ORDER BY at.created_at DESC
                LIMIT 10
            ");
            $stmt->execute();
            $audits = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'audits' => $audits]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'check_tag') {
        $tag = trim($_POST['tag'] ?? '');
        if (empty($tag)) {
            echo json_encode(['success' => false, 'error' => $isTr ? 'Boş barkod kodu' : 'Empty tag code']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT a.id, a.name, a.asset_tag, a.serial_no, 
                       COALESCE(NULLIF(a.image, ''), m.image) as image, 
                       u.fullname as assigned_user, sl.name as status_name, sl.color as status_color 
                FROM assets a 
                LEFT JOIN asset_models m ON a.model_id = m.id 
                LEFT JOIN users u ON a.assigned_user_id = u.id 
                LEFT JOIN asset_status_labels sl ON a.status_id = sl.id 
                WHERE (a.asset_tag = ? OR a.serial_no = ? OR CAST(a.id AS CHAR) = ?) AND a.deleted_at IS NULL
            ");
            $stmt->execute([$tag, $tag, $tag]);
            $asset = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($asset) {
                echo json_encode(['success' => true, 'asset' => $asset]);
            } else {
                echo json_encode(['success' => false, 'error' => $isTr ? 'Varlık bulunamadı!' : 'Asset not found!']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'confirm_audit') {
        $asset_id = intval($_POST['asset_id'] ?? 0);
        if ($asset_id <= 0) {
            echo json_encode(['success' => false, 'error' => $isTr ? 'Geçersiz Varlık ID' : 'Invalid Asset ID']);
            exit;
        }

        try {
            $desc = $isTr 
                ? "Envanter sayımında varlık fiziksel olarak görüldü ve onaylandı. (Hızlı Sayım Modülü)" 
                : "Asset physically verified and approved during inventory audit. (Quick Audit Module)";

            $stmt = $pdo->prepare("INSERT INTO asset_timeline (asset_id, item_type, user_id, event_type, event_description) VALUES (?, 'asset', ?, 'audit', ?)");
            $stmt->execute([$asset_id, $current_user_id, $desc]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
?>

<!-- Include HTML5-QRCode and PDF Libraries -->
<script src="https://unpkg.com/html5-qrcode/html5-qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<style>
    .scanner-card {
        border-radius: 16px;
        border: none;
        background: #ffffff;
    }
    body.dark-mode .scanner-card {
        background: #1e293b;
        color: #f8fafc;
    }
    #reader {
        width: 100%;
        border: none !important;
        border-radius: 15px;
        overflow: hidden;
        background: #f8fafc;
    }
    body.dark-mode #reader {
        background: #0f172a;
    }
    #reader__scan_region video {
        object-fit: cover !important;
        border-radius: 12px;
    }
    .badge-soft-success { background: #dcfce7; color: #166534; }
    .badge-soft-danger { background: #fee2e2; color: #991b1b; }
    .badge-soft-info { background: #e0f2fe; color: #075985; }
    .badge-soft-warning { background: #fef3c7; color: #92400e; }

    .kpi-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        padding: 16px;
        transition: 0.3s;
    }
    body.dark-mode .kpi-card {
        background: #1e293b;
        border-color: #334155;
    }
    .kpi-card-title {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #64748b;
    }
    body.dark-mode .kpi-card-title {
        color: #94a3b8;
    }
    .kpi-card-value {
        font-size: 1.6rem;
        font-weight: 800;
        line-height: 1.2;
    }

    /* Thermal Label Printing Template (60mm x 30mm) */
    .asset-label-container {
        width: 60mm !important;
        height: 30mm !important;
        padding: 1.5mm 2mm !important;
        box-sizing: border-box !important;
        background: #ffffff !important;
        font-family: Arial, Helvetica, sans-serif !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        overflow: hidden !important;
        border: 1px solid #ddd;
    }
    .label-top-row { display: flex !important; flex-direction: row !important; height: 16mm !important; width: 100% !important; gap: 2mm !important; }
    .label-qr-box { width: 16mm !important; height: 16mm !important; flex-shrink: 0 !important; }
    .label-qr-box img { width: 100% !important; height: 100% !important; object-fit: contain !important; display: block !important; }
    .label-info-box { flex: 1 !important; display: flex !important; flex-direction: column !important; justify-content: space-between !important; overflow: hidden !important; }
    .label-asset-tag { font-size: 8pt !important; font-weight: 800 !important; max-height: 9mm !important; overflow: hidden !important; text-transform: uppercase !important; line-height: 1.1 !important; color: #000 !important; }
    .label-logo-box { height: 7mm !important; display: flex !important; align-items: flex-end !important; justify-content: flex-end !important; }
    .label-logo-box img { max-height: 6.5mm !important; max-width: 25mm !important; object-fit: contain !important; }
    .label-barcode-row { flex: 1 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding-top: 1mm !important; box-sizing: border-box !important; }
    .label-barcode-row img { max-width: 100% !important; height: 8mm !important; display: block !important; }
</style>

<div class="content-wrapper" style="margin-left:0 !important;">
    <div class="content-header border-bottom mb-4 bg-white shadow-sm">
        <div class="container-fluid px-4 py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h1 class="m-0 font-weight-bold text-dark" style="font-size:1.4rem;"><i class="fas fa-qrcode mr-2 text-success"></i><?= $isTr ? 'Mobil Hızlı Envanter Sayımı & Denetimi' : 'Mobile Inventory Audit & Scanner' ?></h1>
                    <p class="text-muted small mb-0"><?= $isTr ? 'Kameranız, El Terminaliniz veya Barkod Okuyucu cihazınızla hızlı envanter doğrulama ve sayım yapın.' : 'Perform rapid asset audit using camera stream or USB barcode scanners.' ?></p>
                </div>
                <div>
                    <button class="btn btn-outline-danger btn-sm shadow-sm font-weight-bold px-3 py-2" id="btn-download-all-labels" style="border-radius: 10px;">
                        <i class="fas fa-file-pdf mr-1"></i><?= $isTr ? 'Tüm Barkodları (PDF) İndir' : 'Download All Barcodes (PDF)' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid px-4">
            <!-- Top Audit Stats KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                    <div class="kpi-card shadow-sm border-left border-primary" style="border-left-width:4px !important;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="kpi-card-title"><?= $isTr ? 'Toplam Kayıtlı Varlık' : 'Total Assets' ?></div>
                                <div class="kpi-card-value text-dark" id="stat-total">-</div>
                            </div>
                            <i class="fas fa-boxes fa-2x text-primary opacity-25"></i>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                    <div class="kpi-card shadow-sm border-left border-success" style="border-left-width:4px !important;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="kpi-card-title"><?= $isTr ? 'Sayımı Yapılan (Onaylı)' : 'Audited Assets' ?></div>
                                <div class="kpi-card-value text-success" id="stat-audited">-</div>
                            </div>
                            <i class="fas fa-check-circle fa-2x text-success opacity-25"></i>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                    <div class="kpi-card shadow-sm border-left border-warning" style="border-left-width:4px !important;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="kpi-card-title"><?= $isTr ? 'Sayımı Bekleyen' : 'Pending Audit' ?></div>
                                <div class="kpi-card-value text-warning" id="stat-pending">-</div>
                            </div>
                            <i class="fas fa-clock fa-2x text-warning opacity-25"></i>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                    <div class="kpi-card shadow-sm border-left border-info" style="border-left-width:4px !important;">
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <div class="kpi-card-title"><?= $isTr ? 'Tamamlanma Oranı' : 'Completion Rate' ?></div>
                                <strong id="stat-percent" class="text-info font-weight-bold">0%</strong>
                            </div>
                            <div class="progress mt-2" style="height: 8px; border-radius: 4px;">
                                <div class="progress-bar bg-info" id="stat-progress-bar" style="width: 0%;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <!-- Left Column: Camera / Barcode Scanner -->
                <div class="col-lg-6 mb-4">
                    <div class="card scanner-card shadow-sm p-4 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="font-weight-bold text-dark mb-0"><i class="fas fa-camera mr-2 text-success"></i><?= $isTr ? 'Kamera & Barkod Okuyucu' : 'Camera & Barcode Scanner' ?></h5>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge badge-soft-success px-3 py-1 font-weight-600 mr-2" id="scanner-status"><?= $isTr ? 'Hazır' : 'Ready' ?></span>
                                <button class="btn btn-sm btn-outline-secondary" onclick="initCamera()" title="<?= $isTr ? 'Kamerayı Başlat / Yenile' : 'Restart Camera' ?>"><i class="fas fa-sync-alt"></i></button>
                            </div>
                        </div>
                        
                        <!-- Reader Video Viewport -->
                        <div id="reader" style="min-height: 200px;"></div>

                        <!-- Rapid Mode Switch & Manual Input -->
                        <div class="mt-3">
                            <div class="d-flex align-items-center justify-content-between p-3 mb-3 bg-light rounded border">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-bolt text-warning fa-lg mr-2"></i>
                                    <span class="font-weight-bold text-dark small mb-0"><?= $isTr ? 'Seri Sayım Modu (Barkod Okutulunca Otomatik Onayla)' : 'Rapid Scan Mode (Auto-Confirm on Scan)' ?></span>
                                </div>
                                <div class="custom-control custom-switch" style="padding-left: 2.5rem;">
                                    <input type="checkbox" class="custom-control-input" id="rapid-scan-mode">
                                    <label class="custom-control-label cursor-pointer" for="rapid-scan-mode"></label>
                                </div>
                            </div>

                            <label class="small font-weight-bold text-muted mb-1"><?= $isTr ? 'El Terminali / Manuel Arama (Barkod Kodunu Yazıp Enter yapın)' : 'Manual Input / USB Scanner' ?></label>
                            <div class="input-group">
                                <input type="text" id="manual-tag-input" class="form-control form-control-lg border-right-0" placeholder="<?= $isTr ? 'Etiket / Barkod Kodu Girin (Örn: PC05)...' : 'Enter Tag / Barcode (e.g. PC05)...' ?>" style="border-radius: 10px 0 0 10px; font-size: 14px;">
                                <div class="input-group-append">
                                    <button class="btn btn-success px-4" id="manual-btn" style="border-radius: 0 10px 10px 0;"><i class="fas fa-search mr-1"></i><?= $isTr ? 'Bul' : 'Search' ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Audit Results & Approval Card -->
                <div class="col-lg-6 mb-4">
                    <div class="card scanner-card shadow-sm p-4 h-100 d-flex flex-column justify-content-between">
                        <div class="w-100">
                            <h5 class="font-weight-bold text-dark border-bottom pb-3 mb-4"><i class="fas fa-file-invoice mr-2 text-primary"></i><?= $isTr ? 'Sayım & Varlık Detayı' : 'Audit & Asset Details' ?></h5>
                            
                            <!-- Default State Placeholder -->
                            <div id="result-placeholder" class="text-center py-5 text-muted">
                                <i class="fas fa-barcode fa-4x mb-3 text-light"></i>
                                <p class="mb-0 font-weight-600"><?= $isTr ? 'Kamera ile etiket okutun veya USB Barkod okuyucu / klavye ile koda basın.' : 'Scan a QR/Barcode tag or input asset code manually.' ?></p>
                                <small class="text-muted"><?= $isTr ? 'Örnek Etiket: PC05, AST-001 veya Seri No' : 'Example: PC05, AST-001 or Serial No' ?></small>
                            </div>

                            <!-- Found Asset Details -->
                            <div id="result-details" class="d-none">
                                <div class="text-center mb-4">
                                    <div id="asset-image-container" class="mb-3 text-center">
                                        <img id="asset-img-preview" src="" alt="Varlık Görseli" class="shadow-sm rounded border d-none" style="max-height: 140px; max-width: 100%; object-fit: contain; padding: 4px; background: #ffffff;">
                                        <div id="asset-icon-fallback" class="d-none">
                                            <i class="fas fa-desktop fa-3x text-primary mb-2"></i>
                                        </div>
                                    </div>
                                    <h4 id="asset-name-title" class="font-weight-bold mb-1 text-dark"></h4>
                                    <span id="asset-tag-badge" class="badge badge-light border font-weight-bold px-3 py-1" style="font-size:13px;"></span>
                                </div>

                                <div class="list-group list-group-flush" style="font-size:14px;">
                                    <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 bg-transparent border-bottom">
                                        <span class="text-muted"><i class="fas fa-fingerprint mr-2"></i><?= $isTr ? 'Seri Numarası' : 'Serial Number' ?></span>
                                        <strong id="asset-serial-val" class="text-dark"></strong>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 bg-transparent border-bottom">
                                        <span class="text-muted"><i class="fas fa-user-tag mr-2"></i><?= $isTr ? 'Zimmetli Personel' : 'Assigned User' ?></span>
                                        <strong id="asset-user-val" class="text-dark"></strong>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 bg-transparent">
                                        <span class="text-muted"><i class="fas fa-info-circle mr-2"></i><?= $isTr ? 'Durum' : 'Status' ?></span>
                                        <span id="asset-status-val" class="badge px-3 py-1 text-white"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Confirm Audit Action Button -->
                        <div id="action-container" class="d-none mt-4 w-100">
                            <input type="hidden" id="current-asset-id">
                            <button class="btn btn-success btn-lg btn-block shadow-sm" id="btn-submit-audit" style="border-radius:12px;">
                                <i class="fas fa-check-circle mr-2"></i><?= $isTr ? 'Varlığı Sayıldı Olarak İşaretle' : 'Mark Asset as Audited' ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Audits Table -->
            <div class="row mt-2">
                <div class="col-12 mb-4">
                    <div class="card scanner-card shadow-sm p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
                            <h5 class="font-weight-bold text-dark mb-0"><i class="fas fa-history mr-2 text-info"></i><?= $isTr ? 'Son Sayılan Varlıklar (Geçmiş Loglar)' : 'Recently Audited Assets' ?></h5>
                            <button class="btn btn-sm btn-light border" onclick="loadRecentAudits()"><i class="fas fa-sync-alt mr-1"></i><?= $isTr ? 'Yenile' : 'Refresh' ?></button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" style="font-size: 14px;">
                                <thead>
                                    <tr>
                                        <th><?= $isTr ? 'Sayım Tarihi' : 'Audit Date' ?></th>
                                        <th><?= $isTr ? 'Varlık Etiketi' : 'Asset Tag' ?></th>
                                        <th><?= $isTr ? 'Cihaz Adı' : 'Asset Name' ?></th>
                                        <th><?= $isTr ? 'Sayan Yetkili' : 'Audited By' ?></th>
                                        <th class="text-center"><?= $isTr ? 'Detay' : 'View' ?></th>
                                    </tr>
                                </thead>
                                <tbody id="recent-audits-tbody">
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin mr-1"></i><?= $isTr ? 'Yükleniyor...' : 'Loading...' ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
    let html5QrcodeScanner = null;
    let isScanning = true;

    function playBeep() {
        try {
            const context = new (window.AudioContext || window.webkitAudioContext)();
            const osc = context.createOscillator();
            const gain = context.createGain();
            osc.type = "sine";
            osc.frequency.setValueAtTime(880, context.currentTime);
            gain.gain.setValueAtTime(0.15, context.currentTime);
            osc.connect(gain);
            gain.connect(context.destination);
            osc.start();
            setTimeout(() => { osc.stop(); context.close(); }, 150);
        } catch (e) {}
    }

    function loadAuditStats() {
        const formData = new FormData();
        formData.append('action', 'get_audit_stats');
        formData.append('ajax_action', '1');

        fetch('sayim', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                document.getElementById('stat-total').innerText = res.total;
                document.getElementById('stat-audited').innerText = res.audited;
                document.getElementById('stat-pending').innerText = res.pending;
                document.getElementById('stat-percent').innerText = res.percent + '%';
                document.getElementById('stat-progress-bar').style.width = res.percent + '%';
            }
        }).catch(err => console.error(err));
    }

    function showNoCameraUI() {
        const reader = document.getElementById('reader');
        reader.innerHTML = `
            <div class="text-center py-4 px-3">
                <i class="fas fa-barcode fa-3x text-primary mb-3"></i>
                <h6 class="font-weight-bold text-dark mb-2"><?= $isTr ? 'El Terminali / Barkod Okuyucu Modu Aktif' : 'Barcode Reader & USB Gun Mode Active' ?></h6>
                <p class="text-muted small mb-3"><?= $isTr ? 'Kamera yayını kapalı veya tarayıcı güvenlik politikası (HTTP) gereği pasif. USB Barkod Okuyucu tabancanızı kullanarak etiketi okutabilir veya aşağıdaki kutuya yazabilirsiniz.' : 'Camera streaming disabled or inactive due to HTTP policy. Connect a USB barcode gun scanner or enter code below.' ?></p>
                <button class="btn btn-sm btn-outline-primary" onclick="initCamera()"><i class="fas fa-video mr-1"></i><?= $isTr ? 'Kamerayı Yeniden Dene' : 'Retry Camera' ?></button>
            </div>
        `;
        document.getElementById('scanner-status').className = "badge badge-soft-info px-3 py-1 font-weight-600";
        document.getElementById('scanner-status').innerText = "<?= $isTr ? 'El Terminali Modu' : 'USB Reader Mode' ?>";
        document.getElementById('manual-tag-input').focus();
    }

    function initCamera() {
        const config = { fps: 15, qrbox: { width: 220, height: 220 }, aspectRatio: 1.0 };
        if (html5QrcodeScanner) {
            try { html5QrcodeScanner.clear(); } catch(e){}
        }

        try {
            html5QrcodeScanner = new Html5Qrcode("reader");
            html5QrcodeScanner.start(
                { facingMode: "environment" }, 
                config, 
                onScanSuccess, 
                onScanFailure
            ).then(() => {
                document.getElementById('scanner-status').className = "badge badge-soft-success px-3 py-1 font-weight-600";
                document.getElementById('scanner-status').innerText = "<?= $isTr ? 'Kamera Aktif' : 'Camera Active' ?>";
            }).catch(() => {
                showNoCameraUI();
            });
        } catch(e) {
            showNoCameraUI();
        }
    }

    function onScanSuccess(decodedText) {
        if (!isScanning) return;
        isScanning = false;
        playBeep();
        lookupAsset(decodedText);
        setTimeout(() => { isScanning = true; }, 3000);
    }

    function onScanFailure(error) {}

    function lookupAsset(tag) {
        document.getElementById('scanner-status').className = "badge badge-soft-info px-3 py-1 font-weight-600";
        document.getElementById('scanner-status').innerText = "<?= $isTr ? 'Sorgulanıyor...' : 'Searching...' ?>";

        const formData = new FormData();
        formData.append('action', 'check_tag');
        formData.append('tag', tag);
        formData.append('ajax_action', '1');

        fetch('sayim', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            document.getElementById('scanner-status').className = "badge badge-soft-success px-3 py-1 font-weight-600";
            document.getElementById('scanner-status').innerText = "<?= $isTr ? 'Hazır' : 'Ready' ?>";

            if (res.success) {
                showAssetDetails(res.asset);
                const isRapid = document.getElementById('rapid-scan-mode').checked;
                if (isRapid) {
                    confirmAudit(res.asset.id);
                }
            } else {
                Swal.fire({
                    icon: 'error',
                    title: '<?= $isTr ? "Bulunamadı" : "Not Found" ?>',
                    text: res.error,
                    confirmButtonColor: '#3b82f6'
                });
            }
        })
        .catch(err => {
            console.error(err);
            document.getElementById('scanner-status').className = "badge badge-soft-danger px-3 py-1 font-weight-600";
            document.getElementById('scanner-status').innerText = "Hata / Error";
        });
    }

    function showAssetDetails(asset) {
        document.getElementById('result-placeholder').classList.add('d-none');
        document.getElementById('result-details').classList.remove('d-none');
        document.getElementById('action-container').classList.remove('d-none');

        document.getElementById('current-asset-id').value = asset.id;
        document.getElementById('asset-name-title').innerText = asset.name;
        document.getElementById('asset-tag-badge').innerText = "#" + asset.asset_tag;
        document.getElementById('asset-serial-val').innerText = asset.serial_no || '-';
        document.getElementById('asset-user-val').innerText = asset.assigned_user || '<?= $isTr ? "Boşta" : "Not Assigned" ?>';

        // Asset Photo Handling (Direct asset image or model image fallback)
        const imgEl = document.getElementById('asset-img-preview');
        const fallbackEl = document.getElementById('asset-icon-fallback');
        if (asset.image) {
            let imgUrl = asset.image;
            if (!imgUrl.startsWith('http') && !imgUrl.startsWith('/') && !imgUrl.startsWith('uploads/')) {
                if (imgUrl.startsWith('models-')) {
                    imgUrl = 'uploads/models/' + imgUrl;
                } else if (imgUrl.startsWith('assets-')) {
                    imgUrl = 'uploads/assets/' + imgUrl;
                } else {
                    imgUrl = 'uploads/assets/' + imgUrl;
                }
            }
            imgEl.src = imgUrl;
            imgEl.onerror = function() {
                imgEl.classList.add('d-none');
                fallbackEl.classList.remove('d-none');
            };
            imgEl.classList.remove('d-none');
            fallbackEl.classList.add('d-none');
        } else {
            imgEl.classList.add('d-none');
            fallbackEl.classList.remove('d-none');
        }

        const statusBadge = document.getElementById('asset-status-val');
        statusBadge.innerText = asset.status_name || '-';
        statusBadge.style.backgroundColor = asset.status_color || '#64748b';
    }

    function confirmAudit(assetId) {
        if (!assetId) return;

        const btn = document.getElementById('btn-submit-audit');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i><?= $isTr ? "Kaydediliyor..." : "Saving..." ?>';

        const formData = new FormData();
        formData.append('action', 'confirm_audit');
        formData.append('asset_id', assetId);
        formData.append('ajax_action', '1');

        fetch('sayim', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle mr-2"></i><?= $isTr ? "Varlığı Sayıldı Olarak İşaretle" : "Mark Asset as Audited" ?>';

            if (res.success) {
                playBeep();
                const isRapid = document.getElementById('rapid-scan-mode').checked;
                if (!isRapid) {
                    Swal.fire({
                        icon: 'success',
                        title: '<?= $isTr ? "Sayım Onaylandı" : "Audit Confirmed" ?>',
                        text: '<?= $isTr ? "Varlık sayımı zaman tüneline başarıyla kaydedildi." : "Audit saved to timeline." ?>',
                        timer: 1500,
                        showConfirmButton: false
                    });
                }
                document.getElementById('result-placeholder').classList.remove('d-none');
                document.getElementById('result-details').classList.add('d-none');
                document.getElementById('action-container').classList.add('d-none');
                document.getElementById('manual-tag-input').value = '';
                document.getElementById('manual-tag-input').focus();
                loadRecentAudits();
                loadAuditStats();
            } else {
                Swal.fire({ icon: 'error', title: 'Hata', text: res.error });
            }
        });
    }

    document.getElementById('manual-btn').addEventListener('click', function() {
        const val = document.getElementById('manual-tag-input').value.trim();
        if (val) lookupAsset(val);
    });

    document.getElementById('manual-tag-input').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            const val = this.value.trim();
            if (val) lookupAsset(val);
        }
    });

    document.getElementById('btn-submit-audit').addEventListener('click', function() {
        const assetId = document.getElementById('current-asset-id').value;
        confirmAudit(assetId);
    });

    const companyLogoUrl = <?= json_encode(s('logo_path') ? ($base_url . 'public/' . s('logo_path')) : '') ?>;
    const companyNameText = <?= json_encode(s('company_name')) ?>;
    const baseUrl = <?= json_encode(rtrim($base_url, '/')) ?>;
    const lang = <?= json_encode($_SESSION['lang'] ?? 'tr') ?>;

    function loadLabelImagesAndRender(asset) {
        return new Promise((resolve, reject) => {
            const tempContainer = document.createElement('div');
            tempContainer.className = 'asset-label-container';
            tempContainer.style.position = 'fixed';
            tempContainer.style.left = '-9999px';
            tempContainer.style.top = '-9999px';
            tempContainer.style.background = '#ffffff';

            const displayName = asset.name || 'Item';
            const displayTag = asset.asset_tag || '';
            const fullLabelHeader = (displayTag && displayTag !== displayName) ? `${displayTag}: ${displayName}` : displayName;
            const barcodeData = displayTag || asset.serial_no || asset.id;

            const qrUrl = `${baseUrl}/cihaz/izle/${encodeURIComponent(asset.public_token)}?lang=${lang}`;
            const qrApiUrl = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(qrUrl)}`;
            const realBarcodeUrl = `https://bwipjs-api.metafloor.com/?bcid=code128&text=${encodeURIComponent(barcodeData)}&scale=2&rotate=N&includetext=true`;

            let logoHtml = companyLogoUrl ? `<img src="${companyLogoUrl}" alt="Logo" crossorigin="anonymous">` : `<span class="font-weight-bold text-primary">${companyNameText}</span>`;

            tempContainer.innerHTML = `
                <div class="label-top-row">
                    <div class="label-qr-box"><img id="temp-qr-${asset.id}" src="${qrApiUrl}" alt="QR" crossorigin="anonymous"></div>
                    <div class="label-info-box">
                        <div class="label-asset-tag">${fullLabelHeader}</div>
                        <div class="label-logo-box">${logoHtml}</div>
                    </div>
                </div>
                <div class="label-barcode-row"><img id="temp-barcode-${asset.id}" src="${realBarcodeUrl}" alt="Barcode" crossorigin="anonymous"></div>
            `;

            document.body.appendChild(tempContainer);

            const qrImg = tempContainer.querySelector(`#temp-qr-${asset.id}`);
            const barcodeImg = tempContainer.querySelector(`#temp-barcode-${asset.id}`);
            const logoImg = tempContainer.querySelector('.label-logo-box img');

            const promises = [];
            const waitForImg = (img) => {
                if (!img || img.complete) return Promise.resolve();
                return new Promise((res) => { img.onload = res; img.onerror = res; });
            };

            promises.push(waitForImg(qrImg));
            promises.push(waitForImg(barcodeImg));
            if (logoImg) promises.push(waitForImg(logoImg));

            Promise.all(promises).then(() => {
                setTimeout(() => {
                    html2canvas(tempContainer, { useCORS: true, scale: 4, backgroundColor: '#ffffff' })
                        .then(canvas => { document.body.removeChild(tempContainer); resolve(canvas.toDataURL('image/png')); })
                        .catch(err => { document.body.removeChild(tempContainer); reject(err); });
                }, 100);
            });
        });
    }

    document.getElementById('btn-download-all-labels').addEventListener('click', function() {
        Swal.fire({
            title: '<?= $isTr ? "Etiketler Hazırlanıyor" : "Preparing Labels" ?>',
            html: '<?= $isTr ? "Cihaz listesi alınıyor..." : "Fetching asset list..." ?>',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        const formData = new FormData();
        formData.append('action', 'get_all_assets');
        formData.append('ajax_action', '1');

        fetch('sayim', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(async res => {
            if (!res.success) throw new Error(res.error || 'Failed to fetch assets');
            const assets = res.assets;
            if (assets.length === 0) {
                Swal.fire({ icon: 'info', title: 'Bulunamadı', text: 'Sistemde varlık bulunmuyor.' });
                return;
            }

            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: [60, 30] });

            for (let i = 0; i < assets.length; i++) {
                Swal.update({ html: `Etiket oluşturuluyor: ${i + 1} / ${assets.length}<br><b>${assets[i].asset_tag || ''} - ${assets[i].name}</b>` });
                try {
                    const imgData = await loadLabelImagesAndRender(assets[i]);
                    if (i > 0) pdf.addPage([60, 30], 'landscape');
                    pdf.addImage(imgData, 'PNG', 0, 0, 60, 30);
                } catch (error) { console.error('Error rendering label', error); }
            }

            pdf.save('tum-cihazlar-etiket.pdf');
            Swal.fire({ icon: 'success', title: 'Başarılı', text: 'Tüm etiketler PDF olarak indirildi.' });
        }).catch(err => {
            Swal.fire({ icon: 'error', title: 'Hata', text: err.message });
        });
    });

    function loadRecentAudits() {
        const formData = new FormData();
        formData.append('action', 'get_recent_audits');
        formData.append('ajax_action', '1');
        
        fetch('sayim', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('recent-audits-tbody');
            if (!tbody) return;
            tbody.innerHTML = '';
            
            if (!res.success || !res.audits || res.audits.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">${lang === 'tr' ? 'Henüz sayılan envanter yok.' : 'No audited assets yet.'}</td></tr>`;
                return;
            }
            
            res.audits.forEach(a => {
                const tr = document.createElement('tr');
                const dateStr = new Date(a.created_at).toLocaleString(lang === 'tr' ? 'tr-TR' : 'en-US');
                tr.innerHTML = `
                    <td>${dateStr}</td>
                    <td><span class="badge badge-light border font-weight-bold px-2 py-1">${a.asset_tag}</span></td>
                    <td><strong>${a.asset_name}</strong></td>
                    <td>${a.auditor_name || '-'}</td>
                    <td class="text-center"><a href="varlik-detay/${a.asset_id}" class="btn btn-sm btn-link text-primary p-0" target="_blank"><i class="fas fa-eye"></i></a></td>
                `;
                tbody.appendChild(tr);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        initCamera();
        loadRecentAudits();
        loadAuditStats();
    });
</script>