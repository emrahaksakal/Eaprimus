#!/bin/bash

echo "=========================================================="
echo "         Eaprimus All-in-One Server Installer             "
echo "=========================================================="
echo "This script will install a complete Web Server, PHP, MariaDB"
echo "and configure the Eaprimus application automatically."
echo "=========================================================="
echo ""

# 1. Ask for Web Server preference
echo "Which web server do you want to install and configure?"
echo "1) Nginx (Recommended for high performance)"
echo "2) Apache"
read -p "Enter your choice (1 or 2): " web_choice < /dev/tty

if [ "$web_choice" == "1" ]; then
    WEB_SERVER="nginx"
else
    WEB_SERVER="apache"
fi

# Ask for Language
echo ""
echo "Select Panel Default Language / Panel Varsayılan Dilini Seçin:"
echo "1) Türkçe (TR)"
echo "2) English (EN)"
read -p "Enter your choice (1 or 2): " lang_choice < /dev/tty

if [ "$lang_choice" == "2" ]; then
    PANEL_LANG="en"
else
    PANEL_LANG="tr"
fi

# 2. Ask for Database Server
echo ""
echo "Which database server do you want to install?"
echo "1) MariaDB (Recommended, fast & open source)"
echo "2) MySQL"
read -p "Enter your choice (1 or 2): " db_choice < /dev/tty

if [ "$db_choice" == "2" ]; then
    DB_SERVER="mysql"
    if [ -f /etc/redhat-release ]; then
        DB_SERVER="mysqld"
    fi
    DB_PACKAGE="mysql-server"
else
    DB_SERVER="mariadb"
    DB_PACKAGE="mariadb-server"
fi

# 3. Ask for Firewall
echo ""
echo "=========================================================="
echo "          FIREWALL CONFIGURATION / GÜVENLİK DUVARI        "
echo "=========================================================="
echo "Which ports will be used? / Hangi portlar kullanılacak?"
echo " - Port 80 (HTTP)  : Required for standard web access."
echo " - Port 443 (HTTPS): Required for secure SSL/HTTPS web access."
echo ""
echo "What happens if these ports are closed / firewall is active?"
echo " - You will get a 'Connection Timed Out' (Bağlantı Zaman Aşımı) error."
echo " - The web interface will be COMPLETELY inaccessible from outside."
echo ""
echo "Do you want this script to configure the Firewall automatically?"
echo "1) Yes, configure firewall (Recommended / Önerilen)"
echo "2) No, skip firewall (If you manage it manually or on local network)"
echo "=========================================================="
read -p "Enter your choice (1 or 2): " fw_choice < /dev/tty

# 4. Ask for Database Credentials
echo ""
echo "--- Database Configuration ---"
read -p "Enter Database Name (default: eaprimus_db): " input_db_name < /dev/tty
DB_NAME=${input_db_name:-eaprimus_db}

read -p "Enter Database User (default: eaprimus): " input_db_user < /dev/tty
DB_USER=${input_db_user:-eaprimus}

while true; do
    read -p "Enter Database Password (Required): " DB_PASS < /dev/tty
    if [ -n "$DB_PASS" ]; then
        break
    else
        echo "[!] Password cannot be empty."
    fi
done

echo ""
echo "[*] Starting installation... Please wait."
echo ""

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    echo "Could not detect OS."
    exit 1
fi

# ---------------------------------------------------------
# UBUNTU / DEBIAN INSTALLATION
# ---------------------------------------------------------
if [ "$OS" == "ubuntu" ] || [ "$OS" == "debian" ]; then
    echo "[*] Detected Ubuntu/Debian."
    apt update -y
    
    echo "[*] Installing PHP 8.2 and MariaDB/MySQL..."
    apt install -y software-properties-common
    add-apt-repository ppa:ondrej/php -y
    apt update -y
    apt install -y $DB_PACKAGE php8.2 php8.2-mysql php8.2-gd php8.2-curl php8.2-mbstring php8.2-imap php8.2-ldap php8.2-xml php8.2-zip unzip curl git
    
    if [ "$WEB_SERVER" == "apache" ]; then
        echo "[*] Installing Apache..."
        apt install -y apache2 libapache2-mod-php8.2
        WEB_SERVICE="apache2"
        WEB_USER="www-data"
        a2enmod rewrite
        # Basic apache document root adjustment (optional)
    else
        echo "[*] Installing Nginx..."
        apt install -y nginx php8.2-fpm
        WEB_SERVICE="nginx"
        WEB_USER="www-data"
    fi

# ---------------------------------------------------------
# ALMALINUX / ROCKY / CENTOS INSTALLATION
# ---------------------------------------------------------
elif [[ "$OS" == "almalinux" || "$OS" == "rocky" || "$OS" == "centos" ]]; then
    echo "[*] Detected AlmaLinux/Rocky/CentOS."
    dnf install -y epel-release
    # Get major version dynamically to select correct Remi release (e.g. 8 or 9)
    OS_MAJOR=$(echo $VERSION_ID | cut -d. -f1)
    if [ -z "$OS_MAJOR" ]; then
        OS_MAJOR="9"
    fi
    dnf install -y dnf-utils http://rpms.remirepo.net/enterprise/remi-release-${OS_MAJOR}.rpm
    dnf module reset php -y
    dnf module enable php:remi-8.2 -y
    
    echo "[*] Installing PHP 8.2 and $DB_SERVER..."
    dnf install -y $DB_PACKAGE php php-fpm php-mysqlnd php-pdo php-gd php-mbstring php-xml php-curl php-zip php-intl php-imap php-ldap unzip curl git nano
    
    if [ "$WEB_SERVER" == "apache" ]; then
        echo "[*] Installing Apache..."
        dnf install -y httpd
        WEB_SERVICE="httpd"
        WEB_USER="apache"
    else
        echo "[*] Installing Nginx..."
        dnf install -y nginx
        WEB_SERVICE="nginx"
        WEB_USER="nginx"
        # Nginx requires PHP-FPM to run as nginx user on AlmaLinux
        sed -i 's/user = apache/user = nginx/g' /etc/php-fpm.d/www.conf
        sed -i 's/group = apache/group = nginx/g' /etc/php-fpm.d/www.conf
        # Set socket permissions and ownership for Nginx
        sed -i 's/;listen.owner =.*/listen.owner = nginx/g' /etc/php-fpm.d/www.conf
        sed -i 's/;listen.group =.*/listen.group = nginx/g' /etc/php-fpm.d/www.conf
        sed -i 's/listen.owner =.*/listen.owner = nginx/g' /etc/php-fpm.d/www.conf
        sed -i 's/listen.group =.*/listen.group = nginx/g' /etc/php-fpm.d/www.conf
        # Set ACL users
        if grep -q "listen.acl_users" /etc/php-fpm.d/www.conf; then
            sed -i 's/listen.acl_users =.*/listen.acl_users = nginx/g' /etc/php-fpm.d/www.conf
        else
            echo "listen.acl_users = nginx" >> /etc/php-fpm.d/www.conf
        fi
    fi
    systemctl enable --now php-fpm
else
    echo "Unsupported OS! Please install dependencies manually."
    exit 1
fi

# ---------------------------------------------------------
# FIREWALL SETUP
# ---------------------------------------------------------
if [ "$fw_choice" == "1" ]; then
    echo "[*] Configuring Firewall..."
    if [[ "$OS" == "almalinux" || "$OS" == "rocky" || "$OS" == "centos" ]]; then
        if command -v firewall-cmd >/dev/null 2>&1; then
            if systemctl is-active --quiet firewalld; then
                firewall-cmd --permanent --add-service=http
                firewall-cmd --permanent --add-service=https
                firewall-cmd --reload
            else
                echo "[*] firewalld is installed but not running. Starting and enabling firewalld..."
                systemctl enable --now firewalld
                firewall-cmd --permanent --add-service=http
                firewall-cmd --permanent --add-service=https
                firewall-cmd --reload
            fi
        else
            echo "[!] firewall-cmd not found. Skipping firewall configuration."
        fi
    else
        if command -v ufw >/dev/null 2>&1; then
            if [ "$WEB_SERVER" == "nginx" ]; then
                ufw allow 'Nginx Full'
            else
                ufw allow 'Apache Full'
            fi
        else
            echo "[!] ufw not found. Skipping firewall configuration."
        fi
    fi
fi

# ---------------------------------------------------------
# ENABLE SERVICES & DB SETUP
# ---------------------------------------------------------
echo "[*] Starting Services..."
systemctl enable --now $WEB_SERVICE
systemctl enable --now $DB_SERVER

echo "[*] Creating Database & User..."
mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -u root -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';"
mysql -u root -e "GRANT ALL PRIVILEGES ON *.* TO '${DB_USER}'@'localhost' WITH GRANT OPTION;"
mysql -u root -e "GRANT ALL PRIVILEGES ON *.* TO '${DB_USER}'@'127.0.0.1' WITH GRANT OPTION;"
mysql -u root -e "FLUSH PRIVILEGES;"

# ---------------------------------------------------------
# DOWNLOAD EAPRIMUS
# ---------------------------------------------------------
echo "[*] Downloading Eaprimus Application..."
if [ -d /var/www/html/eaprimus/.git ]; then
    echo "[*] Eaprimus repository already exists. Pulling latest updates..."
    cd /var/www/html/eaprimus
    git pull
else
    echo "[*] Cloning Eaprimus repository from GitHub..."
    rm -rf /var/www/html/eaprimus
    git clone https://github.com/emrahaksakal/Eaprimus.git /var/www/html/eaprimus
    cd /var/www/html/eaprimus
fi

echo "[*] Generating .env Configuration..."
rm -f app/config/installed.lock
mkdir -p app/config
cat <<EOF > app/config/.env
DB_HOST=127.0.0.1
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}
EAPRIMUS_KEY=Secret_$(tr -dc 'a-zA-Z0-9' < /dev/urandom | fold -w 16 | head -n 1)
INSTALL_LANG=${PANEL_LANG}
EOF

echo "[*] Importing Database Schema..."
if [ -f "public/install/database_schema.sql" ]; then
    mysql -u root "${DB_NAME}" < public/install/database_schema.sql
    echo "[+] Database imported successfully."
else
    echo "[-] WARNING: database_schema.sql not found!"
fi

# ---------------------------------------------------------
# FOLDERS, PERMISSIONS & CRON
# ---------------------------------------------------------
echo "[*] Setting up Directories & Permissions for $WEB_USER..."
# Ensure the parent web root directories are readable and traversable by the web server
chmod 755 /var/www /var/www/html

# If Nginx is used, copy the original default Nginx welcome page files if no index file exists
if [ "$WEB_SERVER" == "nginx" ]; then
    if [ ! -f /var/www/html/index.html ] && [ ! -f /var/www/html/index.php ]; then
        if [ -d /usr/share/nginx/html ]; then
            echo "[*] Copying original Nginx welcome page files to /var/www/html..."
            cp -rL /usr/share/nginx/html/* /var/www/html/ 2>/dev/null
        elif [ -d /var/www/html ]; then
            echo "<h1>Welcome to nginx!</h1>" > /var/www/html/index.html
        fi
        chown -R $WEB_USER:$WEB_USER /var/www/html
        find /var/www/html -maxdepth 1 -type f -exec chmod 644 {} \;
    fi
fi

mkdir -p app/config app/sessions app/logs public/uploads app/storage/attachments app/storage/signatures
chown -R $WEB_USER:$WEB_USER .
chmod -R 775 app/config app/sessions app/logs public public/uploads app/storage/attachments app/storage/signatures
chmod 775 .

if command -v git &> /dev/null; then
    if [ -d ".git" ]; then
        echo "[*] Configuring Git settings for smooth future updates..."
        git config core.filemode false
        git config --global --add safe.directory "$PWD"
        git config --system --add safe.directory "$PWD" 2>/dev/null || true
    fi
fi

if [[ "$OS" == "almalinux" || "$OS" == "rocky" || "$OS" == "centos" ]]; then
    echo "[*] Configuring SELinux contexts..."
    chcon -t httpd_sys_rw_content_t "$PWD/app/config" -R
    chcon -t httpd_sys_rw_content_t "$PWD/app/sessions" -R
    chcon -t httpd_sys_rw_content_t "$PWD/app/logs" -R
    chcon -t httpd_sys_rw_content_t "$PWD/public" -R
    chcon -t httpd_sys_rw_content_t "$PWD/app/storage" -R
    if [ -f /var/www/html/index.html ]; then
        chcon -t httpd_sys_content_t /var/www/html/index.html 2>/dev/null
    fi
    setsebool -P httpd_can_network_connect 1
    setsebool -P httpd_can_network_connect_db 1
fi

echo "[*] Setting up Cron Job..."
CRON_CMD="* * * * * php $PWD/app/cron/worker.php > /dev/null 2>&1"
if ! crontab -u $WEB_USER -l 2>/dev/null | grep -q "app/cron/worker.php"; then
    (crontab -u $WEB_USER -l 2>/dev/null; echo "$CRON_CMD") | crontab -u $WEB_USER -
fi

# ---------------------------------------------------------
# WEBSERVER SPECIFIC CONFIGURATIONS
# ---------------------------------------------------------
echo "[*] Applying Web Server Configurations..."
if [ "$WEB_SERVER" == "nginx" ]; then
    # Get primary IP address to use as server_name
    SERVER_IP=$(hostname -I | awk '{print $1}')
    
    # Make sure default.d directory exists
    mkdir -p /etc/nginx/default.d

    # Clean up old conflicting conf.d file if it exists
    rm -f /etc/nginx/conf.d/eaprimus.conf

    # Copy to default.d for seamless multi-app support (AlmaLinux / Debian / Ubuntu)
    cp nginx.conf.example /etc/nginx/default.d/eaprimus.conf
    
    if [[ "$OS" == "ubuntu" || "$OS" == "debian" ]]; then
        # Replace the fastcgi_pass for Ubuntu (PHP 8.2)
        sed -i 's|unix:/run/php-fpm/www.sock|unix:/run/php/php8.2-fpm.sock|g' /etc/nginx/default.d/eaprimus.conf
    fi

    # Fix alias path dynamically if repo is installed somewhere other than /var/www/html/eaprimus
    CURRENT_DIR=$(pwd)
    if [ "$CURRENT_DIR" != "/var/www/html/eaprimus" ]; then
        sed -i "s|/var/www/html/eaprimus|$CURRENT_DIR|g" /etc/nginx/default.d/eaprimus.conf
    fi

    systemctl restart nginx
    if [[ "$OS" == "almalinux" || "$OS" == "rocky" || "$OS" == "centos" ]]; then
        systemctl restart php-fpm
    fi
else
    # Enable AllowOverride for Apache .htaccess to work
    if [[ "$OS" == "almalinux" || "$OS" == "rocky" || "$OS" == "centos" ]]; then
        sed -i '/<Directory "\/var\/www\/html">/,/<\/Directory>/ {s/AllowOverride None/AllowOverride All/}' /etc/httpd/conf/httpd.conf
        systemctl restart httpd
    else
        sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ {s/AllowOverride None/AllowOverride All/}' /etc/apache2/apache2.conf
        systemctl restart apache2
    fi
fi

echo "=========================================================="
echo " SETUP COMPLETED SUCCESSFULLY! 🎉                         "
echo "=========================================================="
echo " Your server is now fully configured and running."
echo " Please open your browser and visit your Server IP:"
echo " http://YOUR_SERVER_IP/eaprimus"
echo " (Kurulum Sihirbazı / Web Installer: http://YOUR_SERVER_IP/eaprimus/install/)"
echo ""
echo " During web installation, use these database details:"
echo " Database Name: ${DB_NAME}"
echo " Database User: ${DB_USER}"
echo " Database Pass: ${DB_PASS}"
echo "=========================================================="

