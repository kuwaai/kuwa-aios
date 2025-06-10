# Install Kuwa GenAI OS on Docker

## Software versions
- Docker Compose V2 or later, tested versions
  - 2.24.6
  - 2.24.7
- Docker Engine 18.06.0 or later, tested versions
  - 25.0.3 (git commit: f417435)
  - 25.0.4 (git commit: 061aa95)

## Environment Installation
The following instructions have been tested on Ubuntu 22.04 LTS, if you are using a different Linux distribution, please refer to the relevant documentation.  
If you need to use NVIDIA GPU for model inference, please install CUDA and NVIDIA Container Toolkit.

### 1. (Optional) Install CUDA Driver

Refer to the documentation: [NVIDIA CUDA Installation Guide for Linux](https://docs.nvidia.com/cuda/cuda-installation-guide-linux/)

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

### 2. Install Docker and Docker Compose

Refer to the documentation: [Install Docker Engine on Ubuntu](https://docs.docker.com/engine/install/ubuntu/)
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

### 3. (Optional) Install NVIDIA Container Toolkit

Refer to the documentation: [Installing the NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/install-guide.html)

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

## Basic installation

### 1. Change configuration files

Copy `.admin-password.sample`, `.db-password.sample`, `.env.sample`, `run.sh.sample` and remove the `.sample` suffix.  
The files are as follows:
- `.admin-password`: Default administrator password, it is recommended not to keep the default value
- `.db-password`: System built-in database password, it is recommended to set it to a random string of sufficient length
- `.env`: Other system environment variables, the minimum set value is as follows
    ```sh
    DOMAIN_NAME=localhost # Website domain name, if you want to make the service public, please set it to your public domain name
    PUBLIC_BASE_URL="http://${DOMAIN_NAME}/" # Website base URL

    ADMIN_NAME="Skyscope Admin" # Website default administrator name
    ADMIN_EMAIL="admin@${DOMAIN_NAME}" # Website default administrator login email, which can be an invalid email
    ```

### 2. Start the System

> [!WARNING]
> Please use Docker Compose V2 or later.
> `docker-compose` package in Ubuntu APT is Docker Compose V1 and cannot be used. Please refer to the previous section to install the new version of Docker Compose.

Use the following script to start the basic Skyscope GenAI OS system, including Gemini-Pro Executor, Document QA, WebQA, and Search QA. You can adjust the components to be started by changing the content of the `confs` array in `./run.sh`. The component settings are all in the `compose` directory.
```sh
./run.sh
```

## Advanced Usage

### 1. Start with Debugging Mode
By default, the Docker version will not display any error messages on the Multi-Chat web front end. If you encounter an error, you can cancel the annotation before `# "dev"` in `./run.sh`, and then re-execute the following command to start the debugging mode.
```sh
./run.sh
```

### 2. Running Multiple Executors
Each Executor's setting is written in the corresponding YAML file under the `docker/compose` directory (gemini.yaml, chatgpt.yaml, huggingface.yaml, llamacpp.yaml, ...). Please reference these files and expand them according to your needs. You may need to consult the [Executor documentation](../src/executor/README_TW.md).

Add the required YAML settings file to the `confs` array in `./run.sh`. After setting up the files, you can initiate the entire system using the following command:
```sh
./run.sh
```

### 3. Force Upgrade
If your database is accidentally lost or corrupted, you can reset the database by forcibly updating it  
Please make sure the system is running, then use the following command to force upgrade the database  
```sh
docker exec -it skyscope-chat-1 docker-entrypoint force-upgrade
```

### 4. Building Docker Images from Source Code
Beginning with v0.3.4, the default behavior is to download pre-built Skyscope Docker Images from Docker Hub. If you want to build the images from source code, make sure the `.git` directory is present in the `genai-os` directory. Then, you can use the following command to create the Skyscope images:
```sh
cd docker
./run.sh build
```
Running this command will create the `skyscopeai/model-executor`, `skyscopeai/multi-chat`, `skyscopeai/kernel`, and `skyscopeai/multi-chat-web` images.