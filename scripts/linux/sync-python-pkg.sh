#!/usr/bin/env bash

function cleanup {
    popd >/dev/null
}

pushd $(dirname "$0") > /dev/null
trap cleanup EXIT

cd ../../ # To root of repository

ARCH=${1:-x86_64}
IMAGE_VARIANT=${2:-cu121}

echo ARCH: ${ARCH}
echo IMAGE_VARIANT: ${IMAGE_VARIANT}

uv pip uninstall -r windows/src/force-reinstall-requirements.txt
uv pip sync --refresh docker/executor/requirements-${ARCH}-${IMAGE_VARIANT}.txt.lock

cd src/kernel
uv pip install .