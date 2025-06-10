from typing import List, Optional
from enum import Enum
from .util import is_rfc3339
import datetime
import logging

logger = logging.getLogger(__name__)


class BaseChunk:
    def __init__(self, cost: int | None = None):
        self.cost = cost

    def __jsonencode__(self):
        raise NotImplementedError()

    def calculate_cost(self):
        raise NotImplementedError()

    def __len__(self):
        return self.cost if self.cost is not None else self.calculate_cost()


class TextChunk(BaseChunk):
    def __init__(
        self, value: str, annotations: Optional[List] = None, cost: int | None = None
    ):
        super().__init__(cost)
        self.value = value
        self.annotations = annotations if annotations is not None else []
        self.cost = cost

    def __jsonencode__(self):
        return {
            "type": "text",
            "text": {"value": self.value, "annotations": self.annotations},
        }

    def calculate_cost(self):
        return len(self.value)


class ImageURLChunk(BaseChunk):
    def __init__(self, image_url: str, cost: int | None = None):
        super().__init__(cost)
        self.image_url = image_url
        self.cost = cost

    def __jsonencode__(self):
        return {"type": "image_url", "image_url": self.image_url}

    def calculate_cost(self):
        return 0


class AudioURLChunk(BaseChunk):
    def __init__(self, audio_url: str, cost: int | None = None):
        super().__init__(cost)
        self.audio_url = audio_url
        self.cost = cost

    def __jsonencode__(self):
        return {"type": "audio_url", "audio_url": self.audio_url}

    def calculate_cost(self):
        return 0


class LogLevel(Enum):
    EMERGENCY = 0
    ALERT = 1
    CRITICAL = 2
    ERROR = 3
    WARNING = 4
    NOTICE = 5
    INFO = 6
    DEBUG = 7

    def __str__(self):
        return self.name.upper()


class LogChunk(BaseChunk):
    def __init__(
        self,
        text: str,
        level: LogLevel = LogLevel.INFO,
        timestamp: str | None = None,
        cost: int | None = None,
    ):
        super().__init__(cost)
        self.text = text
        self.level = level

        if timestamp is not None and not is_rfc3339(timestamp):
            logger.warn(
                f'"{timestamp}" is not in RFC3339 format. Will use the current timestamp.'
            )
            timestamp = None

        # Use the current timestamp
        if timestamp is None:
            timestamp = datetime.datetime.now(datetime.timezone.utc).isoformat()

        self.timestamp = timestamp

    def __jsonencode__(self):
        return {
            "type": "log",
            "log": {
                "text": self.text,
                "level": str(self.level),
                "timestamp": self.timestamp,
            },
        }

    def calculate_cost(self):
        return 0


class ProgressChunk(BaseChunk):
    def __init__(
        self,
        position: int,
        total: int,
        desc: Optional[str] = None,
        postfix: Optional[str] = None,
        cost: int | None = None,
    ):
        super().__init__(cost)
        self.position = position
        self.total = total
        self.desc = desc
        self.postfix = postfix

    def __jsonencode__(self):
        return {
            "type": "progress",
            "progress": {
                "position": self.position,
                "total": self.total,
                "desc": self.desc,
                "postfix": self.postfix,
            },
        }

    def calculate_cost(self):
        return 0


class RefusalChunk(BaseChunk):
    def __init__(self, text: str, cost: int | None = None):
        super().__init__(cost)
        self.text = text

    def __jsonencode__(self):
        return {"type": "refusal", "refusal": {"text": self.text}}

    def calculate_cost(self):
        return 0


class ExitCodeChunk(BaseChunk):
    OK = 0
    COMPLETE = 0
    INCOMPLETE = 1
    FAILURE = 1024
    def __init__(self, exit_code: int, cost: int | None = None):
        super().__init__(cost)
        self.exit_code = exit_code

    def __jsonencode__(self):
        return {"type": "exit_code", "exit_code": self.exit_code}

    def calculate_cost(self):
        return 0
