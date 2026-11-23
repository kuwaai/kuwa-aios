# syntax=docker/dockerfile:1
FROM --platform=$BUILDPLATFORM golang:1.27.3-alpine AS proxy-tools
ARG TARGETOS
ARG TARGETARCH
ARG DOCKER_GEN_VERSION=0.17.2
ARG FOREGO_VERSION=0.18.8
ENV CGO_ENABLED=0

RUN apk add --no-cache git
RUN git clone --depth 1 --branch "${DOCKER_GEN_VERSION}" https://github.com/nginx-proxy/docker-gen.git /src/docker-gen && \
    git clone --depth 1 --branch "v${FOREGO_VERSION}" https://github.com/nginx-proxy/forego.git /src/forego
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    cd /src/docker-gen && \
    go get github.com/klauspost/compress@v1.18.7 github.com/moby/go-archive@v0.3.0 golang.org/x/crypto@v0.58.0 && \
    GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -trimpath \
        -ldflags "-s -w -X main.buildVersion=${DOCKER_GEN_VERSION}" -o /out/docker-gen ./cmd/docker-gen && \
    cd /src/forego && \
    go get golang.org/x/text@v0.43.0 && \
    GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -trimpath \
        -ldflags "-s -w -X main.buildVersion=${FOREGO_VERSION}" -o /out/forego .

FROM docker.io/nginxproxy/nginx-proxy:1.11.6-alpine AS nginx-proxy

FROM docker.io/library/nginx:1.31.9-alpine

ARG NGINX_PROXY_VERSION="1.11.6"
ARG DOCKER_GEN_VERSION="0.17.2"
ENV NGINX_PROXY_VERSION=${NGINX_PROXY_VERSION} \
    DOCKER_GEN_VERSION=${DOCKER_GEN_VERSION} \
    DOCKER_HOST=unix:///tmp/docker.sock \
    DEFAULT_ROOT=none

RUN apk upgrade --no-cache && \
    apk add --no-cache --virtual .run-deps bash openssl curl dnsmasq && \
    echo -e "\ninclude /etc/nginx/toplevel.conf.d/*.conf;" >> /etc/nginx/nginx.conf && \
    sed -i 's/worker_connections.*;$/worker_connections   10240;/' /etc/nginx/nginx.conf && \
    sed -i -e '/^\}$/{s//\}\nworker_rlimit_nofile 20480;/;:a' -e '$!N;$!ba' -e '}' /etc/nginx/nginx.conf && \
    mkdir -p /etc/nginx/toplevel.conf.d /etc/nginx/dhparam /etc/nginx/certs \
        /usr/share/nginx/html/errors

COPY --from=proxy-tools /out/docker-gen /out/forego /usr/local/bin/
COPY --from=nginx-proxy /etc/nginx/network_internal.conf /etc/nginx/
COPY --from=nginx-proxy /app/ /app/
COPY docker/web/vhost.d/ /etc/nginx/vhost.d/
COPY docker/web/fastcgi.conf /etc/nginx/fastcgi.conf
COPY docker/web/nginx-proxy/ /app/
COPY --from=kuwaai/multi-chat:latest /app/public /app/public

ENTRYPOINT ["/app/docker-entrypoint.sh"]
CMD ["forego", "start", "-r"]
