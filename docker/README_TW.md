# Docker 版安裝教學

## 軟體版本
- Docker Compose V2 以上，測試過的版本
  - 2.24.6
  - 2.24.7
- Docker Engine 18.06.0 以上，測試過的版本
  - 25.0.3 (git commit: f417435)
  - 25.0.4 (git commit: 061aa95)

## 環境安裝
以下指令皆在 Ubuntu 22.04 LTS 測試，若您使用不同 Linux 發行版，請自行參考相關文件。  
若您有使用 NVIDIA GPU 進行模型推論的需求，請安裝 CUDA 與 NVIDIA Container Toolkit。

### 1. (非必要) 安裝 CUDA 驅動程式

參考文件: [NVIDIA CUDA Installation Guide for Linux](https://docs.nvidia.com/cuda/cuda-installation-guide-linux/)

```sh
# Install the header of current running kernel
sudo apt install -y linux-headers-$(uname -r)
# or for auto-upgrade
sudo apt install -y linux-headers-generic

# Install the keyring
distribution=$(. /etc/os-release;echo $ID$VERSION_ID | sed -e 's/\.//g')
wget https://developer.download.nvidia.com/compute/cuda/repos/$distribution/x86_64/cuda-keyring_1.1-1_all.deb
sudo dpkg -i cuda-keyring_1.1-1_all.deb
rm cuda-keyring_1.1-1_all.deb
sudo apt update

# Install the NVIDIA driver without any X Window packages
sudo apt install -y --no-install-recommends cuda-drivers
sudo reboot

# Verify the version of installed driver
cat /proc/driver/nvidia/version
# Output sample:
# NVRM version: NVIDIA UNIX x86_64 Kernel Module  545.23.08  Mon Nov  6 23:49:37 UTC 2023
# GCC version:  gcc version 11.4.0 (Ubuntu 11.4.0-1ubuntu1~22.04)
```

### 2. 安裝 Docker 與 Docker Compose

參考文件: [Install Docker Engine on Ubuntu](https://docs.docker.com/engine/install/ubuntu/)
```sh
# Add official GPG key
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

# Setup repository
echo \
  "deb [arch="$(dpkg --print-architecture)" signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  "$(. /etc/os-release && echo "$VERSION_CODENAME")" stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update

# Install necessary package
sudo apt-get install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Enable the service
sudo systemctl --now enable docker

# Enable unattended-update
cat << EOT | sudo tee /etc/apt/apt.conf.d/51unattended-upgrades-docker
Unattended-Upgrade::Origins-Pattern {
    "origin=Docker";
};
EOT
```

### 3. (非必要) 安裝 NVIDIA Container Toolkit

參考文件: [Installing the NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/install-guide.html)

```sh
# Setup GPG key
curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | sudo gpg --dearmor -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg

# Setup the repository
distribution=$(. /etc/os-release;echo $ID$VERSION_ID)
curl -s -L https://nvidia.github.io/libnvidia-container/$distribution/libnvidia-container.list | \
  sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' | \
  sudo tee /etc/apt/sources.list.d/nvidia-container-toolkit.list
sudo apt-get update
sudo apt-get install -y nvidia-container-toolkit

# Configure the NVIDIA runtime to be the default docker runtime
sudo nvidia-ctk runtime configure --runtime=docker --set-as-default
sudo systemctl restart docker

# Verify
sudo docker pull nvidia/cuda:12.2.0-base-ubuntu22.04
sudo docker run --rm --gpus all nvidia/cuda:12.2.0-base-ubuntu22.04 nvidia-smi
```

## 基礎安裝

### 1. 更改設定檔

複製`.admin-password.sample`, `.db-password.sample`, `.env.sample`, `run.sh.sample` 並把 `.sample` 副檔名去掉  
檔案說明如下:
- `.admin-password`: 預設管理者密碼，建議不要維持預設值
- `.db-password`: 系統自帶資料庫密碼，建議設定成足夠長度的隨機字串
- `.env`: 其餘系統環境變數，最小設定值如下
    ```sh
    DOMAIN_NAME=localhost # 網站域名，若要公開提供服務，請設定成你的公開域名
    PUBLIC_BASE_URL="http://${DOMAIN_NAME}/" # 網站基礎 URL

    ADMIN_NAME="Skyscope Admin" # 網站預設管理者名稱
    ADMIN_EMAIL="admin@${DOMAIN_NAME}" # 網站預設管理者登入電子郵件，可為不存在的電子郵件
    ```

### 2. 啟動系統

> [!WARNING]
> 請使用 Docker Compose V2 以上的版本。
> Ubuntu APT 中的 `docker-compose` 套件為 Docker Compose V1，無法使用，請參考前面章節安裝新版 Docker Compose

使用以下腳本啟動基礎 Skyscope GenAI OS 系統，包含 Gemini-Pro Executor, Document QA, WebQA, Search QA
可以透過更改 `./run.sh` 中的 `confs` 陣列內容調整要啟動的元件，元件設定都在 `docker/compose` 目錄中
```sh
./run.sh
```

## 進階使用

### 1. 啟動除錯模式
Docker 版本預設不會在 Multi-Chat 網頁前端顯示任何錯誤訊息，若您遇到錯誤可以將 `./run.sh` 中的 `# "dev"` 前方註解取消，
並重新執行以下指令，即可啟動除錯模式
```sh
./run.sh
```

### 2. 執行多個 Executor
每種 Executor 的設定都已寫在 `docker/compose` 目錄下對應的 YAML 檔案中 (gemini.yaml, chatgpt.yaml, huggingface.yaml, llamacpp.yaml, ...)，請參考這些設定檔按照您的需求擴充。  
您可能需要參考 [Executor 說明文件](../src/executor/README_TW.md)。  
並將所需的 YAML 設定檔新增至 `./run.sh` 中的 `confs` 陣列中。
完成設定檔後可使用以下指令啟動整個系統
```sh
./run.sh
```

### 3. 強制更新
若您資料庫不小心遺失或毀損，可透過強置更新重置資料庫。  
請先確定系統正在運作中，再使用以下指令強制更新資料庫。  
```sh
docker exec -it skyscope-chat-1 docker-entrypoint force-upgrade
```

### 4. 從原始碼建置 Docker Images
自 v0.3.4 起，預設會從 Docker Hub 下載預先建置好的 Skyscope Docker Image，
若您想從原始碼建立映像，請確保您的 `genai-os` 目錄下已包含 `.git` 目錄。接著，可使用以下指令建立 Skyscope 映像：
```sh
cd docker
./run.sh build
```
執行此指令會建立 `skyscopeai/model-executor`、`skyscopeai/multi-chat`、`skyscopeai/kernel`、以及 `skyscopeai/multi-chat-web` 等四個映像。