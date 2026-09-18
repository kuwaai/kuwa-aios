from __future__ import annotations

import uuid
import time
import logging
import asyncio
import threading
from typing import TYPE_CHECKING
from enum import Enum

from .metrics import JOBS_TOTAL, JOBS_ACTIVE, JOB_WAIT_TIME, JOB_PROCESSING_TIME

if TYPE_CHECKING:
    from .processor import Processor
    from .scheduler import JobScheduler

logger = logging.getLogger(__name__)


class JobStateEnum(str, Enum):
    CREATED = "Created"
    PENDING = "Pending"
    PROCESSING = "Processing"
    COMPLETED = "Completed"
    TERMINATED = "Terminated"

    def __str__(self):
        return self.value


class Job:
    def __init__(self, user_uuid, form, headers):
        self.job_id = str(uuid.uuid4())
        self.headers = headers
        self.form = form
        self.user_uuid = user_uuid
        self.parent_queue: JobQueue | None = None
        self.processor: Processor | None = None
        self.state = JobStateEnum.CREATED
        self.assigned = asyncio.Event()
        self.completed = asyncio.Event()
        self.terminated = asyncio.Event()
        self.created_at = time.time()
        self.assigned_at = None

    def __repr__(self):
        return f"Job(id={self.job_id}, user_uuid={self.user_uuid}, access_code={self.form.get('name')}, state={self.state})"

    def mark_pending(self, queue: JobQueue):
        logger.info(f"{self} marked as pending and added to queue.")
        self.parent_queue = queue
        self.state = JobStateEnum.PENDING
        JOBS_TOTAL.labels(status="pending").inc()
        JOBS_ACTIVE.labels(status="pending").inc()

    def mark_processing(self, processor: Processor):
        logger.info(f"{self} marked as processing by processor {processor}.")
        self.processor = processor
        self.state = JobStateEnum.PROCESSING
        self.assigned.set()
        self.completed.clear()
        self.terminated.clear()
        self.assigned_at = time.time()
        wait_duration = self.assigned_at - self.created_at
        JOB_WAIT_TIME.observe(wait_duration)

        from .scheduler import JobScheduler
        JobScheduler().update_avg_wait_time(wait_duration)

        JOBS_ACTIVE.labels(status="pending").dec()
        JOBS_ACTIVE.labels(status="processing").inc()

    async def wait_completed(self):
        try:
            # Processing in chat.py
            await self.completed.wait()
        except Exception as e:
            logger.error(f"{self} error: {e}")
        finally:
            self.mark_completed()
    
    def mark_completed(self):
        if self.state in (JobStateEnum.COMPLETED, JobStateEnum.TERMINATED):
            return

        logger.info(f"{self} marked as completed.")
        previous_state = self.state
        self.state = JobStateEnum.COMPLETED
        self.assigned.set()
        self.completed.set()
        self.terminated.clear()
        JOBS_TOTAL.labels(status="completed").inc()

        if previous_state == JobStateEnum.PENDING:
            JOBS_ACTIVE.labels(status="pending").dec()
        elif previous_state == JobStateEnum.PROCESSING:
            JOBS_ACTIVE.labels(status="processing").dec()
            if self.assigned_at:
                duration = time.time() - self.assigned_at
                JOB_PROCESSING_TIME.observe(duration)
        
        if self.processor:
            self.processor.job_completed(self)
            self.processor = None
        if self.parent_queue:
            queue = self.parent_queue
            self.parent_queue = None
            queue.dequeue(self)

    def mark_terminated(self):
        if self.state == JobStateEnum.TERMINATED:
            return
            
        logger.info(f"{self} marked as terminated.")
        previous_state = self.state
        self.state = JobStateEnum.TERMINATED
        self.assigned.set()
        self.completed.set()
        self.terminated.set()
        if previous_state == JobStateEnum.PENDING:
            JOBS_ACTIVE.labels(status="pending").dec()
        elif previous_state == JobStateEnum.PROCESSING:
            JOBS_ACTIVE.labels(status="processing").dec()
        if self.processor:
            self.processor.job_completed(self)
            self.processor = None
        if self.parent_queue:
            queue = self.parent_queue
            self.parent_queue = None
            queue.dequeue(self)


class JobQueue:
    def __init__(self, scheduler: JobScheduler):
        self._jobs: list[Job] = []
        self._id_to_job: dict[str, Job] = {}
        self._lock: threading.RLock = threading.RLock()
        self.scheduler: JobScheduler = scheduler

    def enqueue(self, job: Job):
        with self._lock:
            job.mark_pending(self)
            self._jobs.append(job)
            self._id_to_job[job.job_id] = job
            logger.info(f"Enqueued {job}")
        self.scheduler.trigger_schedule()

    def dequeue(self, job: Job):
        with self._lock:
            in_jobs = job in self._jobs
            if in_jobs:
                self._jobs.remove(job)
                logger.info(f"Dequeued {job}")

            if job.job_id in self._id_to_job:
                del self._id_to_job[job.job_id]

            job.mark_terminated()
        self.scheduler.trigger_schedule()

    def get_by_id(self, job_id: str):
        with self._lock:
            return self._id_to_job.get(job_id)

    def list_for_user(self, user_uuid: str):
        with self._lock:
            return [job for job in self._jobs if job.user_uuid == user_uuid]

    def __iter__(self):
        with self._lock:
            return iter(list(self._jobs))

    def __len__(self):
        with self._lock:
            return len(self._jobs)
