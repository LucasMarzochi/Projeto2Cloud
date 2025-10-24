# -*- mode: ruby -*-
# vi: set ft=ruby :

Vagrant.configure("2") do |config|
  # ✅ Ubuntu 22.04 (Jammy) – Bento
  config.vm.box = "bento/ubuntu-22.04"

  # Porta do Apache → Host:8080 → Guest:80
  config.vm.network "forwarded_port", guest: 80, host: 8080, auto_correct: true

  # Sincronize apenas o código-fonte; logs ficam nativos na VM
  config.vm.synced_folder "src/", "/var/www/html", create: true
  # (não sincronizar /var/cloudmgr/logs)

  # Provider VMware (Fusion/Workstation)
  config.vm.provider "vmware_desktop" do |v|
    v.gui = true
    v.vmx["memsize"]  = "4096"
    v.vmx["numvcpus"] = "2"
    v.vmx["cpuid.coresPerSocket"] = "2"
  end

  # Provisionamento idempotente
  config.vm.provision "shell", inline: <<'SHELL'
set -e
export DEBIAN_FRONTEND=noninteractive

apt-get update
# PHP no Apache + ping + util-linux (unshare)
apt-get install -y apache2 php php-cli php-mysql php-json php-mbstring libapache2-mod-php \
                   mariadb-server mariadb-client unzip curl jq util-linux iputils-ping

# Em 22.04 já costuma vir cgroup v2; deixamos o ajuste condicional
if ! grep -q "systemd.unified_cgroup_hierarchy=1" /etc/default/grub; then
  sed -i 's/^GRUB_CMDLINE_LINUX="/GRUB_CMDLINE_LINUX="systemd.unified_cgroup_hierarchy=1 /' /etc/default/grub
  update-grub || true
fi

# Estrutura do app
mkdir -p /var/www/html/api /var/www/html/assets
# Logs nativos (não sincronizados)
mkdir -p /var/cloudmgr/logs
chown -R www-data:www-data /var/cloudmgr/logs

# Apache + PHP
a2enmod rewrite php*
cat >/etc/apache2/sites-available/000-default.conf <<'EOF'
<VirtualHost *:80>
    DocumentRoot /var/www/html
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
        Options Indexes FollowSymLinks
        DirectoryIndex index.php index.html
    </Directory>
    ErrorLog  /var/log/apache2/error.log
    CustomLog /var/log/apache2/access.log combined
</VirtualHost>
EOF
systemctl restart apache2

# Banco e schema
mysql -uroot <<'EOF'
CREATE DATABASE IF NOT EXISTS cloudmgr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'cloud'@'localhost' IDENTIFIED BY 'cloud';
GRANT ALL PRIVILEGES ON cloudmgr.* TO 'cloud'@'localhost';
FLUSH PRIVILEGES;
EOF

mysql -uroot cloudmgr <<'EOF'
CREATE TABLE IF NOT EXISTS ambientes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80) NOT NULL,
  command    TEXT NOT NULL,
  cpu_pct    INT NULL,
  mem_mb     INT NULL,
  io_class   VARCHAR(8) NULL,
  unit_name  VARCHAR(120) NULL,
  pid        INT NULL,
  status     ENUM('running','finished','error') DEFAULT 'running',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_created ON ambientes (created_at);
EOF

# Sudo controlado para www-data (caminhos corretos no 22.04)
cat >/etc/sudoers.d/www-data-cloudmgr <<'EOF'
www-data ALL=(root) NOPASSWD:/usr/bin/systemd-run, /usr/bin/systemctl, /usr/bin/ionice, /usr/bin/ps, /usr/bin/tee, /usr/bin/hostname
EOF
chmod 440 /etc/sudoers.d/www-data-cloudmgr

SHELL
end
