import unittest
import asyncio
import os
import gzip
import pickle
import json
from unittest.mock import MagicMock, patch, AsyncMock
import sys
import threading
from starlette.datastructures import FormData

# Add src/kernel/src to sys.path
sys.path.append(os.path.join(os.path.dirname(__file__), "..", "src"))

from kuwa.kernel.core.scheduler import JobScheduler
from kuwa.kernel.core.job import Job, JobStateEnum, JobQueue
from kuwa.kernel.core.processor import Processor, ProcessorList
from kuwa.kernel.core.persistence import (
    save_processor_list,
    load_processor_list_from_file,
    load_processor_list,
)
from kuwa.kernel.routes.chat import completions, NonBufferedStreamingResponse
from kuwa.kernel.routes.executor import register, unregister


def mock_create_task_side_effect(coro, *args, **kwargs):
    if hasattr(coro, "close"):
        coro.close()
    return MagicMock()


class TestPersistence(unittest.TestCase):
    @classmethod
    def cleanup_artifacts(cls):
        import os
        artifacts = ["records.pickle", "result.log", "test_records.pickle", "test_records.pickle.gz"]
        for f in artifacts:
            try:
                if os.path.exists(f):
                    os.remove(f)
            except Exception:
                pass

    @classmethod
    def setUpClass(cls):
        cls.cleanup_artifacts()

    @classmethod
    def tearDownClass(cls):
        cls.cleanup_artifacts()

    def setUp(self):
        JobScheduler._instances.clear()
        self.scheduler = JobScheduler()
        self.scheduler.jobs = JobQueue(self.scheduler)
        self.scheduler.processors = ProcessorList(self.scheduler)
        self.scheduler.trigger_schedule = MagicMock()
        self.test_filename = "test_records.pickle.gz"

        self.patcher_save = patch("kuwa.kernel.routes.executor.save_processor_list")
        self.mock_save = self.patcher_save.start()

    def tearDown(self):
        self.patcher_save.stop()
        if os.path.exists(self.test_filename):
            os.remove(self.test_filename)

    def test_save_and_load_processor_list_from_file(self):
        p1 = Processor("gpt-4", "http://localhost:8000")
        self.scheduler.processors.add(p1)

        # Test save_processor_list
        save_processor_list(self.test_filename)
        self.assertTrue(os.path.exists(self.test_filename))

        # Test load_processor_list_from_file
        loaded_processors = load_processor_list_from_file(self.test_filename)
        self.assertEqual(len(loaded_processors), 1)
        self.assertEqual(loaded_processors[0].access_code, "gpt-4")
        self.assertEqual(loaded_processors[0].endpoint, "http://localhost:8000")

    @patch("kuwa.kernel.core.persistence.check_all_health")
    def test_load_processor_list_filtering(self, mock_check_all_health):
        p1 = Processor("gpt-4", "http://p1")
        p2 = Processor("claude-3", "http://p2")
        records = [p1, p2]

        # Mock health check: gpt-4 is healthy (True), claude-3 is unhealthy (False)
        async def mock_health(processors, **kwargs):
            return [p.access_code == "gpt-4" for p in processors]

        mock_check_all_health.side_effect = mock_health

        # Test load_processor_list
        load_processor_list(records)

        # Check that only p1 is retained
        loaded = self.scheduler.processors.get_all()
        self.assertEqual(len(loaded), 1)
        self.assertEqual(loaded[0].access_code, "gpt-4")

    def test_load_processor_list_corrupted_pickle(self):
        # Passing an invalid/corrupted records object (like a string) should raise TypeError and be caught gracefully
        response = load_processor_list("invalid-corrupted-record")
        self.assertIsNone(response)

    @patch("kuwa.kernel.core.persistence.gzip.open")
    def test_save_processor_list_exception(self, mock_gzip_open):
        # Force save_processor_list to raise an exception
        mock_gzip_open.side_effect = IOError("Permission Denied")
        # Should not raise an exception, but log it and return
        save_processor_list(self.test_filename)

    # --- Processor API Timeout Health Check & Re-register Test ---
    @patch("kuwa.kernel.core.health.check_all_health")
    @patch("kuwa.kernel.core.health.time.monotonic")
    def test_processor_timeout_and_re_register(self, mock_time, mock_check_all_health):
        from kuwa.kernel.core.config import (
            EXECUTOR_HEALTH_CHECK_START_PERIOD_SEC,
            EXECUTOR_HEALTH_CHECK_RETRIES,
        )
        from kuwa.kernel.core.health import periodic_health_check

        # Bypass start period
        mock_time.return_value = EXECUTOR_HEALTH_CHECK_START_PERIOD_SEC + 10

        # 1. Add Processor (gpt-4)
        p = Processor(access_code="gpt-4", endpoint="http://localhost:8000", max_jobs=1)
        p.registration_time = 0
        self.scheduler.processors.add(p)
        self.assertIn(p, self.scheduler.processors.get_all())

        # 2. Simulate API timeouts (mock_health returns False)
        async def mock_unhealthy(endpoints, **kwargs):
            results = []
            for ep in endpoints:
                ep.failure_count += 1
                results.append(ep.failure_count < EXECUTOR_HEALTH_CHECK_RETRIES)
            return results

        mock_check_all_health.side_effect = mock_unhealthy

        # Trigger health check failures to reach threshold (should remove processor)
        for _ in range(EXECUTOR_HEALTH_CHECK_RETRIES):
            periodic_health_check()

        self.assertNotIn(p, self.scheduler.processors.get_all())
        self.assertEqual(p.failure_count, EXECUTOR_HEALTH_CHECK_RETRIES)

        # 3. Simulate the processor trying to re-register after a while
        mock_reg_request = MagicMock()
        mock_reg_request.form = AsyncMock(
            return_value=FormData(
                [
                    ("name", "gpt-4"),
                    ("endpoint", "http://localhost:8000"),
                    ("limit", "1"),
                ]
            )
        )

        # Calling register route again
        reg_response = asyncio.run(register(mock_reg_request))
        self.assertEqual(reg_response, "Success")

        # Verify it is successfully added back and failure_count is reset to 0
        processors = self.scheduler.processors.get_all()
        self.assertEqual(len(processors), 1)
        re_registered_p = processors[0]
        self.assertEqual(re_registered_p.access_code, "gpt-4")
        self.assertEqual(re_registered_p.failure_count, 0)


class TestSystemIntegration(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        JobScheduler._instances.clear()
        self.scheduler = JobScheduler()
        self.scheduler.jobs = JobQueue(self.scheduler)
        self.scheduler.processors = ProcessorList(self.scheduler)
        self.scheduler.trigger_schedule = MagicMock()

        # Disable save file writes
        self.patcher_save = patch("kuwa.kernel.routes.executor.save_processor_list")
        self.mock_save = self.patcher_save.start()

    def tearDown(self):
        self.patcher_save.stop()

    @patch("kuwa.kernel.core.scheduler.asyncio.create_task")
    @patch("kuwa.kernel.core.scheduler.check_all_health")
    @patch("kuwa.kernel.routes.chat.completions_backend")
    async def test_scheduler_chat_and_executor_interaction(
        self, mock_completions_backend, mock_check_all_health, mock_create_task
    ):
        mock_create_task.side_effect = mock_create_task_side_effect

        # Mock completions_backend to act as an empty async generator
        async def mock_backend_gen(*args, **kwargs):
            if False:
                yield

        mock_completions_backend.side_effect = mock_backend_gen

        # 1. Register a new executor via register route in executor route module
        mock_reg_request = MagicMock()
        mock_reg_request.form = AsyncMock(
            return_value=FormData(
                [
                    ("name", "gpt-4"),
                    ("endpoint", "http://localhost:8000"),
                    ("limit", "1"),
                ]
            )
        )
        reg_response = await register(mock_reg_request)
        self.assertEqual(reg_response, "Success")

        processors = self.scheduler.processors.get_all()
        self.assertEqual(len(processors), 1)
        p = processors[0]
        self.assertEqual(p.access_code, "gpt-4")
        self.assertTrue(p.is_idle())

        # 2. Client submits a completion request via completions route in chat route module
        mock_chat_request = MagicMock()
        mock_chat_request.form = AsyncMock(
            return_value=FormData([("user_id", "user1"), ("input", "[]")])
        )
        mock_chat_request.headers = {"name": "gpt-4"}
        mock_chat_request.scope = {}

        # Mock health check to return True (healthy)
        async def mock_health(endpoints, **kwargs):
            return [True for _ in endpoints]

        mock_check_all_health.side_effect = mock_health

        with patch("kuwa.kernel.routes.chat.Job") as mock_job_class:
            mock_job = MagicMock()
            mock_job.job_id = "job1"
            mock_job.assigned.wait = AsyncMock()
            mock_job.terminated.wait = AsyncMock()
            mock_job.form = {"input": "[]", "name": "gpt-4"}
            mock_job_class.return_value = mock_job

            self.scheduler.jobs.enqueue = MagicMock()

            response = await completions(mock_chat_request)
            self.assertIsInstance(response, NonBufferedStreamingResponse)

            # Consume the event stream generator to run its code and trigger enqueue
            async for _ in response.body_iterator:
                pass

            # Verifies that completions enqueued the job inside JobScheduler
            self.scheduler.jobs.enqueue.assert_called_once_with(mock_job)

    @patch("kuwa.kernel.core.scheduler.check_all_health")
    @patch("kuwa.kernel.routes.chat.completions_backend")
    async def test_integration_no_resource_leakage_on_completion(
        self, mock_completions_backend, mock_check_all_health
    ):
        # Restore real trigger_schedule for this test to allow scheduling to run
        self.scheduler.trigger_schedule = lambda: asyncio.create_task(self.scheduler.schedule_jobs())

        # 1. Register the processor in kernel
        mock_reg_request = MagicMock()
        mock_reg_request.form = AsyncMock(
            return_value=FormData(
                [
                    ("name", "gpt-4"),
                    ("endpoint", "http://localhost:8000"),
                    ("limit", "1"),
                ]
            )
        )
        await register(mock_reg_request)
        p = self.scheduler.processors.get_all()[0]
        self.assertEqual(len(p.current_jobs), 0)

        # 2. Mock health check to return True (healthy)
        mock_check_all_health.return_value = [True]

        # Mock the backend generator to yield some text
        async def mock_backend_gen(*args, **kwargs):
            yield "chunk1"
            yield "chunk2"

        mock_completions_backend.side_effect = mock_backend_gen

        # 3. Submit a real completion request
        mock_chat_request = MagicMock()
        mock_chat_request.form = AsyncMock(
            return_value=FormData(
                [
                    ("user_id", "user1"),
                    ("input", json.dumps([{"isbot": False, "msg": "hello"}])),
                    ("name", "gpt-4"),
                ]
            )
        )
        mock_chat_request.headers = {}
        mock_chat_request.scope = {}

        # Call the completions endpoint to get response
        response = await completions(mock_chat_request)
        self.assertIsInstance(response, NonBufferedStreamingResponse)

        # Consume the stream and assert in-flight vs final counts
        chunks_collected = []
        async for chunk in response.body_iterator:
            # Skip status chunks for the active job count assertion
            if isinstance(chunk, bytes):
                chunk_str = chunk.decode("utf-8")
            else:
                chunk_str = chunk
            
            if '"type": "status"' in chunk_str:
                continue
                
            chunks_collected.append(chunk_str)
            # While the stream is active, the processor must have exactly 1 active job
            self.assertEqual(len(p.current_jobs), 1)

        # Assert: the stream was consumed successfully
        self.assertEqual(chunks_collected, ["chunk1", "chunk2"])

        # Assert: After completion, the processor count must cleanly drop back to 0 (No Leak!)
        self.assertEqual(len(p.current_jobs), 0)
        self.assertEqual(len(self.scheduler.jobs), 0)


if __name__ == "__main__":
    unittest.main()
