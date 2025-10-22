# Vagrantfile — VMware Fusion (vmware_desktop) + bento/ubuntu-22.04
# Stack direto na VM: MySQL, FastAPI (8000), Streamlit (8501)
# Orquestração de execuções por FastAPI: systemd-run (cgroups) + unshare (namespaces)

Vagrant.configure("2") do |config|
    config.vm.box = "bento/ubuntu-22.04"
    config.vm.hostname = "proj2-vm"
  
    # VMware Fusion provider
    config.vm.provider :vmware_desktop do |vmw|
      vmw.vmx["displayName"] = "proj2-vm"
      vmw.vmx["numvcpus"]    = "2"
      vmw.vmx["memsize"]     = "4096"
      vmw.gui = true
    end
  
    # Só o necessário para acesso pelo host (loopback)
    config.vm.network "forwarded_port", guest: 8000, host: 8000, host_ip: "127.0.0.1", auto_correct: true
    config.vm.network "forwarded_port", guest: 8501, host: 8501, host_ip: "127.0.0.1", auto_correct: true
  
    # Pastas do projeto (no host) sincronizadas na VM
    config.vm.synced_folder "./backend",  "/srv/app/backend",  create: true
    config.vm.synced_folder "./frontend", "/srv/app/frontend", create: true
  
    # Provisionamento principal
    config.vm.provision "shell", privileged: true, inline: <<-'SHELL'
  set -euo pipefail
  export DEBIAN_FRONTEND=noninteractive
  
  echo "[1/8] Atualizando pacotes..."
  apt-get update -y
  apt-get upgrade -y
  
  echo "[2/8] Instalando pacotes do sistema..."
  apt-get install -y python3 python3-venv python3-pip build-essential \
                     mysql-server pkg-config ca-certificates curl util-linux
  
  echo "[3/8] Configurando MySQL (bind local e DB/usuário)..."
  MYSQL_CNF="/etc/mysql/mysql.conf.d/mysqld.cnf"
  if grep -qE '^[# ]*bind-address' "$MYSQL_CNF"; then
    sed -i 's/^[# ]*bind-address.*/bind-address = 127.0.0.1/' "$MYSQL_CNF"
  else
    printf "\nbind-address = 127.0.0.1\n" >> "$MYSQL_CNF"
  fi
  systemctl enable mysql
  systemctl restart mysql
  
  echo " - aguardando MySQL responder..."
  until mysqladmin ping --silent; do sleep 1; done
  
  DB_NAME="appdb"
  DB_USER="appuser"
  DB_PASS="appsecret"
  mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -u root -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';"
  mysql -u root -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
  mysql -u root -e "FLUSH PRIVILEGES;"
  
  echo "[4/8] Pré-requisitos para orquestração (jobs)..."
  install -d -m 755 /var/run/app/jobs
  chown -R vagrant:vagrant /var/run/app
  # Permissões para o serviço do FastAPI executar systemd-run/systemctl sem senha (sem heredoc):
  printf '%s\n' \
  'vagrant ALL=(root) NOPASSWD: /usr/bin/systemd-run, /bin/systemctl, /usr/bin/systemctl' \
  | tee /etc/sudoers.d/fastapi_runner >/dev/null
  chmod 440 /etc/sudoers.d/fastapi_runner
  # Valida sintaxe do sudoers (falha cedo se houver erro)
  visudo -c -f /etc/sudoers.d/fastapi_runner || (echo "sudoers inválido" && exit 1)
  # Habilita user namespaces (se suportado)
  sysctl -w kernel.unprivileged_userns_clone=1 || true
  
  echo "[5/8] Ambientes virtuais Python (backend e frontend)..."
  install -d /opt/venvs
  python3 -m venv /opt/venvs/backend
  python3 -m venv /opt/venvs/frontend
  /opt/venvs/backend/bin/pip install --upgrade pip
  /opt/venvs/frontend/bin/pip install --upgrade pip
  
  echo "[6/8] Dependências Python..."
  /opt/venvs/backend/bin/pip  install fastapi "uvicorn[standard]" sqlalchemy PyMySQL pydantic python-dotenv
  /opt/venvs/frontend/bin/pip install streamlit requests python-dotenv
  
  echo "[7/8] Services systemd (FastAPI e Streamlit) — sem heredoc..."
  # FASTAPI
  printf "%s\n" \
  "[Unit]" \
  "Description=FastAPI backend (uvicorn)" \
  "After=network.target mysql.service" \
  "Requires=mysql.service" \
  "" \
  "[Service]" \
  "User=vagrant" \
  "Group=vagrant" \
  "WorkingDirectory=/srv/app/backend" \
  "Environment=DATABASE_URL=mysql+pymysql://appuser:appsecret@127.0.0.1:3306/appdb" \
  "Environment=AMBIENTES_ROOT=/var/run/app/ambientes" \
  "ExecStart=/opt/venvs/backend/bin/uvicorn app.main:app --host 0.0.0.0 --port 8000" \
  "Restart=on-failure" \
  "RestartSec=3s" \
  "" \
  "[Install]" \
  "WantedBy=multi-user.target" \
  > /etc/systemd/system/fastapi.service
  
  # STREAMLIT
  printf "%s\n" \
  "[Unit]" \
  "Description=Streamlit frontend" \
  "After=network.target fastapi.service" \
  "Requires=fastapi.service" \
  "" \
  "[Service]" \
  "User=vagrant" \
  "Group=vagrant" \
  "WorkingDirectory=/srv/app/frontend" \
  "ExecStart=/opt/venvs/frontend/bin/streamlit run app.py --server.address 0.0.0.0 --server.port 8501" \
  "Restart=on-failure" \
  "RestartSec=3s" \
  "" \
  "[Install]" \
  "WantedBy=multi-user.target" \
  > /etc/systemd/system/streamlit.service
  
  systemctl daemon-reload
  systemctl enable fastapi streamlit
  
  echo "[8/8] Inicializando serviços (se código já existir)..."
  [ -f /srv/app/backend/app/main.py ] && systemctl start fastapi || echo "AVISO: crie /srv/app/backend/app/main.py e depois: sudo systemctl restart fastapi"
  [ -f /srv/app/frontend/app.py ]     && systemctl start streamlit || echo "AVISO: crie /srv/app/frontend/app.py e depois: sudo systemctl restart streamlit"
  
  echo "OK! Frontend: http://localhost:8501 | Backend: http://localhost:8000/docs"
  SHELL
  end
  