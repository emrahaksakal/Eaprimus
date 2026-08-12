<?php
// public/ajax/download_agent_linux.php
require_once __DIR__ . '/../../app/includes/session.php';
require_once __DIR__ . '/../../app/config/db.php';

// Compute dynamic base URL including path/subdirectory
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$port = $_SERVER['SERVER_PORT'] ?? 80;
$disp_port = ($protocol == 'http' && $port == 80 || $protocol == 'https' && $port == 443) ? '' : ":$port";
$domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$path = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$path = str_replace('\\', '/', $path);
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
    $activationToken = 'ea_act_' . bin2hex(random_bytes(16));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 years'));
    $createdBy = $_SESSION['user_id'] ?? 1;

    try {
        $stmtAct = $pdo->prepare("INSERT INTO agent_activation_tokens (token, created_by, expires_at, used_count, max_uses) VALUES (?, ?, ?, 0, 1000000)");
        $stmtAct->execute([$activationToken, $createdBy, $expiresAt]);

        if (function_exists('systemLog')) {
            systemLog('AGENT_ACT_TOKEN_GENERATED', "Yeni Linux ajan indirme aktivasyon tokenı üretildi. Sorumlu ID: {$createdBy}");
        }
    } catch (Exception $ex) {}
}

$cleanCompany = preg_replace('/[^a-zA-Z0-9]/', '', s('company_name', 'EaprimusA'));

// Linux Bash Script Template using NOWDOC to prevent escaping errors
$script = <<<'BASH_SCRIPT'
#!/bin/bash
# ==============================================================================
#  Eaprimus Linux Endpoint Agent
# ==============================================================================

# Ensure running as root
if [ "$(id -u)" -ne 0 ]; then
    echo "HATA: Bu script root yetkileri ile calistirilmalidir. / ERROR: This script must be run as root."
    echo "Lutfen 'sudo' ile tekrar deneyin. / Please retry with 'sudo'."
    exit 1
fi

# Ensure curl is installed
if ! command -v curl &> /dev/null; then
    echo "HATA: 'curl' komutu bulunamadi. Lutfen once curl kurun. / ERROR: 'curl' not found. Please install curl."
    exit 1
fi

AGENT_DIR="/etc/eaprimus"
CONFIG_FILE="$AGENT_DIR/config.json"
CACHE_FILE="$AGENT_DIR/last_sync.json"
LOG_FILE="/var/log/eaprimus-agent.log"

API_URL="{{API_URL}}"
AUTO_REGISTER="{{AUTO_REGISTER}}"
API_KEY="{{API_KEY}}"
API_SECRET="{{API_SECRET}}"
ACTIVATION_TOKEN="{{ACTIVATION_TOKEN}}"
IS_TR={{IS_TR}}

# Create config directory if not exists
mkdir -p "$AGENT_DIR"
chmod 700 "$AGENT_DIR"

log_msg() {
    local tstamp=$(date "+%Y-%m-%d %H:%M:%S")
    echo "[$tstamp] [LINUX_AGENT] $1" >> "$LOG_FILE"
    echo "$1"
}

# Function to get default network interface details
get_network_info() {
    # Find default interface name
    local default_iface=$(ip route | grep '^default' | awk '{print $5}' | head -n 1)
    if [ -z "$default_iface" ]; then
        default_iface=$(ip -o link show | awk -F': ' '{print $2}' | grep -v 'lo' | head -n 1)
    fi

    # Get MAC Address
    local mac=$(cat "/sys/class/net/$default_iface/address" 2>/dev/null)
    if [ -z "$mac" ]; then
        mac=$(ip link show "$default_iface" | awk '/ether/ {print $2}')
    fi
    echo "$mac"
}

# Function to get primary IP
get_ip_address() {
    local ip_addr=$(hostname -I | awk '{print $1}')
    if [ -z "$ip_addr" ]; then
        ip_addr="127.0.0.1"
    fi
    echo "$ip_addr"
}

# Function to get serial number
get_serial_number() {
    local serial=""
    if [ -f "/sys/class/dmi/id/product_serial" ]; then
        serial=$(cat /sys/class/dmi/id/product_serial 2>/dev/null | tr -d '\r\n')
    fi
    if [ -z "$serial" ] || [ "$serial" = "System Serial Number" ] || [ "$serial" = "None" ]; then
        serial=$(dmidecode -s system-serial-number 2>/dev/null | tr -d '\r\n')
    fi
    if [ -z "$serial" ]; then
        serial="System-$(hostname)-$(get_network_info | tr -d ':')"
    fi
    echo "$serial"
}

# ── Auto Registration ────────────────────────────────────────────────────────
register_agent() {
    if [ "$AUTO_REGISTER" = "false" ]; then
        return 0
    fi
    if [ -f "$CONFIG_FILE" ]; then
        return 0
    fi

    if [ -z "$ACTIVATION_TOKEN" ]; then
        log_msg "HATA: Aktivasyon tokeni bulunamadi. / ERROR: Activation token not found."
        return 1
    fi

    local my_mac=$(get_network_info)
    local my_name=$(hostname)

    log_msg "Ajan kaydi baslatiliyor... / Registering agent..."
    
    local payload=$(cat <<EOF
{
  "activation_token": "$ACTIVATION_TOKEN",
  "mac_address": "$my_mac",
  "computer_name": "$my_name"
}
EOF
)

    local response=$(curl -s -k -X POST "$API_URL/agent-register" \
        -H "Content-Type: application/json" \
        -d "$payload")

    local c_id=$(echo "$response" | grep -o '"client_id":"[^"]*' | cut -d'"' -f4)
    local c_sec=$(echo "$response" | grep -o '"client_secret":"[^"]*' | cut -d'"' -f4)

    if [ -n "$c_id" ] && [ -n "$c_sec" ]; then
        cat <<EOF > "$CONFIG_FILE"
{
  "client_id": "$c_id",
  "client_secret": "$c_sec"
}
EOF
        chmod 600 "$CONFIG_FILE"
        log_msg "Ajan kaydi basarili. / Agent registration successful."
        return 0
    else
        log_msg "HATA: Ajan kayit yapilandirmasi alinamadi. / ERROR: Could not receive agent config."
        log_msg "Sunucu yaniti / Server response: $response"
        return 1
    fi
}

# ── Get Valid Token ─────────────────────────────────────────────────────────
get_valid_token() {
    local token_file="$AGENT_DIR/jwt.token"
    if [ -f "$token_file" ]; then
        local last_mod=$(stat -c %Y "$token_file" 2>/dev/null)
        [ -z "$last_mod" ] && last_mod=$(stat -f %m "$token_file" 2>/dev/null)
        local now=$(date +%s)
        if [ -n "$last_mod" ] && [ $((now - last_mod)) -lt 3000 ]; then
            cat "$token_file"
            return 0
        fi
    fi

    local c_id=""
    local c_sec=""

    if [ "$AUTO_REGISTER" = "false" ]; then
        c_id="$API_KEY"
        c_sec="$API_SECRET"
    else
        if [ ! -f "$CONFIG_FILE" ]; then
            return 1
        fi
        c_id=$(grep -o '"client_id": "[^"]*' "$CONFIG_FILE" | cut -d'"' -f4)
        c_sec=$(grep -o '"client_secret": "[^"]*' "$CONFIG_FILE" | cut -d'"' -f4)
        if [ -z "$c_id" ]; then
            c_id=$(grep -o '"client_id":"[^"]*' "$CONFIG_FILE" | cut -d'"' -f4)
        fi
        if [ -z "$c_sec" ]; then
            c_sec=$(grep -o '"client_secret":"[^"]*' "$CONFIG_FILE" | cut -d'"' -f4)
        fi
    fi

    if [ -z "$c_id" ] || [ -z "$c_sec" ]; then
        return 1
    fi

    local payload=$(cat <<EOF
{
  "grant_type": "client_credentials",
  "client_id": "$c_id",
  "client_secret": "$c_sec"
}
EOF
)

    local response=$(curl -s -k -X POST "$API_URL/auth" \
        -H "Content-Type: application/json" \
        -d "$payload")

    local token=$(echo "$response" | grep -o '"token":"[^"]*' | cut -d'"' -f4)
    if [ -z "$token" ]; then
        log_msg "HATA: Sunucudan token alinamadi. / ERROR: Could not get token. Yanit / Response: $response" >&2
    else
        echo "$token" > "$token_file"
        chmod 600 "$token_file" 2>/dev/null
    fi
    echo "$token"
}

# ── Linux Updates Checker ───────────────────────────────────────────────────
get_linux_updates() {
    local cache_file="/tmp/eaprimus_updates.cache"
    local now=$(date +%s)
    if [ -f "$cache_file" ]; then
        local last_check=$(stat -c %Y "$cache_file" 2>/dev/null)
        [ -z "$last_check" ] && last_check=$(stat -f %m "$cache_file" 2>/dev/null)
        if [ -n "$last_check" ] && [ $((now - last_check)) -lt 3600 ]; then
            cat "$cache_file"
            return 0
        fi
    fi

    local count=0
    if command -v apt-get &>/dev/null; then
        count=$(apt-get -s -o Debug::NoLocking=true upgrade 2>/dev/null | grep -E "^[0-9]+ upgraded" | awk '{print $1}')
    elif command -v dnf &>/dev/null; then
        count=$(dnf check-update -q 2>/dev/null | grep -E '^[a-zA-Z0-9_\-]+' | wc -l)
    elif command -v yum &>/dev/null; then
        count=$(yum check-update -q 2>/dev/null | grep -E '^[a-zA-Z0-9_\-]+' | wc -l)
    fi
    [ -z "$count" ] && count=0
    echo "$count" > "$cache_file" 2>/dev/null
    echo "$count"
}

# ── System Info Gathering ───────────────────────────────────────────────────
get_system_info() {
    # OS
    local os_pretty=""
    if [ -f "/etc/os-release" ]; then
        os_pretty=$(grep "^PRETTY_NAME=" /etc/os-release | cut -d= -f2 | tr -d '"')
    fi
    [ -z "$os_pretty" ] && os_pretty="Linux $(uname -r)"

    # CPU
    local cpu_model=$(lscpu 2>/dev/null | grep "Model name:" | sed 's/Model name:\s*//' | head -n 1)
    if [ -z "$cpu_model" ]; then
        cpu_model=$(grep -m1 "model name" /proc/cpuinfo | cut -d: -f2 | sed 's/^\s*//')
    fi
    [ -z "$cpu_model" ] && cpu_model="Generic Linux CPU"

    # RAM
    local mem_total_kb=$(grep MemTotal /proc/meminfo | awk '{print $2}')
    local mem_gb=$(echo "scale=1; $mem_total_kb / 1024 / 1024" | bc 2>/dev/null)
    if [ -z "$mem_gb" ]; then
        mem_gb=$(awk '/MemTotal/ {printf "%.1f", $2/1024/1024}' /proc/meminfo)
    fi
    local ram_label="${mem_gb} GB"

    # Disk
    local disk_label=$(df -h / | awk 'NR==2 {print $2}')
    [ -z "$disk_label" ] && disk_label="0 GB"

    # Manufacturer / Model
    local manufacturer=$(cat /sys/class/dmi/id/sys_vendor 2>/dev/null | tr -d '\r\n')
    [ -z "$manufacturer" ] && manufacturer=$(dmidecode -s system-manufacturer 2>/dev/null | tr -d '\r\n')
    [ -z "$manufacturer" ] && manufacturer="Generic"

    local model=$(cat /sys/class/dmi/id/product_name 2>/dev/null | tr -d '\r\n')
    [ -z "$model" ] && model=$(dmidecode -s system-product-name 2>/dev/null | tr -d '\r\n')
    [ -z "$model" ] && model="Linux Machine"

    local hostname=$(hostname 2>/dev/null)
    [ -z "$hostname" ] && hostname=$(uname -n 2>/dev/null)
    [ -z "$hostname" ] && hostname="Linux-Server"

    local ip=$(get_ip_address)
    local mac=$(get_network_info)
    
    local serial=$(get_serial_number)
    [ -z "$serial" ] && serial="System-Fallback"

    local updates_count=$(get_linux_updates)
    local updates_key="available_updates"
    if [ "$IS_TR" = "true" ]; then
        updates_key="mevcut_guncellemeler"
    fi

    # Sanitize variables to ensure they do not break JSON syntax (remove quotes, backslashes, and control characters like tabs/newlines)
    os_pretty=$(echo "$os_pretty" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')
    cpu_model=$(echo "$cpu_model" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')
    ram_label=$(echo "$ram_label" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')
    mem_gb=$(echo "$mem_gb" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')
    disk_label=$(echo "$disk_label" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')
    manufacturer=$(echo "$manufacturer" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')
    model=$(echo "$model" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')
    hostname=$(echo "$hostname" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')
    ip=$(echo "$ip" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')
    mac=$(echo "$mac" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')
    serial=$(echo "$serial" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')
    updates_count=$(echo "$updates_count" | tr -d '"' | tr -d '\\' | tr -d '[:cntrl:]')

    # Output JSON payload
    cat <<EOF
{
  "name": "$hostname",
  "asset_tag": "$serial",
  "serial_no": "$serial",
  "ip_address": "$ip",
  "mac_address": "$mac",
  "type": "Server",
  "specs": {
    "os": "$os_pretty",
    "cpu": "$cpu_model",
    "ram": "$ram_label",
    "ram_gb": "$mem_gb",
    "disk": "$disk_label",
    "disk_c_total_gb": "$disk_label",
    "gpu": "Integrated Graphics",
    "monitor": "Headless Server",
    "mainboard": "$manufacturer $model",
    "$updates_key": "$updates_count"
  }
}
EOF
}

# ── Sync To Server ──────────────────────────────────────────────────────────
sync_to_server() {
    local jwt=$(get_valid_token)
    if [ -z "$jwt" ]; then
        log_msg "HATA: Gecerli token alinamadi. / ERROR: Could not get valid token."
        return 1
    fi

    local payload_file="/tmp/eaprimus_payload.json"
    get_system_info > "$payload_file"
    
    local response=$(curl -s -k -X POST "$API_URL/assets" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $jwt" \
        -d @"$payload_file")

    if echo "$response" | grep -q '"success":true'; then
        log_msg "Senkronizasyon basarili. / Synchronization successful."
        cp -f "$payload_file" "$CACHE_FILE" 2>/dev/null
        rm -f "$payload_file"
        return 0
    else
        log_msg "HATA: Senkronizasyon basarisiz. / ERROR: Sync failed."
        log_msg "Sunucu yaniti / Server response: $response"
        rm -f "$payload_file"
        return 1
    fi
}

# ── Service Mode Loop ───────────────────────────────────────────────────────
run_service_mode() {
    log_msg "Ajan arka plan servis modu baslatildi. / Background service mode started."
    while true; do
        sync_to_server
        
        # Listen for trigger requests (long-polling)
        local jwt=$(get_valid_token)
        if [ -n "$jwt" ]; then
            local my_mac=$(get_network_info)
            local my_name=$(hostname)
            
            # Wait up to 55 seconds for a sync request from panel
            local wait_resp=$(curl -s -k -m 60 -X GET "$API_URL/agent-wait?mac=$my_mac&name=$my_name" \
                -H "Authorization: Bearer $jwt")
            
            if echo "$wait_resp" | grep -q '"sync_requested":true'; then
                log_msg "Panelden tetikleme sinyali alindi! / Trigger signal received from panel!"
                sync_to_server
            fi
        fi
        sleep 5
    done
}

# ── Install Cron Job ────────────────────────────────────────────────────────
install_cron_job() {
    local self_path=$(readlink -f "$0")
    local cron_file="/etc/cron.d/eaprimus-agent"
    
    # Store script in standard bin location
    cp -f "$self_path" "/usr/local/bin/eaprimus-agent.sh"
    chmod 755 "/usr/local/bin/eaprimus-agent.sh"

    # Create crontab entry for persistent service boot or hourly checks
    echo "@reboot root /usr/local/bin/eaprimus-agent.sh --service >> $LOG_FILE 2>&1" > "$cron_file"
    echo "*/15 * * * * root /usr/local/bin/eaprimus-agent.sh --cron >> $LOG_FILE 2>&1" >> "$cron_file"
    chmod 644 "$cron_file"
    
    # Also run in background now
    nohup /usr/local/bin/eaprimus-agent.sh --service >> "$LOG_FILE" 2>&1 &
    
    log_msg "Linux Ajan servisi kuruldu ve arka planda baslatildi. / Linux Agent service configured and started in background."
}

# ─────────────────────────────────────────────────────────────────────────────
#  Execution Flow
# ─────────────────────────────────────────────────────────────────────────────
register_agent
if [ $? -ne 0 ]; then
    exit 1
fi

if [ "$1" = "--service" ]; then
    # Kill any other existing background services to avoid duplicate loops
    local current_pid=$$
    for pid in $(pgrep -f "eaprimus-agent.sh --service" 2>/dev/null); do
        if [ -n "$pid" ] && [ "$pid" -ne "$current_pid" ]; then
            kill -9 "$pid" 2>/dev/null
        fi
    done
    run_service_mode
elif [ "$1" = "--cron" ]; then
    # Standard cron execution: just check in once
    sync_to_server
else
    # First install
    sync_to_server
    install_cron_job
fi
BASH_SCRIPT;

// Perform variable injections
$script = str_replace([
    '{{API_URL}}',
    '{{AUTO_REGISTER}}',
    '{{API_KEY}}',
    '{{API_SECRET}}',
    '{{ACTIVATION_TOKEN}}',
    '{{IS_TR}}'
], [
    $baseUrl . '/api/v1',
    (s('api_agent_auto_register') === '1' && !$isPersonal) ? 'true' : 'false',
    $apiKey,
    $apiSecret,
    $activationToken,
    $isTr ? 'true' : 'false'
], $script);

$script = str_replace("\r", "", $script);

header('Content-Description: File Transfer');
header('Content-Type: application/x-sh');
header('Content-Disposition: attachment; filename="Eaprimus-Ajan.sh"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . strlen($script));
echo $script;
exit;
