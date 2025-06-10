#!/bin/bash

# Skyscope GenAI OS Installer
# This script automates the installation of Skyscope GenAI OS and its dependencies.

echo "######################################################"
echo "# Welcome to the Skyscope GenAI OS Installer!        #"
echo "#                                                    #"
echo "# This script will guide you through the installation#"
echo "# of Skyscope GenAI OS, which includes:              #"
echo "# 1. Basic system checks                             #"
echo "# 2. Detection of your Linux distribution            #"
echo "# 3. Installation of core dependencies (curl, git) #"
echo "# 4. Execution of the main Docker-based setup script #"
echo "#    (docker/build.sh) from our GitHub repository.   #"
echo "#                                                    #"
echo "# Root/sudo privileges are required for installation.#"
echo "######################################################"
echo ""
read -p "Press Enter to continue or Ctrl+C to cancel..."

# --- Basic System Checks ---
echo ""
echo "[INFO] Performing basic system checks..."
# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
  echo "[ERROR] This script must be run as root or with sudo." >&2
  exit 1
fi

# Optional: Check for minimum disk space (e.g., 20GB)
# recommended_disk_space_gb=20
# available_disk_space_gb=$(df -BG / | awk 'NR==2 {print $4}' | sed 's/G//')
# if [ "$available_disk_space_gb" -lt "$recommended_disk_space_gb" ]; then
#   echo "[WARNING] Recommended disk space is ${recommended_disk_space_gb}GB, but only ${available_disk_space_gb}GB is available."
#   # Decide if this should be a hard exit or just a warning.
#   # read -p "Continue anyway? (y/N): " confirm_space
#   # if [ "$confirm_space" != "y" ]; then
#   #   exit 1
#   # fi
# fi
echo "[INFO] Basic system checks passed."

# --- Linux Distribution Detection ---
echo ""
echo "[INFO] Detecting Linux distribution..."
PACKAGE_MANAGER=""
DISTRO_NAME=""
DISTRO_VERSION=""

if [ -f /etc/os-release ]; then
    # freedesktop.org and systemd
    . /etc/os-release
    DISTRO_NAME=$ID
    DISTRO_VERSION=$VERSION_ID
    case $ID in
        ubuntu)
            PACKAGE_MANAGER="apt"
            ;;
        debian)
            PACKAGE_MANAGER="apt"
            ;;
        fedora)
            PACKAGE_MANAGER="dnf"
            ;;
        centos)
            if [ "$VERSION_ID" = "7" ]; then
                PACKAGE_MANAGER="yum"
            else
                PACKAGE_MANAGER="dnf" # For CentOS Stream 8+
            fi
            ;;
        rhel) # Red Hat Enterprise Linux
            if [[ "$VERSION_ID" < "8" ]]; then
                PACKAGE_MANAGER="yum"
            else
                PACKAGE_MANAGER="dnf"
            fi
            ;;
        *)
            # Try lsb_release as a fallback
            if command -v lsb_release >/dev/null 2>&1; then
                DISTRO_NAME=$(lsb_release -is)
                DISTRO_VERSION=$(lsb_release -rs)
                DISTRO_NAME_LOWER=$(echo "$DISTRO_NAME" | tr '[:upper:]' '[:lower:]')
                case $DISTRO_NAME_LOWER in
                    ubuntu)
                        PACKAGE_MANAGER="apt"
                        ;;
                    debian)
                        PACKAGE_MANAGER="apt"
                        ;;
                    fedora)
                        PACKAGE_MANAGER="dnf"
                        ;;
                    centos)
                        PACKAGE_MANAGER="yum"
                        # Add version check for CentOS via lsb_release if more specific handling is needed
                        # For instance, if lsb_release -rs returns "7.x.xxxx"
                        if [[ "$(lsb_release -rs)" == 7* ]]; then
                            PACKAGE_MANAGER="yum"
                        else
                            PACKAGE_MANAGER="dnf" # Assuming newer CentOS might use dnf
                        fi
                        ;;
                    *)
                        echo "[ERROR] Unsupported Linux distribution: $DISTRO_NAME" >&2
                        echo "This script supports Ubuntu, Debian, Fedora, CentOS, and RHEL." >&2
                        exit 1
                        ;;
                esac
            else
                echo "[ERROR] Could not determine Linux distribution from /etc/os-release or lsb_release." >&2
                echo "This script supports Ubuntu, Debian, Fedora, CentOS, and RHEL." >&2
                exit 1
            fi
            ;;
    esac
elif command -v lsb_release >/dev/null 2>&1; then
    # Linux Standard Base fallback
    DISTRO_NAME=$(lsb_release -is)
    DISTRO_VERSION=$(lsb_release -rs)
    DISTRO_NAME_LOWER=$(echo "$DISTRO_NAME" | tr '[:upper:]' '[:lower:]')
    case $DISTRO_NAME_LOWER in
        ubuntu)
            PACKAGE_MANAGER="apt"
            ;;
        debian)
            PACKAGE_MANAGER="apt"
            ;;
        fedora)
            PACKAGE_MANAGER="dnf"
            ;;
        centos)
            if [[ "$(lsb_release -rs)" == 7* ]]; then
                PACKAGE_MANAGER="yum"
            else
                PACKAGE_MANAGER="dnf" # Assuming newer CentOS might use dnf
            fi
            ;;
        *)
            echo "[ERROR] Unsupported Linux distribution: $DISTRO_NAME" >&2
            echo "This script supports Ubuntu, Debian, Fedora, CentOS, and RHEL." >&2
            exit 1
            ;;
    esac
else
    echo "[ERROR] Cannot determine Linux distribution. /etc/os-release and lsb_release are not available." >&2
    exit 1
fi

echo "[INFO] Detected Distribution: $DISTRO_NAME $DISTRO_VERSION"
echo "[INFO] Using Package Manager: $PACKAGE_MANAGER"

# --- Dependency Installation ---
echo ""
echo "[INFO] Installing core dependencies (curl, git, sudo)..."
if [ "$PACKAGE_MANAGER" = "apt" ]; then
  sudo $PACKAGE_MANAGER update -y && sudo $PACKAGE_MANAGER install -y curl git sudo
elif [ "$PACKAGE_MANAGER" = "dnf" ]; then
  sudo $PACKAGE_MANAGER install -y curl git sudo
elif [ "$PACKAGE_MANAGER" = "yum" ]; then
  sudo $PACKAGE_MANAGER install -y curl git sudo
else
  echo "[ERROR] Package manager $PACKAGE_MANAGER not handled for core dependencies. Exiting." >&2
  exit 1
fi

if [ $? -ne 0 ]; then
    echo "[ERROR] Failed to install core dependencies. Please check your system and package manager output." >&2
    exit 1
fi
echo "[INFO] Core dependencies installed successfully."

# --- Execute Docker Build Script ---
echo ""
echo "[INFO] Proceeding with Skyscope GenAI OS setup using docker/build.sh from GitHub..."
echo "[INFO] This step will install Docker (if not present) and build the Skyscope application."
# This command fetches the build script from the main branch of the repository and executes it.
# The docker/build.sh script is expected to handle Docker installation and then building the Skyscope application.
bash -c "$(curl -fsSL https://raw.githubusercontent.com/skyscopeai/skyscope-genai-os/main/docker/build.sh)"

if [ $? -ne 0 ]; then
    echo "[ERROR] Execution of docker/build.sh failed. Please check the output above for errors." >&2
    echo "[INFO] Common troubleshooting steps:"
    echo "       - Ensure your system has internet connectivity."
    echo "       - Check if your Linux distribution requires additional steps for Docker installation."
    echo "       - Look for specific error messages from the docker/build.sh script output."
    exit 1
fi

echo ""
echo "####################################################################"
echo "# Skyscope GenAI OS installation process has completed.            #"
echo "#                                                                  #"
echo "# If all steps were successful, the application services should be #"
echo "# starting up via Docker.                                          #"
echo "#                                                                  #"
echo "# You should typically be able to access Skyscope GenAI OS at:     #"
echo "# http://localhost or your configured domain (if any).             #"
echo "#                                                                  #"
echo "# If you encounter issues:                                         #"
echo "# - Review the output of this script for any error messages.       #"
echo "# - Check Docker logs: sudo docker ps -a (to find container names) #"
echo "#   then sudo docker logs <container_name>                         #"
echo "# - Visit our community forums or GitHub issues for support.       #"
echo "####################################################################"

exit 0
