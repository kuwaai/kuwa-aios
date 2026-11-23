#!/bin/bash
set -euo pipefail

function cleanup {
    popd >/dev/null
}

pushd "$(dirname "$0")" > /dev/null
trap cleanup EXIT

if command -v nvidia-smi &>/dev/null; then
    image_variant=cu128
else
    image_variant=cpu
fi
image_variant=${1:-$image_variant}
shift || true

if [ "$image_variant" == "cu128" ]; then
    base_image=ubuntu:26.04
elif [ "$image_variant" == "cpu" ]; then
    base_image=ubuntu:26.04
else
    echo "Unsupported image variant: ${image_variant}" >&2
    exit 2
fi

echo -e "building docker image. IMAGE_VARIANT=${image_variant}; BASE_IMAGE=${base_image}"
cd ..
git_describe=$(git describe --tags --long --always 2>/dev/null || echo 0.0.0)
if [[ "$git_describe" =~ ^v?([0-9]+\.[0-9]+\.[0-9]+)-([0-9]+)-g([0-9a-f]+)$ ]]; then
    git_version="${BASH_REMATCH[1]}.dev${BASH_REMATCH[2]}+g${BASH_REMATCH[3]}"
else
    git_version="0.0.0+g${git_describe##*-}"
fi
compose_args=(-f compose/base.yaml -f compose/pgsql.yaml)
if [ -f .env ]; then
    compose_args=(--env-file ./.env "${compose_args[@]}")
fi

export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1
export COMPOSE_BAKE=${COMPOSE_BAKE:-true}

build_args=(
    --build-arg IMAGE_VARIANT="${image_variant}"
    --build-arg BASE_IMAGE="${base_image}"
    --build-arg SETUPTOOLS_SCM_PRETEND_VERSION="${git_version}"
)

if [ "$#" -eq 0 ]; then
    # web copies the multi-chat public tree, so ensure that image exists before
    # Bake builds the remaining services concurrently.
    docker compose "${compose_args[@]}" build "${build_args[@]}" multi-chat
    docker compose "${compose_args[@]}" build "${build_args[@]}"
else
    docker compose "${compose_args[@]}" build "${build_args[@]}" "$@"
fi