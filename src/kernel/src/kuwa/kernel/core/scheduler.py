from __future__ import annotations

import logging
import asyncio
import threading

from .job import JobStateEnum, Job, JobQueue
from .processor import Processor, ProcessorList
from .health import check_all_health
from .config import (
    EXECUTOR_HEALTH_CHECK_TIMEOUT_SEC,
    EXECUTOR_HEALTH_CHECK_RETRIES,
)
from .metrics import (
    SCHEDULING_ERRORS,
    PROCESSORS_REMOVED,
)

logger = logging.getLogger(__name__)


class SingletonMeta(type):
    _instances = {}

    def __call__(cls, *args, **kwargs):
        key = cls.__name__
        if key not in cls._instances:
            instance = super().__call__(*args, **kwargs)
            cls._instances[key] = instance
        return cls._instances[key]


class JobScheduler(metaclass=SingletonMeta):
    def __init__(self):
        self.lock: threading.RLock = threading.RLock()
        self.jobs: JobQueue = JobQueue(self)
        self.processors: ProcessorList = ProcessorList(self)
        self.avg_wait_time = 0.0
        self.processed_count = 0

    def update_avg_wait_time(self, wait_time):
        with self.lock:
            self.avg_wait_time = (
                self.avg_wait_time * self.processed_count + wait_time
            ) / (self.processed_count + 1)
            self.processed_count += 1

    def get_queue_info(self, job: Job):
        model = job.form.get("name")
        with self.jobs._lock:
            pending_jobs = [
                j
                for j in self.jobs
                if j.state == JobStateEnum.PENDING and j.form.get("name") == model
            ]
            try:
                index = pending_jobs.index(job)
                return index, index * self.avg_wait_time
            except ValueError:
                # Job not in pending list (might be already processing or terminated)
                return 0, 0.0

    def trigger_schedule(self):
        asyncio.create_task(self.schedule_jobs())

    async def schedule_jobs(self):
        candidates: list[tuple[Job, Processor]] = []
        pending_jobs = [j for j in self.jobs if j.state == JobStateEnum.PENDING]
        provisional_busy = {p: len(p.current_jobs) for p in self.processors}

        model_map = self.processors.get_model_map()

        for job in pending_jobs:
            model = job.form.get("name")
            processors = model_map.get(model, [])

            if len(processors) == 0:
                SCHEDULING_ERRORS.inc()
                continue

            for p in processors:
                if provisional_busy[p] < p.max_jobs:
                    candidates.append((job, p))
                    provisional_busy[p] += 1
                    break

        if len(candidates) == 0:
            return

        processors = [processor for _, processor in candidates]
        health_results = await check_all_health(
            processors, timeout=EXECUTOR_HEALTH_CHECK_TIMEOUT_SEC, max_failures=1
        )

        for (job, processor), healthy in zip(candidates, health_results):
            if healthy:
                if (
                    job.state == JobStateEnum.PENDING
                    and processor in self.processors.get_all()
                    and processor.is_idle()
                ):
                    processor.assign_job(job)
            else:
                logger.warning(
                    f"Pre-connection health check failed for {processor} ({processor.failure_count}/{EXECUTOR_HEALTH_CHECK_RETRIES})"
                )
                PROCESSORS_REMOVED.labels(reason="health_check_failed").inc()
                self.processors.remove(processor)

        self.processors.update_metrics()
