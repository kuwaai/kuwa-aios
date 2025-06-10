#!/bin/bash

REBOOT_FLAG=".reboot_flag"
BUILD_SCRIPT_VERSION="v0.2.0"
SKYSCOPE_OWNER=${SUDO_USER:-$USER}

install_docker() {
  if ! command -v docker &>/dev/null; then
    echo "Installing Docker..."

    # Uninstall conflicting packages
    for pkg in docker.io docker-doc docker-compose docker-compose-v2 podman-docker containerd runc; do apt-get remove $pkg; done

    # Add docker's official GPG key
    apt-get update
    apt-get install -y ca-certificates curl
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc

    # Setup repository
    echo \
      "deb [arch="$(dpkg --print-architecture)" signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
                        "$(. /etc/os-release && echo "$VERSION_CODENAME")" stable" |
      tee /etc/apt/sources.list.d/docker.list >/dev/null
    apt-get update

    # Install necessary package
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

    # Enable the service
    systemctl --now enable docker

    # Enable unattended-update
    cat <<EOT | tee /etc/apt/apt.conf.d/51unattended-upgrades-docker
Unattended-Upgrade::Origins-Pattern {
  "origin=Docker";
};
EOT
  else
    echo "Docker is already installed."
  fi
  echo "Adding $SKYSCOPE_OWNER to group docker."
  adduser $SKYSCOPE_OWNER docker
  docker version
}

install_nvidia_driver() {
  if ! command -v nvidia-smi &>/dev/null; then
    echo "Installing NVIDIA driver..."
    apt-get update
    apt-get upgrade -y
    apt-get install -y ubuntu-drivers-common

    # Remove previous NVIDIA installation
    apt-get autoremove nvidia* --purge -y
    apt-get autoclean

    # Install Ubuntu and NVIDIA drivers
    local version=$(ubuntu-drivers devices 2>/dev/null | grep recommended | grep -oP 'nvidia-driver-\d+')
    ubuntu-drivers autoinstall
    apt-get install -y $version

    # Reboot
    touch "$REBOOT_FLAG"
    echo "==================================================================================="
    echo "The system will reboot in 30 seconds. Rerun this script after the reboot completes."
    echo "==================================================================================="
    sleep 30
    reboot
  else
    echo "NVIDIA driver is already installed."
  fi

}

install_cuda_toolkit() {
  if ! command -v nvcc --version &>/dev/null; then
    echo "Installing CUDA toolkit..."
    apt-get update
    apt-get upgrade -y

    # Install CUDA toolkit
    apt-get install -y nvidia-cuda-toolkit
  else
    echo "CUDA toolkit is already installed."
  fi
}

install_nvidia_container_toolkit() {
  if ! command -v nvidia-ctk &>/dev/null; then
    echo "Installing NVIDIA continaer toolkit"

    # Setup GPG key
    curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | gpg --dearmor -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg

    # Setup the repository
    curl -s -L https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list |
      sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' |
      tee /etc/apt/sources.list.d/nvidia-container-toolkit.list
    apt-get update
    apt-get install -y nvidia-container-toolkit

    # Configure the NVIDIA runtime to be the default docker runtime
    nvidia-ctk runtime configure --runtime=docker --set-as-default
    systemctl restart docker
  else
    echo "NVIDIA container toolkit is already installed."
  fi
}

install_skyscope() {
  # Download Skyscope repository if not exist
  cd "$(pwd)"
  if ! git rev-parse &>/dev/null; then
    git clone https://github.com/skyscopeai/skyscope-aios/ skyscope-genai-os
    chown -R $SKYSCOPE_OWNER skyscope-genai-os
    pushd skyscope-genai-os/docker > /dev/null
  else
    pushd "$(dirname "$0")" > /dev/null
  fi

  # Change configuration files
  cp .admin-password.sample .admin-password
  cp .db-password.sample .db-password
  cp .env.sample .env
  cp run.sh.sample run.sh

  # Set admin password
  while true; do
    read -sp "Enter Skyscope admin password: " admin_passwd
    echo
    read -sp "Confirm Skyscope admin password: " admin_passwd_confirm
    echo

    if [ "$admin_passwd" == "$admin_passwd_confirm" ]; then
      echo "Admin password set successfully."
      echo "$admin_passwd" >.admin-password
      break
    else
      echo "Passwords do not match. Please enter your password again."
    fi
  done

  # Set random database password
  db_passwd=$(LC_ALL=C tr -dc 'A-Za-z0-9!"#$%&'\''()*+,-./:;<=>?@[\]^_`{|}~' </dev/urandom | head -c 13)
  echo "$db_passwd" >.db-password
  echo "Database password set successfully."

  # Copy bots and tools
  ./script/sync-root.sh
  
  # [Optional] Build the docker image from source.
  read -p "Would you like to build the Skyscope Docker image from its source code? [y/N]: " build_docker_image
  if [[ "$build_docker_image" == "y" || "$build_docker_image" == "Y" ]]; then
    ./script/build-image.sh
  fi
  ./run.sh
  popd > /dev/null
}

install_all() {
  if [[ -f "$REBOOT_FLAG" ]]; then
    echo "The system has been rebooted. Continuing installation."
    rm -f "$REBOOT_FLAG"
  elif ! command -v nvidia-smi &>/dev/null; then
    read -p "Do you want to install NVIDIA GPU drivers? [y/N]: " install_gpu
    if [[ "$install_gpu" == "y" || "$install_gpu" == "Y" ]]; then
      install_nvidia_driver
    fi
  fi

  install_docker
  if command -v nvidia-smi &>/dev/null; then
    # install_cuda_toolkit
    install_nvidia_container_toolkit
  fi
  install_skyscope
}

echo "Skyscope building script ${BUILD_SCRIPT_VERSION}"
echo "This script automates the installation of Skyscope and its dependencies. It has been tested on Ubuntu 22.04 and 24.04."
echo "Linux distribution information:"
lsb_release -a
echo "Preparing installation..."
sleep 2
install_all
