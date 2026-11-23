# syntax=docker/dockerfile:1
# CPU and CUDA variants use the same updated Ubuntu runtime. CUDA libraries come
# from the locked PyTorch wheels and the driver comes from the container runtime.
ARG BASE_IMAGE=ubuntu:26.04
FROM ${BASE_IMAGE} AS runtime-base

ENV NVIDIA_VISIBLE_DEVICES=all
ENV NVIDIA_DRIVER_CAPABILITIES=compute,utility
ENV UV_BREAK_SYSTEM_PACKAGES=1

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && \
    apt-get upgrade -y --no-install-recommends && \
    apt-get install -y --no-install-recommends \
        python3 python3-pip python3-dev python-is-python3 \
        locales libgomp1 ca-certificates curl \
        musl-dev libnss3-dev ffmpeg && \
    ln -s /usr/lib/$(uname -m)-linux-musl/libc.so /lib/libc.musl-$(uname -m).so.1 && \
    locale-gen zh_TW.UTF-8 && \
    python3 -c 'import sys; sys.exit(0 if sys.version_info[:2] == (3, 14) else 1)' && \
    rm -rf /var/lib/apt/lists/*

FROM runtime-base AS build
ARG TARGETPLATFORM
ARG IMAGE_VARIANT="cpu"

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && \
    apt-get install -y --no-install-recommends cmake build-essential git && \
    rm -rf /var/lib/apt/lists/*

COPY --from=ghcr.io/astral-sh/uv:latest /uv /uvx /bin/

WORKDIR /usr/src/kuwa

COPY docker/executor/requirements*.txt.lock ./
RUN --mount=type=cache,target=/root/.cache/uv \
    if [ "$TARGETPLATFORM" = "linux/amd64" ]; then \
        ARCH="x86_64"; \
    elif [ "$TARGETPLATFORM" = "linux/arm64" ]; then \
        ARCH="aarch64"; \
    else \
        echo "Unsupported platform: $TARGETPLATFORM"; \
        exit 1; \
    fi; \
    sed '/^\.\//d' "requirements-${ARCH}-${IMAGE_VARIANT}.txt.lock" > /tmp/requirements.txt.lock; \
    uv pip sync --force-reinstall --system \
        --link-mode copy --index-strategy unsafe-best-match /tmp/requirements.txt.lock

ARG SETUPTOOLS_SCM_PRETEND_VERSION=0.0.0
ENV SETUPTOOLS_SCM_PRETEND_VERSION=${SETUPTOOLS_SCM_PRETEND_VERSION}

COPY src/library src/library
COPY src/executor src/executor
RUN uv pip install --system --no-deps \
    src/executor src/library/client src/library/rag

FROM runtime-base

WORKDIR /usr/src/kuwa-executor

COPY --from=build /usr/local/lib/python3.14/dist-packages /usr/local/lib/python3.14/dist-packages
COPY --from=build /usr/local/bin/kuwa-executor /usr/local/bin/kuwa-executor
COPY src/executor .

RUN if [ -d /usr/local/lib/python3.14/dist-packages/nvidia ]; then \
        find /usr/local/lib/python3.14/dist-packages/nvidia -maxdepth 2 -type d -name lib \
            > /etc/ld.so.conf.d/pip-nvidia.conf && \
        ldconfig; \
    fi

COPY docker/executor/multi-chat-client/multi-chat-client /usr/local/bin/
COPY docker/executor/docker-entrypoint /usr/local/bin/
RUN chmod +x /usr/local/bin/multi-chat-client /usr/local/bin/docker-entrypoint

ENV KUWA_ROOT="/var/kuwa/docker/root"
VOLUME ${KUWA_ROOT}
RUN mkdir -p ${KUWA_ROOT}/bin && \
    mkdir -p ${KUWA_ROOT}/database && \
    mkdir -p ${KUWA_ROOT}/custom
COPY src/tools ${KUWA_ROOT}/../../src/tools

ENV no_proxy="kernel,web,localhost,127.0.0.0/8,::1,${no_proxy}"
ENV NO_PROXY="kernel,web,localhost,127.0.0.0/8,::1,${NO_PROXY}"
ENV EXECUTOR_TYPE="debug"
ENV EXECUTOR_ACCESS_CODE="debug"
ENV EXECUTOR_NAME="Debug Executor"
ENV EXECUTOR_IMAGE=""
ENV EXECUTOR_ORDER=""
ENV EXECUTOR_CREATE_BOT="true"
ENV ADD_EXECUTOR_TO_MULTI_CHAT="true"
ENV KERNEL_URL="http://kernel:9000/"
ENTRYPOINT [ "docker-entrypoint" ]
CMD []
