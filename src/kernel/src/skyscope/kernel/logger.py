import logging
import os
import re
import uvicorn
from datetime import datetime

class InternalEndpointFilter(logging.Filter):
    """
    Filter out internal endpoint from access log.
    """

    health_check_endpoint = '/health'
    prometheus_endpoint ='/metrics'

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

class ANSIEscapeCodeRemover:
    ANSI_ESCAPE = re.compile(r'\x1B\[[0-?]*[ -/]*[@-~]')
    @staticmethod
    def remove(msg):
        if re.search(r'\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3} - - \[\d{2}/[A-Za-z]{3}/\d{4} \d{2}:\d{2}:\d{2}\] "[^"]*" \d{3} -', msg):
            msg = re.sub(r'\[\d{1,2}/[A-Za-z]{3}/\d{4} \d{2}:\d{2}:\d{2}\]', "", msg).replace(" - -  ", " -> ")[:-2]
        return ANSIEscapeCodeRemover.ANSI_ESCAPE.sub('', msg)

class DefaultFileFormatter(uvicorn.logging.ColourizedFormatter):
    def __init__(self, fmt=None, datefmt=None, style='%', validate=True):
        super().__init__(fmt, datefmt, style, validate)

    def format(self, record):
        message = super().format(record)
        return ANSIEscapeCodeRemover.remove(message)

class AccessFileFormatter(uvicorn.logging.AccessFormatter):
    def __init__(self, fmt=None, datefmt=None, style='%', validate=True):
        super().__init__(fmt, datefmt, style, validate)

    def format(self, record):
        message = super().format(record)
        return ANSIEscapeCodeRemover.remove(message)

class KernelLoggerFactory:

    template = {
        'version': 1,
        'disable_existing_loggers': False,
        'formatters': {
            'default': {
                '()': 'uvicorn.logging.ColourizedFormatter',
                'format': '%(asctime)s [%(name)-13s] %(levelprefix)-4s %(message)s',
                'datefmt': '%Y-%m-%d %H:%M:%S'
            },
            'access': {
                '()': 'uvicorn.logging.AccessFormatter',
                'format': '%(asctime)s [%(name)-13s] %(levelprefix)-4s %(client_addr)s - "%(request_line)s" %(status_code)s',
                'datefmt': '%Y-%m-%d %H:%M:%S'
            },
            'default_file': {
                '()': 'skyscope.kernel.logger.DefaultFileFormatter',
                'format': '%(asctime)s [%(name)-13s] %(levelprefix)-4s %(message)s',
                'datefmt': '%Y-%m-%d %H:%M:%S'
            },
            'access_file': {
                '()': 'skyscope.kernel.logger.AccessFileFormatter',
                'format': '%(asctime)s [%(name)-13s] %(levelprefix)-4s %(client_addr)s - "%(request_line)s" %(status_code)s',
                'datefmt': '%Y-%m-%d %H:%M:%S'
            }
            
        },
        'filters': {
            'internal_endpoint_filter': {
                '()': 'skyscope.kernel.logger.InternalEndpointFilter'
            }
        },
        'handlers': {
            'default': {
                'formatter': 'default',
                'class': 'logging.StreamHandler',
                'stream': 'ext://sys.stderr'
            },
            'access': {
                'formatter': 'access',
                'filters': ['internal_endpoint_filter'],
                'class': 'logging.StreamHandler',
                'stream': 'ext://sys.stdout'
            },
            'default_file': {
                'formatter': 'default_file',
                'class': 'logging.FileHandler',
                'filename': ''
            },
            'access_file': {
                'formatter': 'access_file',
                'filters': ['internal_endpoint_filter'],
                'class': 'logging.FileHandler',
                'filename': ''
            }
        },
        'root': {
            'level': 'INFO',
            'handlers': ['default', 'default_file']
        },
        'loggers': {
            'uvicorn.error': {
                'level': 'INFO',
                'handlers': ['default', 'default_file'],
                'propagate': False
            },
            'uvicorn.access': {
                'level': 'INFO',
                'handlers': ['access', 'access_file'],
                'propagate': False
            },
        }
    }

    def __init__(self, level="INFO", log_dir="logs", datetime_format="%Y-%m-%d_%H-%M-%S.%f"):
        level = level.upper()
        self.conf = self.template.copy()
        self.conf["root"]["level"] = level
        for logger in self.conf["loggers"].keys():
            self.conf["loggers"][logger]["level"] = level
        
        formatted_date = datetime.now().strftime(datetime_format)
        if not os.path.exists(log_dir): os.mkdir(log_dir)
        self.log_file_path = os.path.join(log_dir, f"{formatted_date}.log")
        self.conf["handlers"]["default_file"]["filename"] = self.log_file_path
        self.conf["handlers"]["access_file"]["filename"] = self.log_file_path
    
    def get_config(self):
        return self.conf.copy()
