# -*- mode: ruby -*-
# vi: set ft=ruby :

# Vagrantfile para o Projeto 2 usando VMware Fusion/Workstation
# - Ubuntu 22.04 (box compatível com VMware)
# - 2 vCPUs, 4 GB RAM
# - Apache (porta 80 -> host 8080)
# - MySQL (porta 3306 -> host 33060)
# - Pasta compartilhada ./app -> /srv/app
# - GUI habilitada no VMware (abre janela do console)

Vagrant.configure("2") do |config|
    # Box recomendada para VMware
    config.vm.box = "bento/ubuntu-22.04"  
    
    # Identificação
    config.vm.hostname = "proj2-vm"
  
    # Rede: portas encaminhadas + IP privado (opcional)
    config.vm.network "forwarded_port", guest: 80,   host: 8080,  auto_correct: true
    config.vm.network "forwarded_port", guest: 3306, host: 33060, auto_correct: true
    config.vm.network "private_network", ip: "10.10.0.20"
  
    # Pasta compartilhada: código do host para dentro da VM
    config.vm.synced_folder "./app", "/srv/app", create: true
  
    # Provisionamento: instala Apache, MySQL e configurações básicas
    config.vm.provision "shell", inline: <<-SHELL
      set -eux
  
      # Atualizações e utilitários
      sudo apt-get update -y
      sudo apt-get upgrade -y
      sudo apt-get install -y curl git ufw unzip
  
      # --- Apache ---
      sudo apt-get install -y apache2
      sudo a2enmod rewrite
      sudo systemctl enable --now apache2
  
      # Se houver app/public, expõe via Apache
      if [ -d /srv/app/public ]; then
        sudo rm -f /var/www/html/index.html || true
        sudo ln -s /srv/app/public /var/www/html/app || true
        sudo systemctl reload apache2
      fi
  
      # --- MySQL Server ---
      sudo DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server
      sudo systemctl enable --now mysql
  
      # Permite acesso externo (útil para testar a partir do host)
      sudo sed -i 's/^bind-address.*/bind-address = 0.0.0.0/' /etc/mysql/mysql.conf.d/mysqld.cnf
      sudo systemctl restart mysql
  
      # Cria banco/usuário de desenvolvimento
      DB_NAME="proj2db"
      DB_USER="proj2user"
      DB_PASS="proj2pass"
  
      sudo mysql -uroot <<SQL
      CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
      GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'%';
      FLUSH PRIVILEGES;
  SQL
  
      echo "Provisionamento concluído."
    SHELL
  
    # Provider: VMware Fusion/Workstation (requer plugin vagrant-vmware-desktop)
    config.vm.provider "vmware_desktop" do |vmw|
      vmw.gui = true                     # abre a janela do VMware
      vmw.vmx["memsize"]    = "4096"
      vmw.vmx["numvcpus"]   = "2"
      vmw.vmx["displayName"] = "proj2-vm"
    end
  
    # Evita re-provisionar em cada reload automaticamente
    config.vm.provision "shell", run: "never", inline: "echo 'Provisionamento manual: vagrant provision'"
  end
  