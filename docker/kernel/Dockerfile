FROM python:3.14-alpine

WORKDIR /usr/src/app

COPY src/kernel/pyproject.toml ./src/kernel/pyproject.toml
RUN --mount=type=cache,target=/root/.cache/pip \
    python -c 'from pathlib import Path; \
from tomllib import loads; \
path = Path("src/kernel/pyproject.toml"); \
deps = loads(path.read_text())["project"].get("dependencies", []); \
Path("/tmp/kernel-requirements.txt").write_text("\n".join(deps) + "\n")' && \
    pip install -r /tmp/kernel-requirements.txt \
        "msgpack>=1.2.3" "setuptools>=78.1.1" "urllib3>=2.8.0" && \
    pip install --upgrade pip

ARG SETUPTOOLS_SCM_PRETEND_VERSION=0.0.0
ENV SETUPTOOLS_SCM_PRETEND_VERSION=${SETUPTOOLS_SCM_PRETEND_VERSION}

COPY src/kernel src/kernel
RUN --mount=type=cache,target=/root/.cache/pip \
    pip install --no-deps ./src/kernel && \
    python -m pip uninstall --yes pip && \
    find /usr/local/lib -path '*/ensurepip/_bundled/*.whl' -delete

CMD [ "kuwa-kernel" ]