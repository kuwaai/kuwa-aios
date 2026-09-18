import asyncio
import json
import threading
import time
import unittest
import sys
import os
from unittest.mock import MagicMock, patch, AsyncMock

# Add local src to sys.path
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "src")))

import logging

logging.getLogger("kuwa.kernel.core.scheduler").addHandler(logging.NullHandler())

from kuwa.kernel.core.scheduler import (
    JobScheduler,
)
from kuwa.kernel.core.job import (
    Job,
    JobStateEnum,
    JobQueue,
)
from kuwa.kernel.core.processor import (
    Processor,
    ProcessorList,
)
from kuwa.kernel.core.health import periodic_health_check
from kuwa.kernel.routes.executor import read_executor


def mock_create_task_side_effect(coro, *args, **kwargs):
    if hasattr(coro, "close"):
        coro.close()
    return MagicMock()


class TestJobLifecycle(unittest.IsolatedAsyncioTestCase):
    @patch("kuwa.kernel.core.job.JOBS_ACTIVE")
    @patch("kuwa.kernel.core.job.JOB_WAIT_TIME")
    async def test_mark_processing_updates_wait_and_active_metrics(
        self, mock_wait_time, mock_active
    ):
        mock_active.labels.return_value = MagicMock()
        job = Job(user_uuid="user", form={"name": "test"}, headers={})
        job.mark_processing(MagicMock())
        mock_wait_time.observe.assert_called_once()
        self.assertEqual(job.state, JobStateEnum.PROCESSING)

    @patch("kuwa.kernel.core.job.JOBS_TOTAL")
    @patch("kuwa.kernel.core.job.JOBS_ACTIVE")
    @patch("kuwa.kernel.core.job.JOB_PROCESSING_TIME")
    async def test_mark_completed_from_processing(
        self, mock_processing_time, mock_jobs_active, mock_jobs_total
    ):
        mock_jobs_total.labels.return_value = MagicMock()
        job = Job(user_uuid="user", form={"name": "test"}, headers={})
        job.state = JobStateEnum.PROCESSING
        job.assigned_at = time.time()

        # Test full branch: processor and parent_queue set
        mock_p = MagicMock()
        mock_q = MagicMock()
        job.processor = mock_p
        job.parent_queue = mock_q

        job.mark_completed()
        self.assertEqual(job.state, JobStateEnum.COMPLETED)
        mock_jobs_total.labels.assert_called_with(status="completed")
        mock_p.job_completed.assert_called_once_with(job)
        mock_q.dequeue.assert_called_once_with(job)

    def test_mark_completed_from_pending_explicit(self):
        job = Job(user_uuid="user", form={"name": "test"}, headers={})
        mock_queue = MagicMock()
        job.parent_queue = mock_queue
        job.state = JobStateEnum.PENDING
        job.mark_completed()
        self.assertEqual(job.state, JobStateEnum.COMPLETED)
        mock_queue.dequeue.assert_called_once()

    @patch("kuwa.kernel.core.job.logger")
    async def test_wait_completed_exception_explicit(self, mock_logger):
        job = Job(user_uuid="user", form={"name": "test"}, headers={})
        job.completed.wait = AsyncMock(side_effect=Exception("Wait failed"))
        await job.wait_completed()
        mock_logger.error.assert_called()

    def test_mark_completed_is_idempotent(self):
        job = Job(user_uuid="user", form={"name": "test"}, headers={})
        job.mark_completed()
        job.mark_completed()
        self.assertEqual(job.state, JobStateEnum.COMPLETED)

    @patch("kuwa.kernel.core.job.JOBS_ACTIVE")
    def test_mark_terminated_explicit(self, mock_active):
        mock_active.labels.return_value = MagicMock()
        job = Job(user_uuid="user", form={"name": "test"}, headers={})

        # Test both state branches: PENDING and PROCESSING

        # Branch 1: PENDING
        mock_q = MagicMock()
        job.parent_queue = mock_q
        job.state = JobStateEnum.PENDING
        job.mark_terminated()
        self.assertEqual(job.state, JobStateEnum.TERMINATED)
        mock_active.labels.assert_any_call(status="pending")
        mock_q.dequeue.assert_called_once_with(job)

        # Branch 2: PROCESSING
        job = Job(user_uuid="user", form={"name": "test"}, headers={})
        mock_p = MagicMock()
        job.processor = mock_p
        job.state = JobStateEnum.PROCESSING
        job.mark_terminated()
        self.assertEqual(job.state, JobStateEnum.TERMINATED)
        mock_active.labels.assert_any_call(status="processing")
        mock_p.job_completed.assert_called_once_with(job)


class TestSchedulerQueueInfo(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        # Reset the Singleton instance for testing
        JobScheduler._instances = {}
        self.scheduler = JobScheduler()
        self.scheduler.trigger_schedule = MagicMock()

    def test_update_avg_wait_time(self):
        self.scheduler.update_avg_wait_time(10.0)
        self.assertEqual(self.scheduler.avg_wait_time, 10.0)
        self.assertEqual(self.scheduler.processed_count, 1)

        self.scheduler.update_avg_wait_time(20.0)
        self.assertEqual(self.scheduler.avg_wait_time, 15.0)
        self.assertEqual(self.scheduler.processed_count, 2)

    def test_get_queue_info(self):
        job1 = Job(user_uuid="user1", form={"name": "model1"}, headers={})
        job2 = Job(user_uuid="user2", form={"name": "model1"}, headers={})
        job3 = Job(user_uuid="user3", form={"name": "model2"}, headers={})

        self.scheduler.jobs.enqueue(job1)
        self.scheduler.jobs.enqueue(job2)
        self.scheduler.jobs.enqueue(job3)

        self.scheduler.update_avg_wait_time(10.0)

        # job1 is at index 0 for model1
        idx1, eta1 = self.scheduler.get_queue_info(job1)
        self.assertEqual(idx1, 0)
        self.assertEqual(eta1, 0.0)

        # job2 is at index 1 for model1
        idx2, eta2 = self.scheduler.get_queue_info(job2)
        self.assertEqual(idx2, 1)
        self.assertEqual(eta2, 10.0)

        # job3 is at index 0 for model2
        idx3, eta3 = self.scheduler.get_queue_info(job3)
        self.assertEqual(idx3, 0)
        self.assertEqual(eta3, 0.0)


class TestJobSchedulerCore(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        JobScheduler._instances.clear()
        self.scheduler = JobScheduler()
        self.scheduler.jobs = JobQueue(self.scheduler)
        self.scheduler.processors = ProcessorList(self.scheduler)
        self.scheduler.trigger_schedule = MagicMock()

    @patch("kuwa.kernel.core.processor.asyncio.create_task")
    @patch("kuwa.kernel.core.scheduler.check_all_health")
    async def test_concurrent_scheduling(self, mock_check_all_health, mock_create_task):
        mock_create_task.side_effect = mock_create_task_side_effect

        async def mock_health(endpoints, **kwargs):
            return [True for _ in endpoints]

        mock_check_all_health.side_effect = mock_health

        p1 = Processor(access_code="test", endpoint="http://p1")
        p2 = Processor(access_code="test", endpoint="http://p2")
        self.scheduler.processors._processors = [p1, p2]
        self.scheduler.processors._model_to_processors = {"test": [p1, p2]}

        j1 = Job(user_uuid="user1", form={"name": "test"}, headers={})
        j2 = Job(user_uuid="user2", form={"name": "test"}, headers={})
        j1.state = JobStateEnum.PENDING
        j2.state = JobStateEnum.PENDING
        self.scheduler.jobs._jobs = [j1, j2]
        self.scheduler.jobs._id_to_job = {j1.job_id: j1, j2.job_id: j2}

        await self.scheduler.schedule_jobs()
        self.assertEqual(len(p1.current_jobs) + len(p2.current_jobs), 2)

    @patch("kuwa.kernel.core.processor.asyncio.create_task")
    @patch("kuwa.kernel.core.scheduler.check_all_health")
    async def test_max_jobs_limit(self, mock_check_all_health, mock_create_task):
        mock_create_task.side_effect = mock_create_task_side_effect

        async def mock_health(endpoints, **kwargs):
            return [True for _ in endpoints]

        mock_check_all_health.side_effect = mock_health

        p1 = Processor(access_code="test", endpoint="http://p1", max_jobs=1)
        self.scheduler.processors._processors = [p1]
        self.scheduler.processors._model_to_processors = {"test": [p1]}

        j1 = Job(user_uuid="user1", form={"name": "test"}, headers={})
        j2 = Job(user_uuid="user2", form={"name": "test"}, headers={})
        j1.state = JobStateEnum.PENDING
        j2.state = JobStateEnum.PENDING
        self.scheduler.jobs._jobs = [j1, j2]
        self.scheduler.jobs._id_to_job = {j1.job_id: j1, j2.job_id: j2}

        await self.scheduler.schedule_jobs()
        self.assertEqual(len(p1.current_jobs), 1)

    async def test_read_executor_includes_runtime_metadata(self):
        JobScheduler._instances.clear()
        scheduler = JobScheduler()
        scheduler.processors.clear()
        processor = Processor(access_code="test", endpoint="http://example.com", max_jobs=3)
        scheduler.processors.add(processor)

        response = await read_executor()
        payload = json.loads(response.body.decode())

        self.assertIn("test", payload)
        self.assertEqual(payload["test"][0][1], "READY")
        metadata = payload["test"][0][4]
        self.assertEqual(metadata["req"], 0)
        self.assertEqual(metadata["lim"], 3)
        self.assertEqual(metadata["max_jobs"], 3)
        self.assertTrue(metadata["alive"])
        self.assertIn("current_jobs", metadata)

    @patch("kuwa.kernel.core.processor.asyncio.create_task")
    @patch("kuwa.kernel.core.scheduler.check_all_health")
    async def test_round_robin_prefers_next_processor(self, mock_check_all_health, mock_create_task):
        mock_create_task.side_effect = mock_create_task_side_effect

        async def mock_health(endpoints, **kwargs):
            return [True for _ in endpoints]

        mock_check_all_health.side_effect = mock_health

        p1 = Processor(access_code="test", endpoint="http://p1", max_jobs=2)
        p2 = Processor(access_code="test", endpoint="http://p2", max_jobs=2)
        self.scheduler.processors._processors = [p1, p2]
        self.scheduler.processors._model_to_processors = {"test": [p1, p2]}

        jobs = [Job(user_uuid=f"user{i}", form={"name": "test"}, headers={}) for i in range(3)]
        for job in jobs:
            job.state = JobStateEnum.PENDING
        self.scheduler.jobs._jobs = jobs
        self.scheduler.jobs._id_to_job = {j.job_id: j for j in jobs}

        await self.scheduler.schedule_jobs()

        self.assertEqual(len(p1.current_jobs), 2)
        self.assertEqual(len(p2.current_jobs), 1)

    @patch("kuwa.kernel.core.scheduler.SCHEDULING_ERRORS")
    async def test_schedule_jobs_no_processors(self, mock_errors):
        j = Job("u", {"name": "missing"}, {})
        j.state = JobStateEnum.PENDING
        self.scheduler.jobs._jobs = [j]
        await self.scheduler.schedule_jobs()
        mock_errors.inc.assert_called()

    @patch("kuwa.kernel.core.scheduler.check_all_health")
    @patch("kuwa.kernel.core.scheduler.PROCESSORS_REMOVED")
    async def test_schedule_jobs_pre_connection_failure(
        self, mock_removed, mock_health
    ):
        p = Processor("m", "h")
        self.scheduler.processors.add(p)
        j = Job("u", {"name": "m"}, {})
        j.state = JobStateEnum.PENDING
        self.scheduler.jobs._jobs = [j]

        mock_health.return_value = [False]
        await self.scheduler.schedule_jobs()

        self.assertNotIn(p, self.scheduler.processors)
        mock_removed.labels.assert_called_with(reason="health_check_failed")

    @patch("kuwa.kernel.core.health.check_all_health")
    @patch("kuwa.kernel.core.health.time.monotonic")
    def test_health_check_removes_failed_processors(
        self, mock_time, mock_check_all_health
    ):
        from kuwa.kernel.core.config import (
            EXECUTOR_HEALTH_CHECK_START_PERIOD_SEC,
            EXECUTOR_HEALTH_CHECK_RETRIES,
        )

        mock_time.return_value = EXECUTOR_HEALTH_CHECK_START_PERIOD_SEC + 10

        async def mock_health(endpoints, timeout=10):
            results = []
            for p in endpoints:
                p.failure_count += 1
                results.append(p.failure_count < EXECUTOR_HEALTH_CHECK_RETRIES)
            return results

        mock_check_all_health.side_effect = mock_health

        p1 = Processor(access_code="test", endpoint="http://p1")
        p1.registration_time = 0
        self.scheduler.processors._processors = [p1]
        self.scheduler.processors._model_to_processors = {"test": [p1]}

        for _ in range(EXECUTOR_HEALTH_CHECK_RETRIES):
            periodic_health_check()

        self.assertNotIn(p1, self.scheduler.processors._processors)

    @patch("kuwa.kernel.core.health.check_all_health")
    @patch("kuwa.kernel.core.health.time.monotonic")
    def test_health_check_ignores_start_period(self, mock_time, mock_check_all_health):
        from kuwa.kernel.core.config import EXECUTOR_HEALTH_CHECK_START_PERIOD_SEC

        mock_time.return_value = EXECUTOR_HEALTH_CHECK_START_PERIOD_SEC - 1
        p1 = Processor(access_code="test", endpoint="http://p1")
        p1.registration_time = 0
        self.scheduler.processors._processors = [p1]
        periodic_health_check()
        mock_check_all_health.assert_not_called()


class TestComprehensiveObjects(unittest.TestCase):
    def setUp(self):
        JobScheduler._instances.clear()
        self.scheduler = JobScheduler()
        self.scheduler.trigger_schedule = MagicMock()
        self.patcher_save = patch("kuwa.kernel.core.persistence.save_processor_list")
        self.mock_save = self.patcher_save.start()

    def tearDown(self):
        self.patcher_save.stop()

    @patch("kuwa.kernel.core.processor.asyncio.create_task")
    def test_processor_assign_job_mismatch(self, mock_create_task):
        mock_create_task.side_effect = mock_create_task_side_effect
        p = Processor("m1", "h1")
        j = Job("u", {"name": "m2"}, {})
        p.assign_job(j)
        self.assertEqual(len(p.current_jobs), 0)

    @patch("kuwa.kernel.core.processor.logger")
    def test_processor_job_completed_mismatch(self, mock_logger):
        p = Processor("m", "h")
        j = Job("u", {"name": "m"}, {})
        p.job_completed(j)
        mock_logger.warning.assert_called()

    def test_processor_list_add_duplicate(self):
        pl = self.scheduler.processors
        p = Processor("m", "h")
        pl.add(p)
        pl.add(p)
        self.assertEqual(len(pl), 1)

    def test_processor_list_remove_edge(self):
        pl = self.scheduler.processors
        p = Processor("m", "h")
        pl.add(p)
        # Remove it
        pl.remove(p)
        self.assertNotIn(p, pl)
        # Remove non-existent
        pl.remove(p)

    def test_processor_list_set_all(self):
        pl = self.scheduler.processors
        p = Processor("m", "h")
        pl.set_all([p])
        self.assertEqual(pl.get_all(), [p])

    def test_job_queue_methods(self):
        jq = self.scheduler.jobs
        job = Job("u", {"name": "m"}, {})
        jq.enqueue(job)
        self.assertEqual(len(jq), 1)
        self.assertIs(jq.get_by_id(job.job_id), job)
        self.assertEqual(jq.list_for_user("u"), [job])
        self.assertIn(job, list(jq))

        # Dequeue
        jq.dequeue(job)
        self.assertIsNone(jq.get_by_id(job.job_id))

        # Dequeue with ID only
        jq._id_to_job[job.job_id] = job
        jq.dequeue(job)
        self.assertNotIn(job.job_id, jq._id_to_job)


class TestHealthCore(unittest.IsolatedAsyncioTestCase):
    @patch("kuwa.kernel.core.processor.logger")
    @patch("kuwa.kernel.core.processor.get_base_url")
    async def test_async_health_check_exception(self, mock_get_base, mock_logger):
        mock_get_base.return_value = "http://url"
        mock_session = MagicMock()
        mock_session.get.side_effect = Exception("Down")
        p = Processor("m", "h")
        result = await p.async_health_check(session=mock_session)
        self.assertTrue(result)
        mock_logger.error.assert_called()

    @patch("kuwa.kernel.core.processor.logger")
    @patch("kuwa.kernel.core.processor.get_base_url")
    async def test_processor_health_failure_log(
        self, mock_get_base, mock_logger
    ):
        mock_get_base.return_value = "http://url"
        mock_session = MagicMock()
        mock_resp = MagicMock()
        mock_resp.status = 500
        mock_session.get.return_value.__aenter__ = AsyncMock(return_value=mock_resp)
        mock_session.get.return_value.__aexit__ = AsyncMock(return_value=None)

        p = Processor("m", "h")
        res = await p.async_health_check(session=mock_session)
        self.assertTrue(res)  # failure_count (1) < 5
        self.assertEqual(p.failure_count, 1)
        mock_logger.warning.assert_called()

    @patch("kuwa.kernel.core.processor.logger")
    @patch("kuwa.kernel.core.processor.get_base_url")
    async def test_async_health_check_success_200(
        self, mock_get_base, mock_logger
    ):
        mock_get_base.return_value = "http://url"
        mock_session = MagicMock()
        mock_resp = MagicMock()
        mock_resp.status = 200
        mock_resp.json = AsyncMock(return_value={
            "code": ["m"],
            "req": 0,
            "lim": 1
        })
        mock_session.get.return_value.__aenter__ = AsyncMock(return_value=mock_resp)
        mock_session.get.return_value.__aexit__ = AsyncMock(return_value=None)

        p = Processor("m", "h", max_jobs=1)
        res = await p.async_health_check(session=mock_session)
        self.assertTrue(res)
        self.assertEqual(p.max_jobs, 1)

    @patch("kuwa.kernel.core.processor.logger")
    @patch("kuwa.kernel.core.processor.get_base_url")
    @patch("kuwa.kernel.core.scheduler.JobScheduler")
    async def test_async_health_check_access_code_mismatch(
        self, mock_scheduler, mock_get_base, mock_logger
    ):
        mock_get_base.return_value = "http://url"
        mock_session = MagicMock()
        mock_resp = MagicMock()
        mock_resp.status = 200
        mock_resp.json = AsyncMock(return_value={
            "code": ["different_code"],
            "req": 0,
            "lim": 1
        })
        mock_session.get.return_value.__aenter__ = AsyncMock(return_value=mock_resp)
        mock_session.get.return_value.__aexit__ = AsyncMock(return_value=None)

        p = Processor("m", "h", max_jobs=1)
        
        # Mock JobScheduler().processors.remove
        mock_scheduler_instance = MagicMock()
        mock_scheduler.return_value = mock_scheduler_instance
        
        res = await p.async_health_check(session=mock_session)
        self.assertFalse(res)
        mock_scheduler_instance.processors.remove.assert_called_with(p)
        mock_logger.warning.assert_called_with(
            "Access code mismatch for h. Expected m, got ['different_code']."
        )

    @patch("kuwa.kernel.core.processor.logger")
    @patch("kuwa.kernel.core.processor.get_base_url")
    async def test_async_health_check_updates_max_jobs(
        self, mock_get_base, mock_logger
    ):
        mock_get_base.return_value = "http://url"
        mock_session = MagicMock()
        mock_resp = MagicMock()
        mock_resp.status = 200
        mock_resp.json = AsyncMock(return_value={
            "code": ["m"],
            "req": 0,
            "lim": 5
        })
        mock_session.get.return_value.__aenter__ = AsyncMock(return_value=mock_resp)
        mock_session.get.return_value.__aexit__ = AsyncMock(return_value=None)

        p = Processor("m", "h", max_jobs=1)
        res = await p.async_health_check(session=mock_session)
        self.assertTrue(res)
        self.assertEqual(p.max_jobs, 5)
        mock_logger.info.assert_any_call(
            "Updating max_jobs for m (h): recorded 1, reported 5."
        )

    @patch("kuwa.kernel.core.processor.logger")
    @patch("kuwa.kernel.core.processor.get_base_url")
    async def test_async_health_check_current_jobs_mismatch(
        self, mock_get_base, mock_logger
    ):
        mock_get_base.return_value = "http://url"
        mock_session = MagicMock()
        mock_resp = MagicMock()
        mock_resp.status = 200
        mock_resp.json = AsyncMock(return_value={
            "code": ["m"],
            "req": 3,
            "lim": 5
        })
        mock_session.get.return_value.__aenter__ = AsyncMock(return_value=mock_resp)
        mock_session.get.return_value.__aexit__ = MagicMock() # Use MagicMock for __aexit__ to avoid AsyncMock issues in some python versions if needed, though AsyncMock is fine. Wait, __aexit__ can be MagicMock(return_value=None)

        p = Processor("m", "h", max_jobs=5)
        # p.current_jobs is empty, length 0, mismatch with reported 3
        res = await p.async_health_check(session=mock_session)
        self.assertTrue(res)
        mock_logger.info.assert_any_call(
            "Job count mismatch for m (h): recorded 0, reported 3."
        )

    @patch("kuwa.kernel.core.processor.logger")
    @patch("kuwa.kernel.core.processor.get_base_url")
    async def test_async_health_check_status_204(
        self, mock_get_base, mock_logger
    ):
        mock_get_base.return_value = "http://url"
        mock_session = MagicMock()
        mock_resp = MagicMock()
        mock_resp.status = 204
        mock_session.get.return_value.__aenter__ = AsyncMock(return_value=mock_resp)
        mock_session.get.return_value.__aexit__ = AsyncMock(return_value=None)

        p = Processor("m", "h")
        res = await p.async_health_check(session=mock_session)
        self.assertTrue(res)

    @patch("kuwa.kernel.core.processor.logger")
    @patch("kuwa.kernel.core.processor.get_base_url")
    async def test_async_health_check_json_exception(
        self, mock_get_base, mock_logger
    ):
        mock_get_base.return_value = "http://url"
        mock_session = MagicMock()
        mock_resp = MagicMock()
        mock_resp.status = 200
        mock_resp.json = AsyncMock(side_effect=ValueError("Invalid JSON"))
        mock_session.get.return_value.__aenter__ = AsyncMock(return_value=mock_resp)
        mock_session.get.return_value.__aexit__ = AsyncMock(return_value=None)

        p = Processor("m", "h")
        res = await p.async_health_check(session=mock_session)
        self.assertTrue(res)
        self.assertEqual(p.failure_count, 1)
        mock_logger.error.assert_called()

    @patch("kuwa.kernel.core.processor.aiohttp.ClientSession")
    @patch("kuwa.kernel.core.processor.logger")
    @patch("kuwa.kernel.core.processor.get_base_url")
    async def test_async_health_check_session_none(
        self, mock_get_base, mock_logger, mock_client_session_class
    ):
        mock_get_base.return_value = "http://url"
        
        # Setup mock client session and its get method
        mock_session = MagicMock()
        mock_resp = MagicMock()
        mock_resp.status = 204
        mock_session.get.return_value.__aenter__ = AsyncMock(return_value=mock_resp)
        mock_session.get.return_value.__aexit__ = AsyncMock(return_value=None)
        
        # When ClientSession() is called as context manager, return mock_session
        mock_client_session_class.return_value.__aenter__ = AsyncMock(return_value=mock_session)
        mock_client_session_class.return_value.__aexit__ = AsyncMock(return_value=None)
        
        p = Processor("m", "h")
        res = await p.async_health_check(session=None)
        self.assertTrue(res)


if __name__ == "__main__":
    unittest.main()
