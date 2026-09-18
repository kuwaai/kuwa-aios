from __future__ import annotations

import logging
import aiohttp
import asyncio
import time
from typing import TYPE_CHECKING
from .config import (
    PROCESSOR_LIST_FILE,
    EXECUTOR_HEALTH_CHECK_START_PERIOD_SEC,
    EXECUTOR_HEALTH_CHECK_TIMEOUT_SEC,
    EXECUTOR_HEALTH_CHECK_RETRIES,
)

if TYPE_CHECKING:
    from .processor import Processor

logger = logging.getLogger(__name__)


async def check_all_health(processors: list[Processor], timeout: int = 10, max_failures: int | None = None):
    """
    Asynchronously checks the health status of all endpoints.
    """
    async with aiohttp.ClientSession() as session:
        kwargs = {}
        if max_failures is not None:
            kwargs["max_failures"] = max_failures
        tasks = [
            processor.async_health_check(session, timeout, **kwargs) for processor in processors
        ]
        return await asyncio.gather(*tasks)


def periodic_health_check():
    """
    Periodic health check for all registered processors.
    Removes processors that fail the /health endpoint check.
    """
    from .scheduler import JobScheduler
    from .persistence import save_processor_list

    scheduler: JobScheduler = JobScheduler()
    now: float = time.monotonic()
    # Filter out processors in start period
    check_processors = [
        p
        for p in scheduler.processors
        if now - p.registration_time > EXECUTOR_HEALTH_CHECK_START_PERIOD_SEC
    ]

    if len(check_processors) == 0:
        return

    health_results = asyncio.run(
        check_all_health(check_processors, timeout=EXECUTOR_HEALTH_CHECK_TIMEOUT_SEC)
    )

    modified = False
    for p, healthy in zip(check_processors, health_results):
        if healthy:
            continue
        logger.error(
            f"Periodic health check failed for {p.endpoint}, processor removed after {EXECUTOR_HEALTH_CHECK_RETRIES} retries"
        )
        scheduler.processors.remove(p)
        modified = True

    if modified:
        save_processor_list(PROCESSOR_LIST_FILE)
        scheduler.processors.update_metrics()
