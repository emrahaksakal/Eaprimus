<!-- Return (İade) Modal -->
<div class="modal fade" id="returnModal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <div class="modal-header bg-light border-0 py-3">
                <div class="d-flex align-items-center">
                    <div class="bg-warning-soft p-2 rounded-lg mr-3" style="background: rgba(245, 158, 11, 0.1); border-radius: 8px;">
                        <i class="fas fa-undo-alt text-warning" style="color: #f59e0b;"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold mb-0 text-dark"><?= $isTr ? 'Zimmet İade Al' : 'Return Asset' ?></h5>
                        <p class="small text-muted mb-0"><?= $isTr ? 'Lütfen iade bilgilerini girin.' : 'Please enter return details.' ?></p>
                    </div>
                </div>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="return_asset_id">
                <input type="hidden" id="return_asset_type">
                
                <div class="form-group mb-3">
                    <label class="small font-weight-bold text-muted text-uppercase mb-2"><?= $isTr ? 'İade Sebebi' : 'Return Reason' ?></label>
                    <input type="text" id="return_reason" class="form-control form-control-solid" placeholder="<?= $isTr ? 'Örn: Geri Alma, İşten Ayrılma vb.' : 'E.g: Check In, Resignation, etc.' ?>">
                </div>
                
                <div class="form-group mb-3">
                    <label class="small font-weight-bold text-muted text-uppercase mb-2"><?= $isTr ? 'Vekaleten Teslim Eden (İsteğe Bağlı)' : 'Returned By Proxy (Optional)' ?></label>
                    <input type="text" id="proxy_name" class="form-control form-control-solid" placeholder="<?= $isTr ? 'Örn: Ahmet Yılmaz (Vekaleten)' : 'E.g: John Doe (Proxy)' ?>">
                </div>

                <div class="form-group mb-3">
                    <label class="small font-weight-bold text-muted text-uppercase mb-2 d-block"><?= $isTr ? 'Teslim Durumu (Hasar)' : 'Return Status' ?></label>
                    <div class="custom-control custom-radio custom-control-inline">
                        <input type="radio" id="return_status_ok" name="return_status" class="custom-control-input" value="hasarsiz" checked>
                        <label class="custom-control-label" for="return_status_ok"><?= $isTr ? 'Hasarsız ve Tam Teslim Edilmiştir' : 'Returned undamaged and complete' ?></label>
                    </div>
                    <div class="custom-control custom-radio custom-control-inline">
                        <input type="radio" id="return_status_bad" name="return_status" class="custom-control-input" value="hasarli">
                        <label class="custom-control-label" for="return_status_bad"><?= $isTr ? 'Hasarlı yada Eksik Teslim Edilmiştir' : 'Returned damaged or missing' ?></label>
                    </div>
                </div>

                <div class="form-group mb-3 d-none" id="damage_note_container">
                    <label class="small font-weight-bold text-muted text-uppercase mb-2"><?= $isTr ? 'Hasar / Eksik Açıklaması' : 'Damage / Missing Details' ?></label>
                    <textarea id="damage_note" class="form-control form-control-solid" rows="3" placeholder="<?= $isTr ? 'Lütfen hasar veya eksik ayrıntılarını yazın.' : 'Please describe the damage or missing parts.' ?>"></textarea>
                </div>

                <div class="form-group mb-3 d-none" id="damage_status_action_container">
                    <label class="small font-weight-bold text-muted text-uppercase mb-2 d-block"><?= $isTr ? 'Hasar Sonrası Varlık Durumu' : 'Asset Status After Damage' ?></label>
                    <div class="custom-control custom-radio custom-control-inline">
                        <input type="radio" id="damage_action_faulty" name="damage_status_action" class="custom-control-input" value="hasarli" checked>
                        <label class="custom-control-label font-weight-bold text-danger" for="damage_action_faulty"><?= $isTr ? 'Arızalı Olarak İşaretle (Kullanılamaz)' : 'Mark as Faulty (Undeployable)' ?></label>
                    </div>
                    <div class="custom-control custom-radio custom-control-inline">
                        <input type="radio" id="damage_action_ready" name="damage_status_action" class="custom-control-input" value="hasarli_kullanilabilir">
                        <label class="custom-control-label font-weight-bold text-success" for="damage_action_ready"><?= $isTr ? 'Hazır Olarak İşaretle (Hasarlı fakat Kullanılabilir)' : 'Mark as Ready (Damaged but Usable)' ?></label>
                    </div>
                </div>

                <div class="form-group mb-4" id="signature_method_group">
                    <label class="small font-weight-bold text-muted text-uppercase mb-2 d-block"><?= $isTr ? 'İmza Yöntemi' : 'Signature Method' ?></label>
                    
                    <!-- Original signature warning banner -->
                    <div id="original_signature_warning" class="alert alert-warning border-0 d-none shadow-sm mb-3" style="border-radius:12px; background: rgba(245, 158, 11, 0.08); color: #d97706; font-size:12px; font-weight:600; padding:10px 15px; border: 1px solid rgba(245, 158, 11, 0.25) !important; transition: all 0.3s;">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <span><?= $isTr ? 'Bu varlık başlangıçta Kağıt / Islak İmza ile zimmetlenmiştir. Kağıt / Islak İmzalı İade yöntemi önerilir!' : 'This asset was originally checked out via Paper / Wet Signature. Paper / Wet Signed Return is highly recommended!' ?></span>
                    </div>

                    <div class="custom-control custom-radio mb-2">
                        <input type="radio" id="sig_method_twostage" name="signature_type" class="custom-control-input" value="twostage" checked>
                        <label class="custom-control-label font-weight-bold text-primary" for="sig_method_twostage"><?= $isTr ? '2 Aşamalı Dijital Onay Başlat (Önce Personel, Sonra Admin)' : 'Start 2-Stage Digital Approval (Personnel first)' ?></label>
                        <small class="d-block text-muted ml-4"><?= $isTr ? 'Personelin paneline düşer. İade işlemi imzalar tamamlanınca gerçekleşir.' : 'Goes to personnel dashboard. Return completes after both sign.' ?></small>
                    </div>
                    <div class="custom-control custom-radio mb-2">
                        <input type="radio" id="sig_method_direct" name="signature_type" class="custom-control-input" value="direct">
                        <label class="custom-control-label font-weight-bold text-success" for="sig_method_direct"><?= $isTr ? 'Kağıt / Islak İmzalı İade (İmzasız / Tutanaksız)' : 'Paper / Wet Signed Return (No Digital Signature)' ?></label>
                        <small class="d-block text-muted ml-4"><?= $isTr ? 'Kağıt tutanakla teslim alınan iadeler veya imzasız süreçler için doğrudan iade al.' : 'Direct return for paper protocols or signature-free workflows.' ?></small>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-0 p-4 bg-light">
                <div class="w-100 d-flex justify-content-between align-items-center">
                    <button type="button" class="btn btn-link text-muted font-weight-bold p-0 text-decoration-none" data-dismiss="modal"><?= __("cancel") ?></button>
                    <button type="button" id="btn_submit_return" class="btn btn-warning px-4 py-2 shadow-sm" style="border-radius:12px; font-weight: 700; color: #000; transition: all 0.3s;" onclick="submitReturn()">
                        <i class="fas fa-undo-alt mr-2"></i><?= $isTr ? 'İadeyi Tamamla' : 'Complete Return' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes pulse-warning {
    0% { transform: scale(1); box-shadow: 0 4px 8px rgba(245, 158, 11, 0.1); }
    50% { transform: scale(1.01); box-shadow: 0 6px 12px rgba(245, 158, 11, 0.2); background: rgba(245, 158, 11, 0.12); }
    100% { transform: scale(1); box-shadow: 0 4px 8px rgba(245, 158, 11, 0.1); }
}
</style>

<script>
var isTr = <?= isset($isTr) && $isTr ? 'true' : 'false' ?>;

function openReturnModal(id, type, targetType = 'user', assignedName = '', checkoutId = null) {
    $('#return_asset_id').val(id);
    $('#return_asset_type').val(type);
    $('#proxy_name').val('');
    $('#return_status_ok').prop('checked', true);
    $('#damage_note_container').addClass('d-none');
    $('#damage_note').val('');
    $('#damage_status_action_container').addClass('d-none');
    $('#damage_action_faulty').prop('checked', true);
    $('#return_reason').val(isTr ? 'Geri Alma' : 'Check In');
    
    // Store checkout_id and from_asset_id
    $('#return_asset_id').data('checkout_id', checkoutId || '');
    $('#return_asset_id').data('from_asset_id', (targetType === 'asset') ? assignedName : '');
    
    // Hide warning by default
    $('#original_signature_warning').addClass('d-none');
    
    // Auto-configure signature based on checkout target and item type
    const isTechnicalItem = (type === 'accessories' || type === 'licenses' || type === 'components' || type === 'consumables');
    if (targetType === 'asset' || isTechnicalItem) {
        $('#signature_method_group').hide();
        $('#sig_method_direct').prop('checked', true);
        $('#returnModal').modal('show');
    } else {
        // Call AJAX to check original checkout signature type
        $.get('varliklar', { get_active_checkout_signature: id, assign_view: type, checkout_id: checkoutId }, function(res) {
            $('#signature_method_group').show();
            
            if (typeof res === 'string') {
                try {
                    res = JSON.parse(res.substring(res.indexOf('{')));
                } catch(e) {}
            }
            
            if (res && res.is_paper) {
                $('#sig_method_direct').prop('checked', true);
                $('#original_signature_warning').removeClass('d-none');
            } else {
                $('#sig_method_twostage').prop('checked', true);
            }
            $('#returnModal').modal('show');
        }).fail(function() {
            // Fallback
            $('#signature_method_group').show();
            $('#sig_method_twostage').prop('checked', true);
            $('#returnModal').modal('show');
        });
    }
}

// Add a change listener to alert the admin if they try to change to digital signature
$(document).ready(function() {
    $('input[name="return_status"]').on('change', function() {
        if ($(this).val() === 'hasarli') {
            $('#damage_note_container').removeClass('d-none');
            $('#damage_status_action_container').removeClass('d-none');
        } else {
            $('#damage_note_container').addClass('d-none');
            $('#damage_note').val('');
            $('#damage_status_action_container').addClass('d-none');
            $('#damage_action_faulty').prop('checked', true);
        }
    });

    $('input[name="signature_type"]').on('change', function() {
        const selected = $(this).val();
        if (selected === 'twostage' && !$('#original_signature_warning').hasClass('d-none')) {
            // Pulse the warning to draw attention
            $('#original_signature_warning').css('animation', 'pulse-warning 1s ease-in-out infinite');
            
            // Show a sweetalert confirmation
            Swal.fire({
                icon: 'warning',
                title: isTr ? 'Dikkat!' : 'Attention!',
                text: isTr ? 'Bu cihaz kağıt imzalı (ıslak imzalı) olarak zimmetlenmiş. Yine de 2 aşamalı dijital onay başlatmak istediğinizden emin misiniz?' 
                           : 'This asset was originally wet-signed. Are you sure you want to start a 2-stage digital approval?',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#f59e0b',
                confirmButtonText: isTr ? 'Evet, Dijital Onay Başlat' : 'Yes, start digital approval',
                cancelButtonText: isTr ? 'Hayır, Kağıt İade Seç' : 'No, select paper return',
                customClass: { popup: 'modern-swal-popup' }
            }).then((result) => {
                if (!result.isConfirmed) {
                    $('#sig_method_direct').prop('checked', true);
                }
                $('#original_signature_warning').css('animation', 'none');
            });
        }
    });
});

function submitReturn() {
    const assetId = $('#return_asset_id').val();
    const view = $('#return_asset_type').val();
    const returnReason = $('#return_reason').val();
    const proxyName = $('#proxy_name').val();
    let returnStatus = $('input[name="return_status"]:checked').val();
    if (returnStatus === 'hasarli') {
        returnStatus = $('input[name="damage_status_action"]:checked').val();
    }
    const damageNote = $('#damage_note').val();
    const sigType = $('input[name="signature_type"]:checked').val();
    const checkoutId = $('#return_asset_id').data('checkout_id') || '';
    const fromAssetId = $('#return_asset_id').data('from_asset_id') || '';
    
    let isBypass = 0;
    
    if (sigType === 'direct') {
        isBypass = 1; // Backend will execute immediately without PDF if no signatures are sent
    }
    
    let actualReason = returnReason;
    if (returnReason.trim() === '') {
        if (sigType === 'direct') {
            actualReason = isTr ? 'Direkt İade' : 'Direct Return';
        } else {
            Swal.fire({
                icon: 'warning',
                title: isTr ? 'Eksik Bilgi' : 'Missing Info',
                text: isTr ? 'Lütfen iade sebebi giriniz.' : 'Please enter a return reason.'
            });
            return;
        }
    }

    Swal.fire({
        title: isTr ? 'İşlem Yapılıyor...' : 'Processing...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    const formData = new FormData();
    formData.append('csrf_token', typeof inventoryCsrfToken !== 'undefined' ? inventoryCsrfToken : '<?= function_exists("csrf_token") ? csrf_token() : "" ?>');
    formData.append('action', 'checkin');
    formData.append('view', view);
    formData.append('id', assetId);
    formData.append('asset_id', assetId);
    formData.append('is_ajax', '1');
    formData.append('return_reason', actualReason);
    formData.append('proxy_name', proxyName);
    formData.append('return_status', returnStatus);
    formData.append('damage_note', damageNote);
    formData.append('signature_type', sigType);
    formData.append('personnel_signature', '');
    formData.append('admin_signature', '');
    formData.append('bypass', isBypass);
    
    if (checkoutId) formData.append('checkout_id', checkoutId);
    if (fromAssetId) formData.append('from_asset_id', fromAssetId);

    $.ajax({
        url: 'varliklar',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            $('#returnModal').modal('hide');
            if (response.success) {
                if (response.download_url) {
                    Swal.fire({
                        icon: 'success',
                        title: isTr ? 'İade Alındı!' : 'Returned!',
                        html: (isTr
                            ? '<p class="mb-3">İade tutanağı hazırlandı. İstediğiniz formatı seçerek indirin veya yazdırın.</p>'
                            : '<p class="mb-3">Return form is ready. Please choose the format to download or print.</p>')
                            + '<div class="d-flex justify-content-center flex-wrap" style="gap:10px;">'
                            + '<button id="retSwalPdfBtn" class="swal2-confirm swal2-styled" style="background:#3085d6;"><i class="fas fa-file-pdf mr-2"></i>' + (isTr ? 'PDF Yazdır' : 'Print PDF') + '</button>'
                            + (response.excel_url ? '<button id="retSwalXlsBtn" class="swal2-confirm swal2-styled" style="background:#1d6f42;"><i class="fas fa-file-excel mr-2"></i>' + (isTr ? 'Excel İndir' : 'Download Excel') + '</button>' : '')
                            + '<button id="retSwalCloseBtn" class="swal2-cancel swal2-styled" style="background:#aaa;">' + (isTr ? 'Kapat' : 'Close') + '</button>'
                            + '</div>',
                        showConfirmButton: false,
                        showCancelButton: false,
                        allowOutsideClick: false,
                        didOpen: () => {
                            document.getElementById('retSwalPdfBtn').addEventListener('click', () => {
                                window.open(response.download_url, '_blank');
                            });
                            if (response.excel_url && document.getElementById('retSwalXlsBtn')) {
                                document.getElementById('retSwalXlsBtn').addEventListener('click', () => {
                                    window.open(response.excel_url, '_blank');
                                });
                            }
                            document.getElementById('retSwalCloseBtn').addEventListener('click', () => {
                                Swal.close();
                                window.location.reload();
                            });
                        }
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    if (sigType === 'twostage') {
                        Swal.fire({
                            icon: 'success',
                            title: isTr ? 'İade Talebi Oluşturuldu!' : 'Return Request Created!',
                            text: response.message || (isTr ? 'İade talebi oluşturuldu. Dijital onay sayfasına yönlendiriliyorsunuz.' : 'Return request created. Redirecting to signature approvals.'),
                            timer: 2500,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = 'varliklar?view=signatures';
                        });
                    } else {
                        Swal.fire({
                            icon: 'success',
                            title: isTr ? 'İade Alındı!' : 'Returned!',
                            text: response.message || (isTr ? 'İade işlemi başarıyla tamamlandı.' : 'Successfully completed.'),
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.reload();
                        });
                    }
                }
            } else {
                Swal.fire({
                    icon: 'error',
                    title: isTr ? 'Hata!' : 'Error!',
                    text: response.message || (isTr ? 'İade işlemi başarısız oldu.' : 'Return failed.')
                });
            }
        },
        error: function() {
            Swal.fire({
                icon: 'error',
                title: isTr ? 'Hata!' : 'Error!',
                text: isTr ? 'İnternet bağlantısı veya sunucu hatası.' : 'Network or server error.'
            });
        }
    });
}

window.directCheckin = function(checkoutId, assetId, view, targetName = '') {
    Swal.fire({
        title: isTr ? 'İade Alınıyor...' : 'Processing Return...',
        text: isTr ? 'Lütfen bekleyin.' : 'Please wait.',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    const formData = new FormData();
    formData.append('csrf_token', typeof inventoryCsrfToken !== 'undefined' ? inventoryCsrfToken : '<?= function_exists("csrf_token") ? csrf_token() : "" ?>');
    formData.append('action', 'checkin');
    formData.append('view', view);
    formData.append('id', assetId);
    formData.append('asset_id', assetId);
    formData.append('is_ajax', '1');
    formData.append('return_reason', isTr ? 'Cihazdan Geri Alma' : 'Device Check In');
    formData.append('proxy_name', '');
    formData.append('return_status', 'hasarsiz');
    formData.append('signature_type', 'direct');
    formData.append('personnel_signature', '');
    formData.append('admin_signature', '');
    formData.append('bypass', '1');
    if (checkoutId) formData.append('checkout_id', checkoutId);

    $.ajax({
        url: 'varliklar',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: isTr ? 'İade Alındı!' : 'Returned!',
                    text: response.message || (isTr ? 'İade işlemi başarıyla tamamlandı.' : 'Successfully completed.'),
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: isTr ? 'Hata!' : 'Error!',
                    text: response.message || (isTr ? 'İade işlemi başarısız oldu.' : 'Return failed.')
                });
            }
        },
        error: function() {
            Swal.fire({
                icon: 'error',
                title: isTr ? 'Hata!' : 'Error!',
                text: isTr ? 'İnternet bağlantısı veya sunucu hatası.' : 'Network or server error.'
            });
        }
    });
};
</script>
