# syntax=docker/dockerfile:1
FROM docker.io/library/nginx:1.31.9-alpine

RUN apk upgrade --no-cache && \
    apk add --no-cache curl

COPY docker/web/fastcgi.conf /etc/nginx/fastcgi.conf
COPY docker/web/default.conf /etc/nginx/conf.d/default.conf
COPY --from=kuwaai/multi-chat:latest /app/public /app/public

EXPOSE 80
