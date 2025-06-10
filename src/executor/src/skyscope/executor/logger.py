import logging


class InternalEndpointFilter(logging.Filter):
    """
    Filter out internal endpoint from access log.
    """

    health_check_endpoint = "/health"
    prometheus_endpoint = "/metrics"

    def filter(self, record):
        # Aligned with uvicorn.logging.AccessFormatter
        (
            client_addr,
            method,
            full_path,
            http_version,
            status_code,
        ) = record.args

        internal_endpoints = [self.health_check_endpoint, self.prometheus_endpoint]
        result = all([full_path != x for x in internal_endpoints])
        return result


class ExecutorLoggerFactory:
    template = {
        "version": 1,
        "disable_existing_loggers": False,
        "formatters": {
            "default": {
                "()": "uvicorn.logging.ColourizedFormatter",
                "format": "%(asctime)s [%(name)-13s] %(levelprefix)-4s %(message)s",
                "datefmt": "%Y-%m-%d %H:%M:%S",
            },
            "access": {
                "()": "uvicorn.logging.AccessFormatter",
                "format": '%(asctime)s [%(name)-13s] %(levelprefix)-4s %(client_addr)s - "%(request_line)s" %(status_code)s',
                "datefmt": "%Y-%m-%d %H:%M:%S",
            },
        },
        "filters": {
            "internal_endpoint_filter": {
                "()": "skyscope.executor.logger.InternalEndpointFilter"
            }
        },
        "handlers": {
            "default": {
                "formatter": "default",
                "class": "logging.StreamHandler",
                "stream": "ext://sys.stderr",
            },
            "access": {
                "formatter": "access",
                "filters": ["internal_endpoint_filter"],
                "class": "logging.StreamHandler",
                "stream": "ext://sys.stdout",
            },
        },
        "root": {"level": "INFO", "handlers": ["default"]},
        "loggers": {
            "uvicorn.error": {
                "level": "INFO",
                "handlers": ["default"],
                "propagate": False,
            },
            "uvicorn.access": {
                "level": "INFO",
                "handlers": ["access"],
                "propagate": False,
            },
        },
    }

    def __init__(self, level="INFO"):
        level = level.upper()
        self.conf = self.template.copy()
        self.conf["root"]["level"] = level
        for logger in self.conf["loggers"].keys():
            self.conf["loggers"][logger]["level"] = level

    def get_config(self):
        return self.conf.copy()
