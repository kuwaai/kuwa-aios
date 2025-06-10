#!/bin/bash
set -e

REBOOT_FLAG=".reboot_flag"
BUILD_SCRIPT_VERSION="v0.2.0"
SKYSCOPE_OWNER=${SUDO_USER:-$USER}

install_docker() {
  if ! command -v docker &>/dev/null; then
    echo "[INFO] Docker not found. Attempting to install Docker..."
    if ! command -v apt-get &> /dev/null; then
        echo "[WARNING] 'apt-get' command not found. This script uses 'apt-get' to install Docker automatically."
        echo "           Please install Docker manually for your Linux distribution and then re-run this script."
        echo "           For Docker installation instructions, see: https://docs.docker.com/engine/install/"
        # Exit or return, depending on whether install_all should proceed with other steps
        # For now, let's exit as subsequent steps depend on Docker.
        exit 1
    fi

    echo "[INFO] Updating package lists and installing Docker (dependencies: ca-certificates, curl)..."
    # Uninstall conflicting packages
    # Use a loop with `apt-get remove -y` and ignore errors if a package is not installed
    for pkg in docker.io docker-doc docker-compose docker-compose-v2 podman-docker containerd runc; do
      if dpkg -s $pkg &> /dev/null; then
        echo "[INFO] Removing conflicting package: $pkg"
        sudo apt-get remove -y $pkg || echo "[WARNING] Failed to remove $pkg, or it was not installed. Continuing..."
      fi
    done

    sudo apt-get update -y
    sudo apt-get install -y ca-certificates curl

    echo "[INFO] Setting up Docker's official GPG key..."
    sudo install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    sudo chmod a+r /etc/apt/keyrings/docker.gpg

    echo "[INFO] Setting up Docker repository..."
    echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
      $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
      sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
    sudo apt-get update -y

    echo "[INFO] Installing Docker packages (docker-ce, docker-ce-cli, containerd.io, docker-buildx-plugin, docker-compose-plugin)..."
    sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

    echo "[INFO] Enabling and starting Docker service..."
    sudo systemctl --now enable docker

    echo "[INFO] Setting up unattended upgrades for Docker (optional)..."
    cat <<EOT | sudo tee /etc/apt/apt.conf.d/51unattended-upgrades-docker
Unattended-Upgrade::Origins-Pattern {
  "origin=Docker";
};
EOT
    echo "[INFO] Docker installed successfully."
  else
    echo "[INFO] Docker is already installed."
  fi

  echo "[INFO] Adding user $SKYSCOPE_OWNER to the 'docker' group..."
  if getent group docker > /dev/null; then
    sudo adduser $SKYSCOPE_OWNER docker || echo "[WARNING] Failed to add $SKYSCOPE_OWNER to docker group. This might be fine if the user is already in the group or if this script is run in a context where adduser has limitations."
  else
    echo "[WARNING] 'docker' group does not exist. Skipping adduser."
  fi
  echo "[INFO] Docker version:"
  docker version
}

install_nvidia_driver() {
  if ! command -v nvidia-smi &>/dev/null; then
    echo "[INFO] NVIDIA driver not found (nvidia-smi not detected)."
    if ! command -v apt-get &> /dev/null || ! command -v ubuntu-drivers &> /dev/null; then
        echo "[WARNING] 'apt-get' or 'ubuntu-drivers' command not found. This script uses these commands for automatic NVIDIA driver installation on Ubuntu."
        echo "           If you are on a different distribution or wish to install drivers manually:"
        echo "           1. Install the appropriate NVIDIA drivers for your GPU and Linux distribution."
        echo "           2. Ensure 'nvidia-smi' command is working."
        echo "           3. Re-run this script."
        echo "           Alternatively, if you do not have an NVIDIA GPU or do not wish to use GPU acceleration, you can skip this step if prompted or ignore related errors if Skyscope proceeds with CPU-based operations."
        # Decide whether to exit or allow proceeding without GPU. For now, allow proceeding.
        return 1 # Indicate drivers are not set up by this script
    fi

    echo "[INFO] Attempting to install NVIDIA drivers for Ubuntu..."
    sudo apt-get update -y
    sudo apt-get upgrade -y
    sudo apt-get install -y ubuntu-drivers-common

    echo "[INFO] Removing any previous NVIDIA installations..."
    sudo apt-get autoremove -y nvidia* --purge || echo "[WARNING] Failed to autoremove existing nvidia packages, or none were present."

    echo "[INFO] Installing recommended NVIDIA drivers..."
    # ubuntu-drivers devices # This just lists, let autoinstall handle it.
    sudo ubuntu-drivers autoinstall
    # The command below might be redundant if autoinstall works, but can be a fallback.
    # local version=$(ubuntu-drivers devices 2>/dev/null | grep recommended | grep -oP 'nvidia-driver-\d+')
    # if [ -n "$version" ]; then
    #   sudo apt-get install -y $version
    # else
    #   echo "[WARNING] Could not determine recommended NVIDIA driver. Skipping specific version install."
    # fi

    echo "[INFO] NVIDIA driver installation process initiated."
    echo "       A REBOOT IS REQUIRED to complete the NVIDIA driver installation."
    touch "$REBOOT_FLAG"
    echo "==================================================================================="
    echo "[ACTION REQUIRED] The system will reboot in 30 seconds to apply NVIDIA driver changes."
    echo "                Please re-run this script (docker/build.sh) after the reboot completes."
    echo "==================================================================================="
    sleep 30
    sudo reboot
    exit 0 # Exit to ensure reboot happens before anything else.
  else
    echo "[INFO] NVIDIA driver (nvidia-smi) is already installed/detected."
  fi
  return 0
}

install_cuda_toolkit() {
  # This function is currently not called by install_all if NVIDIA drivers are present.
  # If it were to be used, it should also have apt-get checks.
  if ! command -v nvcc --version &>/dev/null; then
    echo "[INFO] CUDA toolkit (nvcc) not found. Attempting to install..."
    if ! command -v apt-get &> /dev/null; then
        echo "[WARNING] 'apt-get' command not found. Cannot install CUDA toolkit automatically."
        echo "           Please install CUDA toolkit manually if required for your setup."
        return 1
    fi
    sudo apt-get update -y
    sudo apt-get install -y nvidia-cuda-toolkit
    echo "[INFO] CUDA toolkit installed."
  else
    echo "[INFO] CUDA toolkit (nvcc) is already installed/detected."
  fi
}

install_nvidia_container_toolkit() {
  if ! command -v nvidia-ctk &>/dev/null; then
    echo "[INFO] NVIDIA Container Toolkit (nvidia-ctk) not found. Attempting to install..."
    if ! command -v apt-get &> /dev/null || ! command -v curl &> /dev/null || ! command -v gpg &> /dev/null; then
        echo "[WARNING] 'apt-get', 'curl', or 'gpg' command not found. Cannot install NVIDIA Container Toolkit automatically."
        echo "           Please install NVIDIA Container Toolkit manually if required for GPU support in Docker."
        return 1
    fi

    echo "[INFO] Setting up NVIDIA Container Toolkit repository..."
    curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | sudo gpg --dearmor -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg
    curl -s -L https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list | \
      sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' | \
      sudo tee /etc/apt/sources.list.d/nvidia-container-toolkit.list
    sudo apt-get update -y

    echo "[INFO] Installing NVIDIA Container Toolkit..."
    sudo apt-get install -y nvidia-container-toolkit

    echo "[INFO] Configuring Docker to use NVIDIA runtime..."
    sudo nvidia-ctk runtime configure --runtime=docker --set-as-default
    sudo systemctl restart docker
    echo "[INFO] NVIDIA Container Toolkit installed and configured."
  else
    echo "[INFO] NVIDIA Container Toolkit (nvidia-ctk) is already installed/detected."
  fi
}

install_skyscope() {
  echo ""
  echo "[INFO] Setting up Skyscope GenAI OS..."
  # If this script is run directly (not via install_skyscope.sh), it might be in a different CWD.
  # However, install_skyscope.sh is designed to curl *this* script, which implies this script
  # is responsible for getting its own application source if not present.

  # Determine script's own directory to robustly find other scripts if repo is already cloned.
  SCRIPT_DIR=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )

  # Check if we are already inside a git repository (potentially the skyscope-genai-os repo)
  # And if the current directory (which should be .../skyscope-genai-os/docker) contains the necessary files.
  if git rev-parse --is-inside-work-tree > /dev/null 2>&1 && \
     [ -f "$SCRIPT_DIR/run.sh.sample" ] && [ -d "$SCRIPT_DIR/../src" ]; then
    echo "[INFO] Already inside a Skyscope GenAI OS git repository. Using local files."
    # Ensure we are in the 'docker' directory relative to the script's location.
    cd "$SCRIPT_DIR"
  else
    echo "[INFO] Skyscope GenAI OS repository not found locally or not in the expected structure. Cloning from GitHub..."
    # Fallback to previous behavior: clone if not in a git repo or if it's the wrong repo.
    # This part assumes the script is run from a directory where 'skyscope-genai-os' can be cloned.
    # If install_skyscope.sh curls this script, 'cd "$(pwd)"' might not be the repo root.
    # For robustness if this script is curled and executed directly, it's better to operate in a defined temp or target dir.
    # However, for now, maintaining consistency with the current flow:
    TARGET_DIR_PARENT=$(pwd) # Assuming user runs from a suitable parent directory
    TARGET_DIR_CLONE="skyscope-genai-os"
    if [ -d "$TARGET_DIR_PARENT/$TARGET_DIR_CLONE" ]; then
      echo "[INFO] Directory $TARGET_DIR_PARENT/$TARGET_DIR_CLONE already exists. Attempting to use it."
      cd "$TARGET_DIR_PARENT/$TARGET_DIR_CLONE/docker" || { echo "[ERROR] Failed to cd into $TARGET_DIR_PARENT/$TARGET_DIR_CLONE/docker"; exit 1; }
    else
      echo "[INFO] Cloning Skyscope GenAI OS repository into $TARGET_DIR_PARENT/$TARGET_DIR_CLONE..."
      cd "$TARGET_DIR_PARENT"
      git clone https://github.com/skyscopeai/skyscope-aios/ "$TARGET_DIR_CLONE"
      sudo chown -R $SKYSCOPE_OWNER:$SKYSCOPE_OWNER "$TARGET_DIR_CLONE" # Use sudo for chown
      cd "$TARGET_DIR_CLONE/docker" || { echo "[ERROR] Failed to cd into $TARGET_DIR_CLONE/docker post-clone"; exit 1; }
    fi
  fi

  echo "[INFO] Current working directory: $(pwd)"
  echo "[INFO] Copying configuration file samples..."
  cp .admin-password.sample .admin-password
  cp .db-password.sample .db-password
  cp .env.sample .env
  cp run.sh.sample run.sh
  chmod +x run.sh # Ensure run.sh is executable

  echo "[INFO] Setting up administrator password..."
  while true; do
    read -sp "Enter Skyscope admin password: " admin_passwd
    echo
    read -sp "Confirm Skyscope admin password: " admin_passwd_confirm
    echo

    if [ "$admin_passwd" == "$admin_passwd_confirm" ]; then
      echo "[INFO] Admin password set successfully."
      echo "$admin_passwd" >.admin-password
      break
    else
      echo "[WARNING] Passwords do not match. Please enter your password again."
    fi
  done

  echo "[INFO] Setting random database password..."
  db_passwd=$(LC_ALL=C tr -dc 'A-Za-z0-9!"#$%&'\''()*+,-./:;<=>?@[\]^_`{|}~' </dev/urandom | head -c 13)
  echo "$db_passwd" >.db-password
  echo "[INFO] Database password set successfully."

  echo "[INFO] Syncing root directory files (bots, tools)..."
  # Ensure the sync script is executable
  chmod +x ./script/sync-skyscope-root.sh
  ./script/sync-skyscope-root.sh
  
  echo "[INFO] Checking for optional Docker image build from source..."
  read -p "Would you like to build the Skyscope Docker image from its source code? (This is optional and takes time) [y/N]: " build_docker_image
  if [[ "$build_docker_image" == "y" || "$build_docker_image" == "Y" ]]; then
    echo "[INFO] Building Docker images from source..."
    # Ensure build-image.sh is executable
    chmod +x ./script/build-image.sh
    ./script/build-image.sh
  else
    echo "[INFO] Skipping Docker image build from source. Pre-built images will be used."
  fi

  echo "[INFO] Starting Skyscope GenAI OS services using run.sh..."
  ./run.sh
  # popd is not strictly necessary here as the script will end,
  # but good practice if more commands followed.
  # The 'cd' logic at the start of install_skyscope needs to be robust.
  # If this script was invoked by `bash -c "$(curl ...)"`, `popd` might not make sense
  # as there was no `pushd` in *that* specific subshell context from the parent installer.
  # For now, let's assume `pushd` in this function is balanced by an implicit or explicit `popd`
  # or the script exits, making the CWD change temporary for this function's scope if part of a larger call.
  # Since `install_skyscope` is the final step in `install_all`, `popd` is not critical here.
}

install_all() {
  echo ""
  echo "[INFO] Starting Skyscope GenAI OS installation process..."
  if [[ -f "$REBOOT_FLAG" ]]; then
    echo "[INFO] System has been rebooted (reboot flag found). Continuing installation..."
    sudo rm -f "$REBOOT_FLAG" # Use sudo for rm if flag was created by root
  elif ! command -v nvidia-smi &>/dev/null; then
    read -p "Do you have an NVIDIA GPU and wish to install NVIDIA drivers? [y/N]: " install_gpu
    if [[ "$install_gpu" == "y" || "$install_gpu" == "Y" ]]; then
      install_nvidia_driver || echo "[WARNING] NVIDIA driver installation was skipped or failed. Proceeding without GPU acceleration by this script."
    else
      echo "[INFO] Skipping NVIDIA driver installation."
    fi
  fi

  install_docker
  if command -v nvidia-smi &>/dev/null; then
    echo "[INFO] NVIDIA driver detected. Setting up NVIDIA Container Toolkit..."
    # install_cuda_toolkit # Typically not needed for pre-built containers if drivers are correct
    install_nvidia_container_toolkit
  else
    echo "[INFO] No NVIDIA driver detected by nvidia-smi. Skipping NVIDIA Container Toolkit setup."
  fi
  install_skyscope
  echo "[INFO] Skyscope GenAI OS installation process finished."
}

echo ""
echo "--- Skyscope GenAI OS Docker Build Script ---"
echo "Version: ${BUILD_SCRIPT_VERSION}"
echo "This script automates the installation of Docker, (optionally) NVIDIA drivers, and Skyscope GenAI OS."
echo "It is primarily designed for Debian/Ubuntu-based systems for automated Docker/NVIDIA driver parts."
echo "For other systems, please ensure Docker and NVIDIA drivers (if needed) are installed manually first."
echo ""
echo "Current system information:"
lsb_release -a || echo "[WARNING] lsb_release not found. Cannot display detailed distribution info here."
echo ""
echo "Preparing installation..."
sleep 2
install_all

# The final success message is in install_skyscope.sh, which calls this script.
# This script should exit 0 if it reaches here successfully.
