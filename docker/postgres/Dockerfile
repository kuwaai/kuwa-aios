# syntax=docker/dockerfile:1
FROM --platform=$BUILDPLATFORM golang:1.27.3-alpine AS gosu-builder
ARG TARGETOS
ARG TARGETARCH
ARG GOSU_VERSION=1.20
ENV CGO_ENABLED=0

RUN apk add --no-cache git && \
    git clone --depth 1 --branch "${GOSU_VERSION}" https://github.com/tianon/gosu.git /src/gosu
WORKDIR /src/gosu
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -trimpath \
        -ldflags "-s -w" -o /out/gosu .

FROM postgres:17.12-trixie
RUN apt-get update && \
    apt-get upgrade -y --no-install-recommends && \
    rm -rf /var/lib/apt/lists/* && \
    rm -f /etc/ssl/private/ssl-cert-snakeoil.key /etc/ssl/certs/ssl-cert-snakeoil.pem
COPY --from=gosu-builder /out/gosu /usr/local/bin/gosu
