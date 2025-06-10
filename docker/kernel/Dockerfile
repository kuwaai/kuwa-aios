FROM python:3.10-alpine

WORKDIR /usr/src/app

RUN apk update && apk add git
COPY src/kernel/. .
COPY .git ../../.git
RUN --mount=type=cache,target=/root/.cache/pip \
    pip install . &&\
    rm -rf ../../.git &&\
    apk del git

CMD [ "skyscope-kernel" ]