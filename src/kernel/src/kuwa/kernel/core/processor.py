from __future__ import annotations

import time
import logging
import asyncio
import threading
import aiohttp
from typing import TYPE_CHECKING

from .config import EXECUTOR_HEALTH_CHECK_RETRIES, PROCESSOR_LIST_FILE
from .metrics import PROCESSORS_REGISTERED, PROCESSORS_IDLE, PROCESSOR_HEALTH_CHECKS

if TYPE_CHECKING:
    from .job import Job
    from .scheduler import JobScheduler

from .utils import get_base_url

logger = logging.getLogger(__name__)


class Processor:
    def __init__(self, access_code: str, endpoint: str, max_jobs: int = 1):
        self.access_code: str = access_code
        self.endpoint: str = endpoint
        self.max_jobs: int = int(max_jobs)
        self.current_jobs: list[Job] = []
        self.failure_count: int = 0
        self.registration_time: float = time.monotonic()
        PROCESSORS_REGISTERED.labels(model_id=access_code).inc()
        PROCESSORS_IDLE.labels(model_id=access_code).inc()

    def __repr__(self):
        return f"Processor(access_code={self.access_code}, current_jobs={len(self.current_jobs)}/{self.max_jobs})"

    def is_idle(self):
        return len(self.current_jobs) < self.max_jobs

    def assign_job(self, job: Job):
        if not self.is_idle() or job.form.get("name") != self.access_code:
            logger.warning(
                f"{self} cannot handle job {job} since it is either busy or the access code does not match."
            )
            return

        logger.info(f"{self} started job {job}")
        self.current_jobs.append(job)
        if not self.is_idle():
            PROCESSORS_IDLE.labels(model_id=self.access_code).dec()

        job.mark_processing(self)
        asyncio.create_task(job.wait_completed())

    def job_completed(self, job: Job):
        if job not in self.current_jobs:
            logger.warning(f"{self} has no {job} to complete.")
            return
        logger.info(f"{self} completed {job}.")
        was_full = not self.is_idle()
        job.processor = None
        self.current_jobs.remove(job)

        if was_full and self.is_idle():
            PROCESSORS_IDLE.labels(model_id=self.access_code).inc()

    async def async_health_check(
        self,
        session: aiohttp.ClientSession | None = None,
        timeout: int = 10,
        max_failures: int = EXECUTOR_HEALTH_CHECK_RETRIES,
    ):
        """
        Asynchronous health check for the processor.
        Return whether the processor is healthy and update failure_count accordingly.
        """
        healthy_check_url: str = get_base_url(self.endpoint) + "/health"
        mismatch = False

        async def _check(client: aiohttp.ClientSession) -> bool:
            nonlocal mismatch
            async with client.get(healthy_check_url, timeout=timeout) as resp:
                if resp.status == 204:
                    return True
                if resp.status == 200:
                    try:
                        report = await resp.json()
                        reported_codes = report.get("code", [])
                        if self.access_code not in reported_codes:
                            logger.warning(
                                f"Access code mismatch for {self.endpoint}. Expected {self.access_code}, got {reported_codes}."
                            )
                            from .scheduler import JobScheduler
                            JobScheduler().processors.remove(self)
                            mismatch = True
                            return False

                        reported_max_jobs = report.get("lim")
                        if reported_max_jobs is not None:
                            reported_max_jobs = int(reported_max_jobs)
                            if self.max_jobs != reported_max_jobs:
                                logger.info(
                                    f"Updating max_jobs for {self.access_code} ({self.endpoint}): recorded {self.max_jobs}, reported {reported_max_jobs}."
                                )
                                self.max_jobs = reported_max_jobs

                        reported_current_jobs = report.get("req")
                        if reported_current_jobs is not None:
                            reported_current_jobs = int(reported_current_jobs)
                            if len(self.current_jobs) != reported_current_jobs:
                                logger.info(
                                    f"Job count mismatch for {self.access_code} ({self.endpoint}): recorded {len(self.current_jobs)}, reported {reported_current_jobs}."
                                )
                        return True
                    except Exception as e:
                        logger.error(f"Error decoding or handling health check report from {healthy_check_url}: {e}")
                        return False
                return False

        healthy = False
        try:
            if session is None:
                async with aiohttp.ClientSession() as client:
                    healthy = await _check(client)
            else:
                healthy = await _check(session)
        except Exception as e:
            logger.error(f"Health check failed for {healthy_check_url}: {e}")

        if mismatch:
            return False

        if healthy:
            self.failure_count = 0
        else:
            self.failure_count += 1
            logger.warning(
                f"{self} healthy check failed. ({self.failure_count}/{max_failures})"
            )
        return self.failure_count < max_failures


class ProcessorList:
    def __init__(self, scheduler: JobScheduler):
        self._processors: list[Processor] = []
        self._model_to_processors: dict[str, list[Processor]] = {}
        self._lock: threading.RLock = threading.RLock()
        self.scheduler: JobScheduler = scheduler

    def add(self, processor: Processor):
        with self._lock:
            if processor in self._processors:
                return
            self._processors.append(processor)
            self._model_to_processors.setdefault(processor.access_code, []).append(
                processor
            )
            logger.info(f"Added {processor}")
            self._update_metrics_locked()

            # Import dynamically to avoid circular dependencies at load time
            from .persistence import save_processor_list

            save_processor_list(PROCESSOR_LIST_FILE)
            self.scheduler.trigger_schedule()

    def remove(self, processor: Processor):
        with self._lock:
            if processor not in self._processors:
                return
            PROCESSORS_REGISTERED.labels(model_id=processor.access_code).dec()
            if processor.is_idle():
                PROCESSORS_IDLE.labels(model_id=processor.access_code).dec()
            self._processors.remove(processor)

            # Remove from model_to_processors mapping
            if processor.access_code in self._model_to_processors:
                self._model_to_processors[processor.access_code].remove(processor)
                if not self._model_to_processors[processor.access_code]:
                    del self._model_to_processors[processor.access_code]

            logger.info(f"Removed processor {processor}")
            self._update_metrics_locked()

            # Import dynamically to avoid circular dependencies at load time
            from .persistence import save_processor_list

            save_processor_list(PROCESSOR_LIST_FILE)

    def clear(self):
        with self._lock:
            self._processors = []
            self._model_to_processors = {}
            self._update_metrics_locked()

    def set_all(self, processors: list[Processor]):
        with self._lock:
            self._processors = processors
            self._model_to_processors = {}
            for p in self._processors:
                self._model_to_processors.setdefault(p.access_code, []).append(p)
            self._update_metrics_locked()

            # Import dynamically to avoid circular dependencies at load time
            from .persistence import save_processor_list

            save_processor_list(PROCESSOR_LIST_FILE)

    def get_all(self):
        with self._lock:
            return list(self._processors)

    def get_model_map(self):
        """Returns a read-only view of the model map."""
        with self._lock:
            # Return a copy to ensure it's read-only for the caller
            return {k: list(v) for k, v in self._model_to_processors.items()}

    def _update_metrics_locked(self):
        healthy = len([p for p in self._processors if p.failure_count == 0])
        unhealthy = len([p for p in self._processors if p.failure_count > 0])
        PROCESSOR_HEALTH_CHECKS.labels(status="healthy").set(healthy)
        PROCESSOR_HEALTH_CHECKS.labels(status="unhealthy").set(unhealthy)

        # Update registered count per model
        counts: dict[str, int] = {}
        for p in self._processors:
            counts[p.access_code] = counts.get(p.access_code, 0) + 1

        for model_id, count in counts.items():
            PROCESSORS_REGISTERED.labels(model_id=model_id).set(count)

    def update_metrics(self):
        with self._lock:
            self._update_metrics_locked()

    def __iter__(self):
        with self._lock:
            return iter(list(self._processors))

    def __len__(self):
        with self._lock:
            return len(self._processors)
