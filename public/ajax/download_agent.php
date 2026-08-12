<?php
// public/ajax/download_agent.php
require_once __DIR__ . '/../../app/includes/session.php';
require_once __DIR__ . '/../../app/config/db.php';

// Compute dynamic base URL including path/subdirectory
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$port = $_SERVER['SERVER_PORT'] ?? 80;
$disp_port = ($protocol == 'http' && $port == 80 || $protocol == 'https' && $port == 443) ? '' : ":$port";
$domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$path = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$path = str_replace('\\', '/', $path); // Windows fix
if (substr($path, -12) === '/public/ajax') {
    $path = substr($path, 0, -12);
} elseif (substr($path, -7) === '/public') {
    $path = substr($path, 0, -7);
} elseif (substr($path, -5) === '/ajax') {
    $path = substr($path, 0, -5);
}
$path = rtrim($path, '/');
$baseUrl = rtrim("$protocol://$domain$disp_port$path", '/');

$isPersonal = isset($_GET['personal']) && $_GET['personal'] == '1';
$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$apiKeyParam = isset($_GET['api_key']) ? trim($_GET['api_key']) : '';

$authSuccess = false;
if (isset($_SESSION['user_id'])) {
    $authSuccess = true;
} else if ($isPersonal && $userId > 0 && !empty($apiKeyParam)) {
    $pdo = db();
    $stmtKey = $pdo->prepare("SELECT COUNT(*) FROM api_keys WHERE user_id = ? AND client_id = ? AND revoked_at IS NULL");
    $stmtKey->execute([$userId, $apiKeyParam]);
    if ($stmtKey->fetchColumn() > 0) {
        $authSuccess = true;
    }
}

if (!$authSuccess) {
    if (php_sapi_name() !== 'cli' && !isset($_GET['api_key'])) {
        header("Location: " . $baseUrl . "/login");
        exit;
    } else {
        http_response_code(401);
        die("Yetkisiz erisim. / Unauthorized access.");
    }
}

if (!$isPersonal && s('api_enabled') !== '1') {
    $lang = $_SESSION['lang'] ?? 'tr';
    $isTr = ($lang === 'tr');
    $errorTitle = $isTr ? 'API Girişi Devre Dışı' : 'API Access Disabled';
    $errorMsg = $isTr 
        ? 'Sistem genelinde API girişi pasif durumdadır. Ajan dosyalarını indirebilmek için öncelikle <b>Sistem Ayarları > API</b> sekmesinden API girişini aktifleştirmeniz gerekmektedir.'
        : 'System-wide API access is currently disabled. To download agent files, you must first enable API access in the <b>System Settings > API</b> tab.';
    $btnText = $isTr ? 'Sistem Ayarlarına Git' : 'Go to System Settings';
    
    ?>
    <!DOCTYPE html>
    <html lang="<?= $lang ?>">
    <head>
        <meta charset="UTF-8">
        <title>Eaprimus | <?= $errorTitle ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0f1b3d; height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; color: #fff; text-align: center; }
            .error-card { background: rgba(255,255,255,0.05); backdrop-filter: blur(10px); padding: 50px 40px; border-radius: 24px; border: 1px solid rgba(255,255,255,0.1); max-width: 550px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.3); }
            .warning-icon { font-size: 80px; color: #ef4444; margin-bottom: 25px; animation: pulse 2s infinite; }
            @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }
            .title { font-size: 22px; margin-bottom: 15px; font-weight: 700; color: #fff; }
            .desc { color: #94a3b8; line-height: 1.6; font-size: 15px; margin-bottom: 30px; }
            .btn-action { display: inline-block; background: #3b82f6; color: #fff; text-decoration: none; padding: 14px 28px; border-radius: 12px; font-weight: 600; transition: all 0.3s; border: 1px solid rgba(255,255,255,0.1); }
            .btn-action:hover { background: #2563eb; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(37,99,235,0.3); color: #fff; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <div class="warning-icon"><i class="fas fa-ban"></i></div>
            <div class="title"><?= $errorTitle ?></div>
            <div class="desc"><?= $errorMsg ?></div>
            <a href="../../sistem-ayarlari?tab=api" class="btn-action"><i class="fas fa-cog mr-2"></i> <?= $btnText ?></a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$lang = $_SESSION['lang'] ?? 'tr';
$isTr = ($lang === 'tr');

if (!isset($pdo)) {
    $pdo = db();
}

if ($isPersonal) {
    if (empty($apiKeyParam) && !isset($_SESSION['user_id'])) {
        die("Yetkisiz erisim. / Unauthorized access.");
    }
    if (isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
        if ((int)$_SESSION['role'] === 1 && isset($_GET['user_id'])) {
            $userId = intval($_GET['user_id']);
        }
    } else {
        $userId = intval($_GET['user_id']);
    }
    $stmtKey = $pdo->prepare("SELECT client_id, client_secret_plain FROM api_keys WHERE user_id = ? AND revoked_at IS NULL LIMIT 1");
    $stmtKey->execute([$userId]);
    $personalKey = $stmtKey->fetch(PDO::FETCH_ASSOC);
    if (!$personalKey) {
        die($isTr
            ? "Lutfen once bu kullanici icin bir API anahtari olusturun."
            : "Please first generate an API key for this user.");
    }
    $apiKey    = $personalKey['client_id'];
    $apiSecret = $personalKey['client_secret_plain'];
} else {
    if ($_SESSION['role'] != 1) {
        die("Yetkisiz erisim. / Unauthorized.");
    }
    global $allSettings;
    $apiKey    = $allSettings['api_client_id']     ?? '';
    $apiSecret = $allSettings['api_client_secret'] ?? '';
    if (empty($apiKey) || empty($apiSecret)) {
        $errorTitle = $isTr ? 'API Anahtarı Eksik' : 'API Key Missing';
        $errorMsg = $isTr 
            ? 'Sistem genelinde ajan kaydı yapılabilmesi için öncelikle sistem API anahtarlarının oluşturulması gerekmektedir.<br><br>Lütfen panelde <b>Sistem Ayarları > API</b> sekmesine giderek <b>"Yeni API Anahtarı Üret"</b> butonuna tıklayın ve ayarları kaydedin.'
            : 'To perform global agent registration, you must first generate system API keys.<br><br>Please go to <b>System Settings > API</b> tab in the panel, click <b>"Generate New API Key"</b> and save the settings.';
        $btnText = $isTr ? 'Sistem Ayarlarına Git' : 'Go to System Settings';
        
        ?>
        <!DOCTYPE html>
        <html lang="<?= $lang ?>">
        <head>
            <meta charset="UTF-8">
            <title>Eaprimus | <?= $errorTitle ?></title>
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <style>
                body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0f1b3d; height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; color: #fff; text-align: center; }
                .error-card { background: rgba(255,255,255,0.05); backdrop-filter: blur(10px); padding: 50px 40px; border-radius: 24px; border: 1px solid rgba(255,255,255,0.1); max-width: 550px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.3); }
                .warning-icon { font-size: 80px; color: #f59e0b; margin-bottom: 25px; animation: pulse 2s infinite; }
                @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }
                .title { font-size: 22px; margin-bottom: 15px; font-weight: 700; color: #fff; }
                .desc { color: #94a3b8; line-height: 1.6; font-size: 15px; margin-bottom: 30px; }
                .btn-action { display: inline-block; background: #3b82f6; color: #fff; text-decoration: none; padding: 14px 28px; border-radius: 12px; font-weight: 600; transition: all 0.3s; border: 1px solid rgba(255,255,255,0.1); }
                .btn-action:hover { background: #2563eb; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(37,99,235,0.3); color: #fff; }
            </style>
        </head>
        <body>
            <div class="error-card">
                <div class="warning-icon"><i class="fas fa-key"></i></div>
                <div class="title"><?= $errorTitle ?></div>
                <div class="desc"><?= $errorMsg ?></div>
                <a href="<?= $baseUrl ?>/sistem-ayarlari?tab=api" class="btn-action"><i class="fas fa-cog mr-2"></i> <?= $btnText ?></a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

$activationToken = '';
if (s('api_agent_auto_register') === '1') {
    // Generate a secure activation token
    $activationToken = 'ea_act_' . bin2hex(random_bytes(16));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 years'));
    $createdBy = $_SESSION['user_id'] ?? 1;

    // Save token to database
    try {
        $stmtAct = $pdo->prepare("INSERT INTO agent_activation_tokens (token, created_by, expires_at, used_count, max_uses) VALUES (?, ?, ?, 0, 1000000)");
        $stmtAct->execute([$activationToken, $createdBy, $expiresAt]);

        // Log the creation
        if (function_exists('systemLog')) {
            systemLog('AGENT_ACT_TOKEN_GENERATED', "Yeni ajan indirme aktivasyon tokenı üretildi. Sorumlu ID: {$createdBy}");
        }
    } catch (Exception $ex) {}
}

// ─────────────────────────────────────────────────────────────────────────────
// Build hybrid BAT/PowerShell script (PowerShell 5.1 compatible)
$script  = <<<'PS_PART1'
<# :
@echo off
rem ==============================================================================
rem  Eaprimus Endpoint Agent
rem ==============================================================================
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo {{BAT_ADMIN_MSG}}
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)
set "IS_ADMIN=1"

set "AGENT_DIR=%ProgramData%\Eaprimus"
set "AGENT_LOG=%AGENT_DIR%\%COMPUTERNAME%_logs-{{COMPANY_NAME}}.log"
set "AGENT_PS1=%AGENT_DIR%\agent.ps1"

if not exist "%AGENT_DIR%" mkdir "%AGENT_DIR%" >nul 2>&1

rem ── Service mode: run the stored script directly (called by Task Scheduler)
if "%1"=="--service" (
    set "EAPRIMUS_SERVICE=1"
    powershell -NoProfile -ExecutionPolicy Bypass -File "%AGENT_PS1%" --service >> "%AGENT_LOG%" 2>&1
    exit /b
)

rem ── Interactive mode: update agent before running ─────────────────────────
rem 1) Stop and delete the old scheduled task (so the old agent.ps1 is not locked)
schtasks /end    /tn "EaprimusAgentSync" >nul 2>&1
schtasks /delete /tn "EaprimusAgentSync" /f  >nul 2>&1

rem 2) Delete the old agent script, config, cache, and logs to ensure a clean install/update
if exist "%AGENT_PS1%" del /f /q "%AGENT_PS1%" >nul 2>&1
if exist "%AGENT_DIR%\config.json" del /f /q "%AGENT_DIR%\config.json" >nul 2>&1
if exist "%AGENT_DIR%\last_sync.json" del /f /q "%AGENT_DIR%\last_sync.json" >nul 2>&1
if exist "%AGENT_DIR%\agent_log.txt" del /f /q "%AGENT_DIR%\agent_log.txt" >nul 2>&1
if exist "%AGENT_DIR%\agent_last_run.log" del /f /q "%AGENT_DIR%\agent_last_run.log" >nul 2>&1
if exist "%AGENT_DIR%\*_logs-*.log" del /f /q "%AGENT_DIR%\*_logs-*.log" >nul 2>&1
if exist "%AGENT_LOG%" del /f /q "%AGENT_LOG%" >nul 2>&1

rem 3) Copy THIS (new) BAT as the agent.ps1
copy /y "%~f0" "%AGENT_PS1%" >nul 2>&1

echo {{BAT_WAIT_MSG}}
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%AGENT_PS1%"
echo.
echo ==============================================================================
if %IS_ADMIN%==1 (
    echo {{BAT_SERVICE_MSG}} Log: %AGENT_LOG%
) else (
    echo {{BAT_WARN_MSG}}
    echo {{BAT_RETRY_MSG}}
)
echo ==============================================================================
pause
exit /b
#>
PS_PART1;

$rawCompany = s('company_name', 'Eaprimus');
$cleanCompany = preg_replace('/[^a-zA-Z0-9_\-]/', '', $rawCompany);
if (empty($cleanCompany)) $cleanCompany = 'Eaprimus';

$batAdminMsg = $isTr ? 'Yonetici yetkileri aliniyor...' : 'Requesting administrator privileges...';
$batWaitMsg = $isTr ? 'Lutfen bekleyin, sistem bilgileri toplanip sunucuya gonderiliyor...' : 'Please wait, collecting system information and sending to server...';
$batServiceMsg = $isTr ? 'Ajan arka plan servisi yapilandirildi.' : 'Agent background service configured.';
$batWarnMsg = $isTr ? '[UYARI] Yonetisi yetkisi olmadan calistirdiniz.' : '[WARNING] You executed without administrator privileges.';
$batRetryMsg = $isTr ? '[UYARI] Lutfen "Yonetici olarak calistir" ile tekrar deneyin.' : '[WARNING] Please retry by choosing "Run as administrator".';

$script = str_replace([
    '{{COMPANY_NAME}}',
    '{{BAT_ADMIN_MSG}}',
    '{{BAT_WAIT_MSG}}',
    '{{BAT_SERVICE_MSG}}',
    '{{BAT_WARN_MSG}}',
    '{{BAT_RETRY_MSG}}'
], [
    $cleanCompany,
    $batAdminMsg,
    $batWaitMsg,
    $batServiceMsg,
    $batWarnMsg,
    $batRetryMsg
], $script);

// Part 2 – inject PHP credentials as obfuscated base64 strings
$psApiUrl    = base64_encode($baseUrl . '/api/v1');
$psApiKey    = base64_encode($apiKey);
$psApiSecret = base64_encode($apiSecret);
$psVerifySsl = (s('api_verify_ssl') === '1') ? '$true' : '$false';
$psAutoReg   = (s('api_agent_auto_register') === '1') ? '$true' : '$false';
$psActToken  = str_replace("'", "''", $activationToken);
$psIsTr      = $isTr ? '$true' : '$false';

$script .= "\r\n\$DEFAULT_API_URL    = [System.Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('$psApiUrl'))\r\n";
$script .= "\$DEFAULT_API_KEY    = [System.Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('$psApiKey'))\r\n";
$script .= "\$DEFAULT_API_SECRET = [System.Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('$psApiSecret'))\r\n";
$script .= "\$VERIFY_SSL         = $psVerifySsl\r\n";
$script .= "\$AUTO_REGISTER       = $psAutoReg\r\n";
$script .= "\$ACTIVATION_TOKEN    = '$psActToken'\r\n";
$script .= "\$IS_TR               = $psIsTr\r\n\r\n";

// Part 3 – rest of PS script (single-quoted = literal, no PHP interpolation)
$script .= <<<'PS_PART2'

$targetDir    = "C:\ProgramData\Eaprimus"
$targetScript = "$targetDir\agent.ps1"
$cacheFile    = "$targetDir\last_sync.json"
$configPath   = "$targetDir\config.json"
$logFile      = "$targetDir\$($env:COMPUTERNAME)_logs-{{COMPANY_NAME}}.log"
$isService    = ($args -contains "--service") -or ($env:EAPRIMUS_SERVICE -eq "1")

# ── SSL Validation Policy ────────────────────────────────────────────────────
if (-not $VERIFY_SSL) {
    if ([Type]::GetType("TrustAllCertsPolicy") -eq $null) {
        Add-Type @"
using System.Net;
using System.Security.Cryptography.X509Certificates;
public class TrustAllCertsPolicy : ICertificatePolicy {
    public bool CheckValidationResult(ServicePoint sp, X509Certificate cert,
        WebRequest req, int prob) { return true; }
}
"@
    }
    [System.Net.ServicePointManager]::CertificatePolicy = New-Object TrustAllCertsPolicy
}

# ── DPAPI Helpers ────────────────────────────────────────────────────────────
Function Encrypt-Secret($clearText) {
    try {
        Add-Type -AssemblyName System.Security
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($clearText)
        $entropy = [System.Text.Encoding]::UTF8.GetBytes("EaprimusEntropy")
        $encBytes = [System.Security.Cryptography.ProtectedData]::Protect($bytes, $entropy, [System.Security.Cryptography.DataProtectionScope]::LocalMachine)
        return [Convert]::ToBase64String($encBytes)
    } catch {
        return $null
    }
}

Function Decrypt-Secret($encText) {
    try {
        Add-Type -AssemblyName System.Security
        $encBytes = [Convert]::FromBase64String($encText)
        $entropy = [System.Text.Encoding]::UTF8.GetBytes("EaprimusEntropy")
        $bytes = [System.Security.Cryptography.ProtectedData]::Unprotect($encBytes, $entropy, [System.Security.Cryptography.DataProtectionScope]::LocalMachine)
        return [System.Text.Encoding]::UTF8.GetString($bytes)
    } catch {
        return $null
    }
}

$API_URL    = $DEFAULT_API_URL
$API_KEY    = $DEFAULT_API_KEY
$API_SECRET = $DEFAULT_API_SECRET

# ── Auto Registration / Config Loading ────────────────────────────────────────
if ($AUTO_REGISTER) {
    $loadedFromConfig = $false
    if (Test-Path $configPath) {
        try {
            $cfg = Get-Content $configPath | ConvertFrom-Json
            if ($cfg.client_id -and $cfg.client_secret) {
                # Decrypt values using DPAPI
                $decId = Decrypt-Secret $cfg.client_id
                $decSecret = Decrypt-Secret $cfg.client_secret

                if ($decId -and $decSecret) {
                    $API_KEY = $decId
                    $API_SECRET = $decSecret
                    $loadedFromConfig = $true
                }
            }
        } catch {}
    }

    if (-not $loadedFromConfig -and $ACTIVATION_TOKEN) {
        if ($IS_TR) { Write-Host "Ajan kaydi baslatiliyor..." } else { Write-Host "Registering agent..." }

        # Determine MAC and IP
        $mac = ""
        $adapters = Get-CimInstance Win32_NetworkAdapter -ErrorAction SilentlyContinue | Where-Object { $_.PhysicalAdapter -eq $true }
        foreach ($a in $adapters) {
            $aDevID = $a.DeviceID
            $cfg    = Get-CimInstance Win32_NetworkAdapterConfiguration -ErrorAction SilentlyContinue |
                      Where-Object { $_.Index -eq $aDevID -and $_.IPEnabled -eq $true }
            if ($cfg) { $mac = $a.MACAddress; break }
        }
        if (-not $mac) { $mac = $env:COMPUTERNAME }

        $primaryIP = ""
        foreach ($a in $adapters) {
            $aDevID = $a.DeviceID
            $cfg    = Get-CimInstance Win32_NetworkAdapterConfiguration -ErrorAction SilentlyContinue |
                      Where-Object { $_.Index -eq $aDevID -and $_.IPEnabled -eq $true }
            if ($cfg) {
                $ipv4 = $cfg.IPAddress | Where-Object { $_ -match '^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$' } | Select-Object -First 1
                if ($ipv4) { $primaryIP = $ipv4; break }
            }
        }

        $regBody = @{
            activation_token = $ACTIVATION_TOKEN
            mac_address = $mac
            computer_name = $env:COMPUTERNAME
            ip_address = $primaryIP
        } | ConvertTo-Json

        try {
            $r = Invoke-RestMethod -Uri "$API_URL/agent-register" -Method Post -Body $regBody -ContentType "application/json"
            if ($r -and $r.client_id -and $r.client_secret) {
                # Encrypt values using DPAPI
                $encId = Encrypt-Secret $r.client_id
                $encSecret = Encrypt-Secret $r.client_secret

                if ($encId -and $encSecret) {
                    if (-not (Test-Path $targetDir)) {
                        New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
                    }
                    $cfgJson = @{
                        client_id = $encId
                        client_secret = $encSecret
                    } | ConvertTo-Json
                    $cfgJson | Out-File $configPath -Encoding UTF8 -Force

                    $API_KEY = $r.client_id
                    $API_SECRET = $r.client_secret
                    if ($IS_TR) { Write-Host "Ajan basariyla kaydedildi." } else { Write-Host "Agent registered successfully." }
                } else {
                    if ($IS_TR) { Write-Host "AJAN SIFRELEME HATASI: DPAPI basarisiz." } else { Write-Host "AGENT ENCRYPTION ERROR: DPAPI failed." }
                }
            } else {
                if ($IS_TR) {
                    Write-Host "AJAN KAYIT YAPILANDIRMASI ALINAMADI: Sunucu yanitinda gerekli kimlik bilgileri (client_id/client_secret) eksik." -ForegroundColor Yellow
                } else {
                    Write-Host "AGENT REGISTRATION CONFIGURATION NOT RECEIVED: Missing credentials (client_id/client_secret) in server response." -ForegroundColor Yellow
                }
                Write-Host "AJAN KAYIT YANITI: $r" -ForegroundColor Yellow
            }
        } catch {
            $errMsg = $_.Exception.Message
            $statusCode = 0
            if ($_.Exception -and $_.Exception.Response) {
                try {
                    $statusCode = [int]$_.Exception.Response.StatusCode
                } catch {}
            }
            if ($statusCode -eq 401) {
                if ($IS_TR) {
                    Write-Host "[HATA] Kayit Basarisiz (401 Yetkisiz). Aktivasyon anahtari (Token) gecersiz veya suresi dolmus." -ForegroundColor Red
                } else {
                    Write-Host "[ERROR] Registration Failed (401 Unauthorized). Activation token is invalid or expired." -ForegroundColor Red
                }
            } else {
                if ($IS_TR) { Write-Host "AJAN KAYIT HATASI: $errMsg" } else { Write-Host "AGENT REGISTRATION ERROR: $errMsg" }
            }
        }
    }
}

$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
$isAdmin   = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if ($isAdmin -and -not $isService) {
    try {
        Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe' AND CommandLine LIKE '%Eaprimus%'" -ErrorAction SilentlyContinue | ForEach-Object {
            if ($_.ProcessId -ne $PID) {
                Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
            }
        }
    } catch {}

    if (-not (Test-Path $targetDir)) {
        New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
    }
    try {
        $src = $MyInvocation.MyCommand.Path
        if ($src -and (Test-Path $src) -and ($src -ne $targetScript)) {
            Copy-Item -Path $src -Destination $targetScript -Force
        }
    } catch {}
    if (Test-Path $targetScript) {
        $created = $false
        # Görevi sistem başlangıcında çalışacak şekilde kuruyoruz (Sürekli çalışan arka plan servisi)
        $out = schtasks /create /tn "EaprimusAgentSync" /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$targetScript`" --service" /sc ONSTART /ru "SYSTEM" /f 2>&1
        if ($LASTEXITCODE -eq 0) {
            $created = $true
        }

        # Görev ayarlarını (Pilde çalışabilme, uykudan uyandırma, çökme durumunda otomatik yeniden başlatma vb.) güncelliyoruz
        try {
            $taskObj = Get-ScheduledTask -TaskName "EaprimusAgentSync" -ErrorAction SilentlyContinue
            if ($taskObj) {
                $taskObj.Settings.AllowStartIfOnBatteries = $true
                $taskObj.Settings.DontStopIfGoingOnBatteries = $true
                $taskObj.Settings.WakeToRun = $true
                $taskObj.Settings.StartWhenAvailable = $true
                $taskObj.Settings.RestartCount = 999
                $taskObj.Settings.RestartInterval = New-TimeSpan -Minutes 1
                Set-ScheduledTask -InputObject $taskObj | Out-Null
            }
        } catch {}

        if ($created) {
            schtasks /run /tn "EaprimusAgentSync" | Out-Null
            if ($IS_TR) { Write-Host "Arka plan senkronizasyon servisi kuruldu ve baslatildi." } else { Write-Host "Background synchronization service configured and started." }
        } else {
            if ($IS_TR) {
                Write-Host "[UYARI] Arka plan servisi (Zamanlanmis Gorev) olusturulamadi: $out" -ForegroundColor Yellow
                Write-Host "[UYARI] Lutfen BAT dosyasini sag tiklayip 'Yonetici Olarak Calistir' secenegi ile calistirdiginizdan emin olun." -ForegroundColor Yellow
            } else {
                Write-Host "[WARNING] Background service (Scheduled Task) could not be created: $out" -ForegroundColor Yellow
                Write-Host "[WARNING] Please make sure to right-click the BAT file and choose 'Run as Administrator'." -ForegroundColor Yellow
            }
        }
    }
}

# ── Auth ─────────────────────────────────────────────────────────────────────
$AGENT_TOKEN   = $null
$AGENT_EXPIRES = [DateTime]::MinValue

Function Get-ValidToken {
    $now = Get-Date
    if ((-not $script:AGENT_TOKEN) -or ($now -ge $script:AGENT_EXPIRES)) {
        $body = @{ api_key=$API_KEY; api_secret=$API_SECRET } | ConvertTo-Json
        try {
            $r = Invoke-RestMethod -Uri "$API_URL/auth" -Method Post -Body $body -ContentType "application/json"
            if ($r -and $r.token) {
                $script:AGENT_TOKEN = $r.token
                $parts = $r.token.Split('.')
                if ($parts.Count -eq 3) {
                    $pb  = $parts[1].Replace('-','+').Replace('_','/')
                    $pad = $pb.Length % 4
                    if ($pad -eq 2) { $pb += "==" } elseif ($pad -eq 3) { $pb += "=" }
                    $pj = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($pb)) | ConvertFrom-Json
                    if ($pj -and $pj.exp) {
                        $epoch = New-Object DateTime 1970,1,1,0,0,0,([DateTimeKind]::Utc)
                        $script:AGENT_EXPIRES = $epoch.AddSeconds($pj.exp).ToLocalTime().AddSeconds(-60)
                    } else {
                        $script:AGENT_EXPIRES = $now.AddSeconds(3000)
                    }
                } else {
                    $script:AGENT_EXPIRES = $now.AddSeconds(3000)
                }
            }
        } catch {
            $errMsg = $_.Exception.Message
            $statusCode = 0
            if ($_.Exception -and $_.Exception.Response) {
                try {
                    $statusCode = [int]$_.Exception.Response.StatusCode
                } catch {}
            }
            if ($statusCode -eq 401) {
                # Stop scheduled task and clean config
                & schtasks /end /tn "EaprimusAgentSync" 2>&1 | Out-Null
                & schtasks /delete /tn "EaprimusAgentSync" /f 2>&1 | Out-Null
                if (Test-Path $configPath) {
                    Remove-Item $configPath -Force -ErrorAction SilentlyContinue
                }

                $currentScriptPath = $MyInvocation.MyCommand.Path
                if ($IS_TR) {
                    $warnMsg = "`n" + ("="*78) + "`n" +
                               "[HATA] API Yetkilendirme Basarisiz! (401 Yetkisiz / Onaylanmadi)`n" +
                               "[HATA] Girdiginiz API Key veya Secret sunucu tarafindan reddedildi.`n" +
                               "[HATA] Lutfen panele gidip yeni bir Eaprimus-Ajan.bat indirin.`n" +
                               "[HATA] Calistirilan eski dosya yolu: $currentScriptPath`n" +
                               "[HATA] Lutfen bu eski dosyayi silin.`n" +
                               ("="*78) + "`n"
                } else {
                    $warnMsg = "`n" + ("="*78) + "`n" +
                               "[ERROR] API Authentication Failed! (401 Unauthorized)`n" +
                               "[ERROR] The configured API Key or Secret was rejected by the server.`n" +
                               "[ERROR] Please log into the panel and download a fresh Eaprimus-Ajan.bat.`n" +
                               "[ERROR] Current old file path: $currentScriptPath`n" +
                               "[ERROR] Please delete this old file.`n" +
                               ("="*78) + "`n"
                }

                if ($isService) {
                    if ($IS_TR) {
                        Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] AUTH HATASI: 401 Yetkisiz. Eski/gecersiz anahtar. Servis durduruldu." | Out-File $logFile -Append
                    } else {
                        Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] AUTH ERROR: 401 Unauthorized. Old/invalid key. Service stopped." | Out-File $logFile -Append
                    }
                } else {
                    Write-Host $warnMsg -ForegroundColor Red
                }
            } else {
                if ($isService) {
                    if ($IS_TR) {
                        Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] AUTH HATASI: $errMsg" | Out-File $logFile -Append
                    } else {
                        Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] AUTH ERROR: $errMsg" | Out-File $logFile -Append
                    }
                } else {
                    if ($IS_TR) { Write-Host "AUTH HATASI: $errMsg" } else { Write-Host "AUTH ERROR: $errMsg" }
                }
            }
        }
    }
    return $script:AGENT_TOKEN
}

# ── Software list ─────────────────────────────────────────────────────────────
Function Get-InstalledSoftware {
    $list = @()
    $paths = @(
        "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*",
        "HKLM:\SOFTWARE\Wow6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*"
    )
    foreach ($p in $paths) {
        $items = Get-ItemProperty $p -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName }
        foreach ($i in $items) {
            $list += @{ Name=$i.DisplayName; Version=$i.DisplayVersion; Publisher=$i.Publisher }
        }
    }
    return $list | Sort-Object Name -Unique
}

# ── System info ───────────────────────────────────────────────────────────────
Function Get-SystemInfo {
    $hn     = $env:COMPUTERNAME
    $osBase = (Get-CimInstance Win32_OperatingSystem).Caption
    $dv     = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' -ErrorAction SilentlyContinue).DisplayVersion
    if (-not $dv) {
        $dv = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' -ErrorAction SilentlyContinue).ReleaseId
    }
    if ($dv) { $os = "$osBase ($dv)" } else { $os = $osBase }

    $cpu   = (Get-CimInstance Win32_Processor | Select-Object -First 1).Name
    $ramGB = [math]::Round((Get-CimInstance Win32_PhysicalMemory | Measure-Object -Property Capacity -Sum).Sum / 1GB, 2)

    # Disk
    $diskList = @()
    $disks    = Get-CimInstance Win32_DiskDrive | Sort-Object Index
    foreach ($d in $disks) {
        if ($d.Size -gt 0) {
            $gb = [math]::Round($d.Size / 1000000000, 0)
            if ($gb -eq 0) { $gb = [math]::Round($d.Size / 1000000000, 2) }
            if ($d.Model -match "$gb\s*(GB|G)") {
                $diskList += $d.Model
            } else {
                $diskList += "$($d.Model) ($gb GB)"
            }
        }
    }
    $diskText = $diskList -join " + "
    if (-not $diskText) {
        $diskC    = Get-CimInstance Win32_LogicalDisk | Where-Object { $_.DeviceID -eq "C:" }
        $diskText = "$([math]::Round($diskC.Size / 1000000000, 0)) GB"
    }

    # GPU
    $gpuList = Get-CimInstance Win32_VideoController | Select-Object -ExpandProperty Name | Sort-Object -Unique
    $gpu     = $gpuList -join ", "
    if (-not $gpu) { $gpu = "Bulunamadi" }

    # Monitor
    $mnames = Get-CimInstance Win32_DesktopMonitor | Where-Object { $_.Caption } | Select-Object -ExpandProperty Caption
    if (-not $mnames) {
        $mnames = Get-CimInstance Win32_PnPSignedDriver |
                  Where-Object { $_.DeviceClass -eq "MONITOR" -and $_.DeviceName } |
                  Select-Object -ExpandProperty DeviceName
    }
    $ml = @()
    foreach ($m in $mnames) {
        if ($m -and ($ml -notcontains $m)) { $ml += $m }
    }
    if ($ml.Count -gt 1) {
        $f = $ml | Where-Object { $_ -ne "Generic PnP Monitor" -and $_ -ne "Genel PnP Monitör" }
        if ($f) { $ml = $f }
    }
    if ($ml) { $monitorInfo = $ml -join ", " } else { $monitorInfo = "Bulunamadi" }

    # Network
    $ethIP = ""; $ethMAC = ""; $wfIP = ""; $wfMAC = ""
    $adapters = Get-CimInstance Win32_NetworkAdapter | Where-Object { $_.PhysicalAdapter -eq $true }
    foreach ($a in $adapters) {
        $aDevID = $a.DeviceID
        $cfg    = Get-CimInstance Win32_NetworkAdapterConfiguration |
                  Where-Object { $_.Index -eq $aDevID -and $_.IPEnabled -eq $true }
        if ($cfg) {
            $ipv4 = $cfg.IPAddress | Where-Object { $_ -match '^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$' } | Select-Object -First 1
            if ($ipv4) {
                $conn = $a.NetConnectionID
                $nm   = $a.Name
                if ($conn -match "Wi-Fi|Wireless|Kablosuz" -or $nm -match "Wi-Fi|Wireless|802.11") {
                    if (-not $wfIP) { $wfIP = $ipv4; $wfMAC = $a.MACAddress }
                } else {
                    if (-not $ethIP) { $ethIP = $ipv4; $ethMAC = $a.MACAddress }
                }
            }
        }
    }
    if ($ethIP)      { $primaryIP = $ethIP;  $primaryMAC = $ethMAC }
    elseif ($wfIP)   { $primaryIP = $wfIP;   $primaryMAC = $wfMAC  }
    else             { $primaryIP = "";       $primaryMAC = ""      }

    $serial = (Get-CimInstance Win32_BIOS).SerialNumber
    $board  = Get-CimInstance Win32_BaseBoard -ErrorAction SilentlyContinue
    if ($board) {
        $mb = (("$($board.Manufacturer) $($board.Product)") -replace '\s+',' ').Trim()
    } else {
        $mb = ""
    }

    $av = "Bulunamadi"
    try {
        $avObj = Get-CimInstance -Namespace root\SecurityCenter2 -Class AntivirusProduct -ErrorAction SilentlyContinue |
                 Select-Object -First 1
        if ($avObj) { $av = $avObj.displayName }
    } catch {}

    return @{
        name        = $hn
        asset_tag   = $hn
        serial_no   = $serial
        ip_address  = $primaryIP
        mac_address = $primaryMAC
        type        = "Desktop/Laptop"
        specs       = @{
            mainboard       = $mb
            os              = $os
            cpu             = $cpu
            ram_gb          = $ramGB
            disk_c_total_gb = $diskText
            gpu             = $gpu
            monitor         = $monitorInfo
            ethernet_ip     = $ethIP
            ethernet_mac    = $ethMAC
            wifi_ip         = $wfIP
            wifi_mac        = $wfMAC
            antivirus       = $av
            installed_software = (Get-InstalledSoftware)
        }
    }
}

# ── Sync to server ────────────────────────────────────────────────────────────
Function Sync-ToServer {
    $jwt = Get-ValidToken
    if (-not $jwt) { return $false }
    $hdrs = @{ Authorization = "Bearer $jwt" }
    try {
        $p    = Get-SystemInfo
        $json = $p | ConvertTo-Json -Depth 5
        $r = Invoke-RestMethod -Uri "$API_URL/assets" -Method Post -Headers $hdrs -Body $json -ContentType "application/json"

        if ($isAdmin) {
            $p.specs.Remove("installed_software") | Out-Null
            $p | ConvertTo-Json | Out-File $cacheFile -Encoding UTF8 -Force
        }
        return $true
    } catch {
        $errMsg = $_.Exception.Message
        if ($isService) {
            if ($IS_TR) {
                Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] GONDERIM HATASI: $errMsg" | Out-File $logFile -Append
            } else {
                Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] SEND ERROR: $errMsg" | Out-File $logFile -Append
            }
        }
        return $false
    }
}

# ── Helpers ───────────────────────────────────────────────────────────────────
Function Has-HardwareChanged {
    if (-not (Test-Path $cacheFile)) { return $true }
    try {
        $c = Get-Content $cacheFile | ConvertFrom-Json
        $n = Get-SystemInfo
        if ($c.specs.cpu             -ne $n.specs.cpu             -or
            $c.specs.ram_gb          -ne $n.specs.ram_gb          -or
            $c.specs.disk_c_total_gb -ne $n.specs.disk_c_total_gb -or
            $c.specs.gpu             -ne $n.specs.gpu             -or
            $c.specs.monitor         -ne $n.specs.monitor         -or
            $c.specs.os              -ne $n.specs.os) {
            return $true
        }
        return $false
    } catch {
        return $true
    }
}

Function Get-LocalMac {
    $adapters = Get-CimInstance Win32_NetworkAdapter | Where-Object { $_.PhysicalAdapter -eq $true }
    foreach ($a in $adapters) {
        $aDevID = $a.DeviceID
        $cfg    = Get-CimInstance Win32_NetworkAdapterConfiguration |
                  Where-Object { $_.Index -eq $aDevID -and $_.IPEnabled -eq $true }
        if ($cfg) { return $a.MACAddress }
    }
    return $env:COMPUTERNAME
}

# ==============================================================================
#  EXECUTION FLOW (Run & Close Logic)
# ==============================================================================
if (-not $isService) {
    # Interactive: Kurulum sırasında sadece 1 kez çalışır
    $ok = Sync-ToServer
    if (-not $ok) {
        if ($IS_TR) { Write-Host "UYARI: Ilk senkronizasyon basarisiz oldu. Arka plan servisi otomatik olarak tekrar deneyecektir." } else { Write-Host "WARNING: Initial synchronization failed. Background service will retry automatically." }
    }
} else {
    # Service mode: Run as a persistent background loop (similar to a Windows Service)
    $lastFullSync = [DateTime]::MinValue
    $syncIntervalMinutes = 60

    while ($true) {
        $now = Get-Date
        $needSync = $false

        # 1. Check if periodic sync time (1 hour) is reached
        if ($now -ge $lastFullSync.AddMinutes($syncIntervalMinutes)) {
            $needSync = $true
            if ($IS_TR) {
                Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Periyodik senkronizasyon tetiklendi (1 saatlik periyot)." | Out-File $logFile -Append
            } else {
                Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Periodic synchronization triggered (1 hour period)." | Out-File $logFile -Append
            }
        }

        # 2. Check if hardware changed
        if (-not $needSync -and (Has-HardwareChanged)) {
            $needSync = $true
            if ($IS_TR) {
                Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Donanim degisikligi algilandi, senkronizasyon tetiklendi." | Out-File $logFile -Append
            } else {
                Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Hardware change detected, synchronization triggered." | Out-File $logFile -Append
            }
        }

        # 3. If no sync is needed yet, wait for manual trigger from server via long-poll
        if (-not $needSync) {
            $jwt = Get-ValidToken
            if ($jwt) {
                $mac  = Get-LocalMac
                $hdrs = @{ Authorization = "Bearer $jwt" }
                $em   = [Uri]::EscapeDataString($mac)
                $en   = [Uri]::EscapeDataString($env:COMPUTERNAME)
                $checkUrl = "$API_URL/agent-wait?mac=$em&name=$en"

                try {
                    # Long-poll to server (Wait up to 60 seconds)
                    $res = Invoke-RestMethod -Uri $checkUrl -Method Get -Headers $hdrs -TimeoutSec 60

                    if ($res -and $res.sync_requested) {
                        if ($IS_TR) {
                            Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Sunucudan anlik tetikleme sinyali alindi." | Out-File $logFile -Append
                        } else {
                            Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Instant trigger signal received from server." | Out-File $logFile -Append
                        }
                        $needSync = $true
                    }
                } catch {
                    $errMsg = $_.Exception.Message
                    if ($errMsg -notmatch "timed out") {
                        if ($IS_TR) {
                            Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Tetikleme kontrol hatasi: $errMsg" | Out-File $logFile -Append
                        } else {
                            Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Trigger check error: $errMsg" | Out-File $logFile -Append
                        }
                    }
                    Start-Sleep -Seconds 10
                }
            } else {
                Start-Sleep -Seconds 30
            }
        }

        # 4. Perform the synchronization
        if ($needSync) {
            if (Sync-ToServer) {
                $lastFullSync = Get-Date
                if ($IS_TR) {
                    Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Senkronizasyon basarili." | Out-File $logFile -Append
                } else {
                    Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Synchronization successful." | Out-File $logFile -Append
                }
            } else {
                # If sync fails, retry after a short delay
                Start-Sleep -Seconds 20
            }
        }

        # Idle pause
        Start-Sleep -Seconds 2
    }
}
PS_PART2;
$script = str_replace('{{COMPANY_NAME}}', $cleanCompany, $script);

header('Content-Description: File Transfer');
header('Content-Type: application/bat');
header('Content-Disposition: attachment; filename="Eaprimus-Ajan.bat"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . strlen($script));
echo $script;
exit;
