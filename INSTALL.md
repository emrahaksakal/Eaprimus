# Installation Guide / Kurulum Kılavuzu

This document guides you through the installation of the Eaprimus system on both Linux (Ubuntu, AlmaLinux, RedHat) and Windows environments.

Bu belge, Eaprimus sisteminin hem Linux (Ubuntu, AlmaLinux, RedHat) hem de Windows ortamlarında kurulumu için size rehberlik eder.

---

[Türkçe Kurulum Kılavuzu İçin Tıklayın / Click Here for Turkish Guide](#tr-turkce-kurulum)

---

## EN: English Installation Guide

Eaprimus can be installed automatically via a single command or manually step-by-step.

### Option 1: Automatic Installation (Recommended for Linux VPS/VDS)
If you are using a clean AlmaLinux, Rocky Linux, CentOS, Ubuntu, or Debian server, you can install the entire system (including Web Server, Database, PHP, Firewall, SELinux, and Cron) with a single command. Connect to your server via SSH and run:

```bash
curl -sL https://raw.githubusercontent.com/emrahaksakal/Eaprimus/main/server-install.sh | sudo bash
```
*(If you use this method, you can skip the rest of this manual guide and proceed directly to your browser for the Web Wizard.)*

---

### Option 2: Manual Installation (For cPanel, Plesk, Shared Hosting, Windows)

#### 1. Prerequisites (LAMP Stack)

#### Option A: Ubuntu (22.04 / 24.04)
Run the following commands to install Apache, PHP 8.2, and extensions:
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install software-properties-common -y
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install php8.2 php8.2-mysql php8.2-gd php8.2-curl php8.2-mbstring php8.2-imap php8.2-ldap php8.2-xml libapache2-mod-php8.2 -y
sudo apt install mysql-server -y

# Configure Firewall (Allow HTTP/HTTPS traffic if UFW is enabled)
sudo ufw allow "Apache Full"
```

**Create a dedicated database user for Eaprimus installation:**
1. Enter the MySQL console:
```bash
sudo mysql -u root
```
2. Inside the MySQL console, run these queries (replace `StrongPasswordHere` with your secure password):
```sql
CREATE USER IF NOT EXISTS 'eaprimus'@'localhost' IDENTIFIED BY 'StrongPasswordHere';
GRANT ALL PRIVILEGES ON *.* TO 'eaprimus'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
exit;
```

#### Option B: AlmaLinux / RedHat (8 / 9)
Run the following commands to install Apache, PHP 8.2, and extensions:
```bash
sudo dnf update -y
sudo dnf install dnf-plugins-core -y
sudo dnf config-manager --set-enabled crb
sudo dnf install https://dl.fedoraproject.org/pub/epel/epel-release-latest-9.noarch.rpm -y
sudo dnf install https://rpms.remirepo.net/enterprise/remi-release-9.rpm -y
sudo dnf clean all && sudo dnf makecache -y
sudo dnf module reset php -y
sudo dnf module enable php:remi-8.2 -y
sudo dnf install httpd php php-mysqlnd php-gd php-curl php-mbstring php-imap php-ldap php-xml -y
sudo dnf install mariadb-server -y
sudo systemctl enable --now httpd
sudo systemctl enable --now mariadb

# Configure Firewall (Allow HTTP/HTTPS traffic if Firewalld is active)
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

**Create a dedicated database user for Eaprimus installation:**
1. Enter the MySQL console:
```bash
sudo mysql -u root
```
2. Inside the MySQL console, run these queries (replace `StrongPasswordHere` with your secure password):
```sql
CREATE USER IF NOT EXISTS 'eaprimus'@'localhost' IDENTIFIED BY 'StrongPasswordHere';
GRANT ALL PRIVILEGES ON *.* TO 'eaprimus'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
exit;
```

---

### 2. Download and Extract Eaprimus

You can install Eaprimus in any directory you prefer (e.g., `/var/www/html/eaprimus` or `/opt/eaprimus`).

```bash
# Example: Creating directory in /var/www/html (or /opt)
sudo mkdir -p /var/www/html/eaprimus
cd /var/www/html/eaprimus

# Download the latest release as a tar.gz archive and extract it
sudo curl -sL https://github.com/emrahaksakal/Eaprimus/archive/refs/heads/main.tar.gz | sudo tar -xz --strip-components=1
```
*(If you choose a custom location like `/opt/eaprimus`, make sure your Apache/Nginx DocumentRoot is configured properly.)*

---

### 3. Directory Permissions (Linux)

> [!NOTE]
> **Windows Local Environments (XAMPP / AMPPS / Laragon):** You do **NOT** need to configure directory permissions. These commands are only for Linux production or staging environments.
>
> **755 vs 775 Permissions on Linux:**
> - If the web server user (`www-data` or `apache`) is the **owner** of the files, you can use **`755`** (more secure).
> - If you deploy files using a different user account (e.g. `ubuntu` or `ftp-user`) and the web server is in the group, use **`775`** so the web server can write sessions, logs, and attachments.

You must grant the web server user (`www-data` on Ubuntu, `apache` on AlmaLinux/RedHat) write permissions to the storage directories. Since Git does not track empty folders, create them first:

#### On Ubuntu (Apache):
```bash
sudo mkdir -p /var/www/html/eaprimus/app/config /var/www/html/eaprimus/app/sessions /var/www/html/eaprimus/app/logs /var/www/html/eaprimus/public/uploads /var/www/html/eaprimus/app/storage/attachments /var/www/html/eaprimus/app/storage/signatures
sudo chown -R www-data:www-data /var/www/html/eaprimus
sudo chmod -R 775 /var/www/html/eaprimus/app/config
sudo chmod -R 775 /var/www/html/eaprimus/app/sessions
sudo chmod -R 775 /var/www/html/eaprimus/app/logs
sudo chmod -R 775 /var/www/html/eaprimus/public
sudo chmod -R 775 /var/www/html/eaprimus/public/uploads
sudo chmod -R 775 /var/www/html/eaprimus/app/storage/attachments
sudo chmod -R 775 /var/www/html/eaprimus/app/storage/signatures
```

#### On AlmaLinux / RedHat (Apache):
```bash
sudo mkdir -p /var/www/html/eaprimus/app/config /var/www/html/eaprimus/app/sessions /var/www/html/eaprimus/app/logs /var/www/html/eaprimus/public/uploads /var/www/html/eaprimus/app/storage/attachments /var/www/html/eaprimus/app/storage/signatures
sudo chown -R apache:apache /var/www/html/eaprimus
sudo chmod -R 775 /var/www/html/eaprimus/app/config
sudo chmod -R 775 /var/www/html/eaprimus/app/sessions
sudo chmod -R 775 /var/www/html/eaprimus/app/logs
sudo chmod -R 775 /var/www/html/eaprimus/public
sudo chmod -R 775 /var/www/html/eaprimus/public/uploads
sudo chmod -R 775 /var/www/html/eaprimus/app/storage/attachments
sudo chmod -R 775 /var/www/html/eaprimus/app/storage/signatures
```

*(For AlmaLinux/RedHat, if SELinux is active, run:)*
```bash
sudo chcon -t httpd_sys_rw_content_t /var/www/html/eaprimus/app/config -R
sudo chcon -t httpd_sys_rw_content_t /var/www/html/eaprimus/app/sessions -R
sudo chcon -t httpd_sys_rw_content_t /var/www/html/eaprimus/app/logs -R
sudo chcon -t httpd_sys_rw_content_t /var/www/html/eaprimus/public -R
sudo chcon -t httpd_sys_rw_content_t /var/www/html/eaprimus/app/storage -R
sudo setsebool -P httpd_can_network_connect 1
```

---

### 3. Enable Apache URL Rewrite (.htaccess)
For Eaprimus to route pages correctly (e.g. redirecting to `/login` or `/install`), you must enable `.htaccess` rewrite rules in your Apache configuration:

#### On Ubuntu (Apache):
Ensure `mod_rewrite` is enabled and restart Apache:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### On AlmaLinux / RedHat (Apache):
By default, Apache ignores `.htaccess` overrides. Enable them by setting `AllowOverride All` for the web root directory in `/etc/httpd/conf/httpd.conf` and restart:
```bash
# Automatically switch AllowOverride None to AllowOverride All in the web root config block
sudo sed -i '/<Directory "\/var\/www\/html">/,/<\/Directory>/ {s/AllowOverride None/AllowOverride All/}' /etc/httpd/conf/httpd.conf
sudo systemctl restart httpd
```

---

### 4. Run the Web Installer
1. Open your browser and go to your server address (e.g., `http://your-server-ip/eaprimus/` or directly `http://your-server-ip/eaprimus/install/`).
2. The visual web installer wizard (`/install/index.php`) will load automatically.
3. Follow the steps on the screen to enter database credentials, company details, and configure the administrator account.

---

### 5. Background Tasks Setup (Cron)
The web installation wizard will attempt to configure this automatically. However, if you receive a warning on Step 6 that automatic installation failed, you must add it manually:

- **Windows:** Right-click `public/install/setup_windows_cron.bat` and click **"Run as Administrator"**.
- **Linux:** Open crontab using `sudo crontab -u www-data -e` (Ubuntu) or `sudo crontab -u apache -e` (AlmaLinux/RedHat) and append:
  ```bash
  * * * * * php /var/www/html/eaprimus/app/cron/worker.php > /dev/null 2>&1
  ```

---
---

<a name="tr-turkce-kurulum"></a>

## TR: Türkçe Kurulum Kılavuzu

Eaprimus'u tek bir komutla otomatik olarak veya adım adım manuel olarak kurabilirsiniz.

### Seçenek 1: Otomatik Kurulum (Linux VPS/VDS için Önerilir)
Eğer temiz bir AlmaLinux, Rocky Linux, CentOS, Ubuntu veya Debian sunucunuz varsa, tek bir komutla tüm sistemi (Web Sunucusu, Veritabanı, PHP, Güvenlik Duvarı, SELinux ve Cron dahil) otomatik kurabilirsiniz. SSH ile sunucunuza bağlanın ve şu komutu çalıştırın:

```bash
curl -sL https://raw.githubusercontent.com/emrahaksakal/Eaprimus/main/server-install.sh | sudo bash
```
*(Eğer bu yöntemi kullanırsanız, aşağıdaki manuel kurulum adımlarının hiçbirini yapmanıza gerek kalmaz. Doğrudan tarayıcınızı açıp kurulum sihirbazına geçebilirsiniz.)*

---

### Seçenek 2: Manuel Kurulum (cPanel, Plesk, Paylaşımlı Hosting, Windows İçin)

#### 1. Gereksinimler (LAMP Stack)

#### Seçenek A: Ubuntu (22.04 / 24.04)
Aşağıdaki komutları çalıştırarak Apache, PHP 8.2 ve gerekli eklentileri yükleyin:
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install software-properties-common -y
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install php8.2 php8.2-mysql php8.2-gd php8.2-curl php8.2-mbstring php8.2-imap php8.2-ldap php8.2-xml libapache2-mod-php8.2 -y
sudo apt install mysql-server -y

# Güvenlik Duvarı Yapılandırması (UFW aktif ise web trafiğine izin verin)
sudo ufw allow "Apache Full"
```

**Eaprimus kurulumu için veritabanı kullanıcısını oluşturun:**
1. Veritabanı konsoluna giriş yapın:
```bash
sudo mysql -u root
```
2. MySQL konsolu içinde şu sorguları çalıştırın (`GucluSifrenizBuraya` yazan yere kendi güvenli şifrenizi yazın):
```sql
CREATE USER IF NOT EXISTS 'eaprimus'@'localhost' IDENTIFIED BY 'GucluSifrenizBuraya';
GRANT ALL PRIVILEGES ON *.* TO 'eaprimus'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
exit;
```

#### Seçenek B: AlmaLinux / RedHat (8 / 9)
Aşağıdaki komutları çalıştırarak Apache, PHP 8.2 ve gerekli eklentileri yükleyin:
```bash
sudo dnf update -y
sudo dnf install dnf-plugins-core -y
sudo dnf config-manager --set-enabled crb
sudo dnf install https://dl.fedoraproject.org/pub/epel/epel-release-latest-9.noarch.rpm -y
sudo dnf install https://rpms.remirepo.net/enterprise/remi-release-9.rpm -y
sudo dnf clean all && sudo dnf makecache -y
sudo dnf module reset php -y
sudo dnf module enable php:remi-8.2 -y
sudo dnf install httpd php php-mysqlnd php-gd php-curl php-mbstring php-imap php-ldap php-xml -y
sudo dnf install mariadb-server -y
sudo systemctl enable --now httpd
sudo systemctl enable --now mariadb

# Güvenlik Duvarı Yapılandırması (Firewalld aktif ise web trafiğine izin verin)
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

**Eaprimus kurulumu için veritabanı kullanıcısını oluşturun:**
1. Veritabanı konsoluna giriş yapın:
```bash
sudo mysql -u root
```
2. MySQL konsolu içinde şu sorguları çalıştırın (`GucluSifrenizBuraya` yazan yere kendi güvenli şifrenizi yazın):
```sql
CREATE USER IF NOT EXISTS 'eaprimus'@'localhost' IDENTIFIED BY 'GucluSifrenizBuraya';
GRANT ALL PRIVILEGES ON *.* TO 'eaprimus'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
exit;
```

---

### 2. Eaprimus'u İndirme ve Çıkarma

Eaprimus'u istediğiniz herhangi bir dizine kurabilirsiniz (Örn: `/var/www/html/eaprimus` veya `/opt/eaprimus`).

```bash
# Örnek: Kurulum dizinini oluşturma (Örn: /var/www/html veya /opt)
sudo mkdir -p /var/www/html/eaprimus
cd /var/www/html/eaprimus

# En güncel sürümü tar.gz olarak indirip çıkarma
sudo curl -sL https://github.com/emrahaksakal/Eaprimus/archive/refs/heads/main.tar.gz | sudo tar -xz --strip-components=1
```
*(Eğer `/opt/eaprimus` gibi özel bir dizin kullanırsanız, Apache/Nginx ayarlarınızdaki kök dizini (DocumentRoot) buna göre ayarladığınızdan emin olun.)*

---

### 3. Klasör İzinleri (Linux)

> [!NOTE]
> **Windows Yerel Ortamları (XAMPP / AMPPS / Laragon):** Herhangi bir klasör izni veya chmod ayarı yapmanıza **gerek yoktur**. Bu komutlar sadece Linux sunucular (production/staging) içindir.
>
> **Linux Üzerinde 755 ve 775 İzinleri:**
> - Eğer dosyaların **sahibi (owner)** doğrudan web sunucusu kullanıcısı (`www-data` veya `apache`) ise, daha güvenli olan **`755`** izinlerini kullanabilirsiniz.
> - Eğer dosyaları başka bir kullanıcıyla (örn. `ubuntu` veya `ftp-user`) yüklüyorsanız ve web sunucusu grup üyesiyse, web sunucusunun oturum, log ve ekleri yazabilmesi için **`775`** izinlerini vermelisiniz.

Web sunucusu kullanıcısına (`Ubuntu` için `www-data`, `AlmaLinux/RedHat` için `apache`) yazma yetkisi vermeniz gerekir. Git boş klasörleri takip etmediğinden, öncelikle eksik klasörleri oluşturun:

#### Ubuntu (Apache):
```bash
sudo mkdir -p /var/www/html/eaprimus/app/config /var/www/html/eaprimus/app/sessions /var/www/html/eaprimus/app/logs /var/www/html/eaprimus/public/uploads /var/www/html/eaprimus/app/storage/attachments /var/www/html/eaprimus/app/storage/signatures
sudo chown -R www-data:www-data /var/www/html/eaprimus
sudo chmod -R 775 /var/www/html/eaprimus/app/config
sudo chmod -R 775 /var/www/html/eaprimus/app/sessions
sudo chmod -R 775 /var/www/html/eaprimus/app/logs
sudo chmod -R 775 /var/www/html/eaprimus/public
sudo chmod -R 775 /var/www/html/eaprimus/public/uploads
sudo chmod -R 775 /var/www/html/eaprimus/app/storage/attachments
sudo chmod -R 775 /var/www/html/eaprimus/app/storage/signatures
```

#### AlmaLinux / RedHat (Apache):
```bash
sudo mkdir -p /var/www/html/eaprimus/app/config /var/www/html/eaprimus/app/sessions /var/www/html/eaprimus/app/logs /var/www/html/eaprimus/public/uploads /var/www/html/eaprimus/app/storage/attachments /var/www/html/eaprimus/app/storage/signatures
sudo chown -R apache:apache /var/www/html/eaprimus
sudo chmod -R 775 /var/www/html/eaprimus/app/config
sudo chmod -R 775 /var/www/html/eaprimus/app/sessions
sudo chmod -R 775 /var/www/html/eaprimus/app/logs
sudo chmod -R 775 /var/www/html/eaprimus/public
sudo chmod -R 775 /var/www/html/eaprimus/public/uploads
sudo chmod -R 775 /var/www/html/eaprimus/app/storage/attachments
sudo chmod -R 775 /var/www/html/eaprimus/app/storage/signatures
```

*(AlmaLinux/RedHat üzerinde eğer SELinux aktif ise şu komutları çalıştırın:)*
```bash
sudo chcon -t httpd_sys_rw_content_t /var/www/html/eaprimus/app/config -R
sudo chcon -t httpd_sys_rw_content_t /var/www/html/eaprimus/app/sessions -R
sudo chcon -t httpd_sys_rw_content_t /var/www/html/eaprimus/app/logs -R
sudo chcon -t httpd_sys_rw_content_t /var/www/html/eaprimus/public -R
sudo chcon -t httpd_sys_rw_content_t /var/www/html/eaprimus/app/storage -R
sudo setsebool -P httpd_can_network_connect 1
```

---

### 3. Apache URL Yönlendirmeyi Etkinleştirme (.htaccess)
Eaprimus'un sayfaları doğru yönlendirebilmesi için (örneğin `/giris` veya `/install` yönlendirmeleri), Apache konfigürasyonunda `.htaccess` izinlerinin açık olması gerekir:

#### Ubuntu (Apache):
`mod_rewrite` modülünü aktif edin ve Apache'yi yeniden başlatın:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### AlmaLinux / RedHat (Apache):
Varsayılan olarak Apache `.htaccess` dosyalarını okumaz. Bunu etkinleştirmek için `/etc/httpd/conf/httpd.conf` dosyasında web dizini için `AllowOverride All` yapın ve servisi yeniden başlatın:
```bash
# Web root ayar bloğundaki AllowOverride None satırını AllowOverride All ile değiştirin
sudo sed -i '/<Directory "\/var\/www\/html">/,/<\/Directory>/ {s/AllowOverride None/AllowOverride All/}' /etc/httpd/conf/httpd.conf
sudo systemctl restart httpd
```

---

### 4. Görsel Kurulum Sihirbazını Çalıştırma
1. Tarayıcınızı açın ve sunucunuzun adresine gidin (Örn: `http://192.168.3.99/eaprimus/` veya doğrudan `http://192.168.3.99/eaprimus/install/`).
2. Görsel kurulum ekranı (`/install/index.php`) otomatik olarak açılacaktır.
3. Ekranda gösterilen adımları takip ederek veritabanı şifresini, şirket bilgilerini ve yönetici (admin) hesabını kolayca oluşturun.

---

### 5. Arka Plan Görevleri Yapılandırması (Cron)
Sistemin arka planda mail okuyabilmesi ve SLA sürelerini kontrol edebilmesi için bir Cron görevine ihtiyacı vardır. Kurulum sihirbazı bunu otomatik kurmaya çalışacaktır. **Ancak 6. adımda "Otomatik kurulum yapılamadı" uyarısı alırsanız**, görevi elle eklemeniz gerekir:

- **Windows:** `public/install/setup_windows_cron.bat` dosyasına sağ tıklayıp **"Yönetici Olarak Çalıştır"** deyin.
- **Linux:** Zamanlayıcıyı açmak için `sudo crontab -u www-data -e` (Ubuntu) veya `sudo crontab -u apache -e` (AlmaLinux/RedHat) komutunu girip dosyanın en altına şu satırı ekleyin:
  ```bash
  * * * * * php /var/www/html/eaprimus/app/cron/worker.php > /dev/null 2>&1
  ```

---

### 6. Sık Karşılaşılan Sorunlar ve Çözümleri (Troubleshooting)

#### Sorun: Nginx / Apache Yönlendirmeleri ve IP Erişimi (`http://192.168.3.99`)
- **Nginx & Apache Çalışma Yapısı:**
  - **Apache Kullanıcıları:** Apache `.htaccess` dosyalarını otomatik olarak okur. `/var/www/html` DocumentRoot olarak ayarlandığında `http://192.168.3.99/` varsayılan karşılama ekranını gösterirken, `http://192.168.3.99/eaprimus` doğrudan Eaprimus uygulamasına yönlenir.
  - **Nginx Kullanıcıları:** Nginx `.htaccess` dosyalarını **okumaz**, yönlendirmeler `/etc/nginx/conf.d/eaprimus.conf` dosyası üzerinden yapılandırılır.

- **Nginx Çoklu Uygulama (Multi-App) & Alt Dizin Kurulumu (AlmaLinux / CentOS / RHEL):**
  Sunucunuzda birden fazla uygulama (`snipeIT`, `gevaxTV`, `e-bordro` vb.) ve kurumsal bir ana açılış sayfası (`/var/www/index.php`) bulunuyorsa, Eaprimus uygulamasını ana sayfayı bozmadan tanımlamak için:
  1. Dosyayı `/etc/nginx/default.d/eaprimus.conf` konumuna kopyalayın:
     ```bash
     cd /var/www/eaprimus # (veya /var/www/html/eaprimus)
     sudo cp nginx.conf.example /etc/nginx/default.d/eaprimus.conf
     sudo systemctl restart nginx
     ```
  2. Artık:
     - `http://192.168.3.99/` -> Kurumsal Açılış Ekranınız ("Gürsoylar Endüstriyel...")
     - `http://192.168.3.99/snipeIT` -> SnipeIT Uygulaması
     - `http://192.168.3.99/eaprimus` -> Eaprimus Uygulaması (`/eaprimus/anasayfa`) olarak sorunsuz çalışacaktır.
