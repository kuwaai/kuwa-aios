import unittest
import asyncio
from unittest.mock import MagicMock, patch, AsyncMock
from fastapi.responses import (
    JSONResponse,
    HTMLResponse,
    PlainTextResponse,
    RedirectResponse,
)
from starlette.datastructures import FormData
import sys
import os
import json

# Add src/kernel/src to sys.path
sys.path.append(os.path.join(os.path.dirname(__file__), "..", "src"))

from kuwa.kernel.routes.executor import (
    register,
    unregister,
    debug,
    list_executor,
    read_executor,
    create_executor,
    delete_executor,
    update_executor,
    shutdown_executor,
)
from kuwa.kernel.core.scheduler import JobScheduler, Processor


class TestExecutorRoute(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        JobScheduler._instances.clear()
        self.scheduler = JobScheduler()
        self.scheduler.processors.clear()

        # Disable file writes for stability in tests
        self.patcher_save = patch("kuwa.kernel.routes.executor.save_processor_list")
        self.mock_save = self.patcher_save.start()

    def tearDown(self):
        self.patcher_save.stop()

    async def test_register_new_processor(self):
        # Mock request
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [
                    ("name", "gpt-4"),
                    ("endpoint", "http://localhost:8000"),
                    ("limit", "2"),
                ]
            )
        )

        response = await register(mock_request)
        self.assertEqual(response, "Success")

        # Verify it was added to scheduler
        processors = self.scheduler.processors.get_all()
        self.assertEqual(len(processors), 1)
        p = processors[0]
        self.assertEqual(p.access_code, "gpt-4")
        self.assertEqual(p.endpoint, "http://localhost:8000")
        self.assertEqual(p.max_jobs, 2)

    async def test_register_re_register_existing(self):
        p = Processor("gpt-4", "http://localhost:8000", max_jobs=1)
        p.failure_count = 3
        self.scheduler.processors.add(p)

        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [("name", "gpt-4"), ("endpoint", "http://localhost:8000")]
            )
        )

        response = await register(mock_request)
        self.assertEqual(response, "Success")
        self.assertEqual(p.failure_count, 0)  # failure count reset

    async def test_register_with_mismatching_endpoint_in_loop(self):
        # Multiple workers may share an access code while using different ports.
        p1 = Processor("gpt-4", "http://localhost:9000")
        self.scheduler.processors.add(p1)

        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [("name", "gpt-4"), ("endpoint", "http://localhost:8000")]
            )
        )

        response = await register(mock_request)
        self.assertEqual(response, "Success")
        processors = self.scheduler.processors.get_all()
        self.assertEqual(len(processors), 2)
        self.assertEqual({p.endpoint for p in processors}, {
            "http://localhost:9000", "http://localhost:8000"
        })

    async def test_register_access_code_mismatch_replaces_processor(self):
        p = Processor("gpt-4", "http://localhost:8000", max_jobs=1)
        self.scheduler.processors.add(p)

        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [("name", "claude-3"), ("endpoint", "http://localhost:8000"), ("limit", "3")]
            )
        )

        response = await register(mock_request)
        self.assertEqual(response, "Success")

        # Verify old was removed and new was added
        processors = self.scheduler.processors.get_all()
        self.assertEqual(len(processors), 1)
        new_p = processors[0]
        self.assertEqual(new_p.access_code, "claude-3")
        self.assertEqual(new_p.endpoint, "http://localhost:8000")
        self.assertEqual(new_p.max_jobs, 3)

    async def test_register_invalid_endpoint(self):
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData([("name", "gpt-4"), ("endpoint", "")])
        )

        response = await register(mock_request)
        self.assertEqual(response, "Failed")

    async def test_register_none_values(self):
        # Test case where name is missing/None
        mock_request_no_name = MagicMock()
        mock_request_no_name.form = AsyncMock(
            return_value=FormData([("endpoint", "http://localhost:8000")])
        )
        response = await register(mock_request_no_name)
        self.assertEqual(response, "Failed")

        # Test case where endpoint is missing/None
        mock_request_no_endpoint = MagicMock()
        mock_request_no_endpoint.form = AsyncMock(
            return_value=FormData([("name", "gpt-4")])
        )
        response = await register(mock_request_no_endpoint)
        self.assertEqual(response, "Failed")

    async def test_unregister_existing_processor(self):
        p = Processor("gpt-4", "http://localhost:8000")
        self.scheduler.processors.add(p)

        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [("name", "gpt-4"), ("endpoint", "http://localhost:8000")]
            )
        )

        response = await unregister(mock_request)
        self.assertEqual(response, "Success")
        self.assertNotIn(p, self.scheduler.processors.get_all())

    async def test_unregister_existing_processor_with_executor_path(self):
        p = Processor("gpt-4", "http://localhost:8000/chat")
        self.scheduler.processors.add(p)

        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [("name", "gpt-4"), ("endpoint", "http://localhost:8000/chat")]
            )
        )

        response = await unregister(mock_request)
        self.assertEqual(response, "Success")
        self.assertNotIn(p, self.scheduler.processors.get_all())

    async def test_unregister_endpoint_mismatch_keeps_processor(self):
        p = Processor("gpt-4", "http://localhost:8000/chat")
        self.scheduler.processors.add(p)

        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [("name", "gpt-4"), ("endpoint", "http://localhost:8000/old-chat")]
            )
        )

        response = await unregister(mock_request)
        self.assertEqual(response, "Failed")
        self.assertIn(p, self.scheduler.processors.get_all())

    async def test_register_keeps_multiple_processors_for_one_access_code(self):
        first = Processor("gpt-4", "http://localhost:8000/chat")
        self.scheduler.processors.add(first)

        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [("name", "gpt-4"), ("endpoint", "http://localhost:9000/chat")]
            )
        )

        response = await register(mock_request)
        self.assertEqual(response, "Success")
        processors = self.scheduler.processors.get_all()
        self.assertEqual(len(processors), 2)
        self.assertEqual({p.endpoint for p in processors}, {
            "http://localhost:8000/chat", "http://localhost:9000/chat"
        })

    async def test_register_keeps_same_access_code_on_new_endpoint(self):
        old = Processor("gpt-4", "http://localhost:8000/chat")
        self.scheduler.processors.add(old)

        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [("name", "gpt-4"), ("endpoint", "http://localhost:9000/chat")]
            )
        )

        response = await register(mock_request)
        self.assertEqual(response, "Success")
        processors = self.scheduler.processors.get_all()
        self.assertEqual(len(processors), 2)
        self.assertEqual({p.endpoint for p in processors}, {
            "http://localhost:8000/chat", "http://localhost:9000/chat"
        })

    async def test_unregister_none_values(self):
        # Test case where name is missing/None
        mock_request_no_name = MagicMock()
        mock_request_no_name.form = AsyncMock(
            return_value=FormData([("endpoint", "http://localhost:8000")])
        )
        response = await unregister(mock_request_no_name)
        self.assertEqual(response, "Failed")

        # Test case where endpoint is missing/None
        mock_request_no_endpoint = MagicMock()
        mock_request_no_endpoint.form = AsyncMock(
            return_value=FormData([("name", "gpt-4")])
        )
        response = await unregister(mock_request_no_endpoint)
        self.assertEqual(response, "Failed")

    async def test_unregister_non_existent(self):
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [("name", "gpt-4"), ("endpoint", "http://localhost:8000")]
            )
        )

        response = await unregister(mock_request)
        self.assertEqual(response, "Failed")

    async def test_debug_get_json(self):
        p = Processor("gpt-4", "http://localhost:8000")
        self.scheduler.processors.add(p)

        mock_request = MagicMock()
        mock_request.method = "GET"
        mock_request.headers = {"Accept": "application/json"}

        response = await debug(mock_request)
        self.assertIsInstance(response, JSONResponse)

        # Verify JSON content
        data = json.loads(response.body.decode())
        self.assertIn("gpt-4", data)
        self.assertEqual(data["gpt-4"][0]["endpoint"], "http://localhost:8000")

    async def test_debug_get_html(self):
        mock_request = MagicMock()
        mock_request.method = "GET"
        mock_request.headers = {"Accept": "text/html"}

        response = await debug(mock_request)
        self.assertIsInstance(response, HTMLResponse)

    async def test_debug_post_active_executions(self):
        mock_request = MagicMock()
        mock_request.method = "POST"
        mock_request.form = AsyncMock(
            return_value=FormData(
                [
                    (
                        "active_executions",
                        '{"gpt-4": [["http://localhost:8000", "READY", -1, -1]]}',
                    )
                ]
            )
        )

        response = await debug(mock_request)
        self.assertIsInstance(response, RedirectResponse)

    async def test_list_executor(self):
        p = Processor("gpt-4", "http://localhost:8000")
        self.scheduler.processors.add(p)
        response = await list_executor()
        data = json.loads(response.body.decode())
        self.assertIn("gpt-4", data)

    async def test_read_executor(self):
        p = Processor("gpt-4", "http://localhost:8000")
        self.scheduler.processors.add(p)
        response = await read_executor()
        data = json.loads(response.body.decode())
        self.assertIn("gpt-4", data)

    async def test_create_executor_missing_fields(self):
        mock_request = MagicMock()
        mock_request.json = AsyncMock(
            return_value={"access_code": "gpt-4"}
        )  # missing url
        response = await create_executor(mock_request)
        self.assertEqual(response.status_code, 400)

    @patch("kuwa.kernel.core.processor.Processor.async_health_check")
    async def test_create_executor_unhealthy(self, mock_health):
        mock_health.return_value = False
        mock_request = MagicMock()
        mock_request.json = AsyncMock(
            return_value={"access_code": "gpt-4", "url": "http://localhost:8000"}
        )
        response = await create_executor(mock_request)
        self.assertEqual(response.status_code, 502)

    async def test_delete_executor(self):
        p = Processor("gpt-4", "http://localhost:8000")
        self.scheduler.processors.add(p)
        mock_request = MagicMock()
        mock_request.json = AsyncMock(
            return_value={"access_code": "gpt-4", "url": "http://localhost:8000"}
        )
        response = await delete_executor(mock_request)
        self.assertIsInstance(response, JSONResponse)
        self.assertEqual(response.status_code, 200)
        self.assertNotIn(p, self.scheduler.processors.get_all())

    async def test_delete_executor_missing_fields(self):
        mock_request = MagicMock()
        mock_request.json = AsyncMock(return_value={"access_code": "gpt-4"})
        response = await delete_executor(mock_request)
        self.assertEqual(response.status_code, 400)

    async def test_delete_executor_not_found(self):
        mock_request = MagicMock()
        mock_request.json = AsyncMock(
            return_value={"access_code": "non-existent", "url": "http://localhost:8000"}
        )
        response = await delete_executor(mock_request)
        self.assertEqual(response.status_code, 404)

    async def test_update_executor_existing(self):
        p = Processor("gpt-4", "http://localhost:8000")
        self.scheduler.processors.add(p)

        # Update max_jobs
        mock_request = MagicMock()
        mock_request.json = AsyncMock(
            return_value={
                "access_code": "gpt-4",
                "endpoint": "http://localhost:8000",
                "field": "max_jobs",
                "value": 5,
            }
        )
        response = await update_executor(mock_request)
        self.assertEqual(response.status_code, 200)
        self.assertEqual(p.max_jobs, 5)

        # Update access_code
        mock_request.json = AsyncMock(
            return_value={
                "access_code": "gpt-4",
                "endpoint": "http://localhost:8000",
                "field": "access_code",
                "value": "gpt-4-updated",
            }
        )
        response = await update_executor(mock_request)
        self.assertEqual(response.status_code, 200)
        self.assertEqual(p.access_code, "gpt-4-updated")

        # Update endpoint
        mock_request.json = AsyncMock(
            return_value={
                "access_code": "gpt-4-updated",
                "endpoint": "http://localhost:8000",
                "field": "endpoint",
                "value": "http://localhost:9000",
            }
        )
        response = await update_executor(mock_request)
        self.assertEqual(response.status_code, 200)
        self.assertEqual(p.endpoint, "http://localhost:9000")

    async def test_update_executor_bulk(self):
        p = Processor("gpt-4", "http://localhost:8000")
        self.scheduler.processors.add(p)
        mock_request = MagicMock()
        mock_request.json = AsyncMock(
            return_value={
                "original_access_code": "gpt-4",
                "original_endpoint": "http://localhost:8000",
                "access_code": "gpt-4-new",
                "endpoint": "http://localhost:9000",
                "max_jobs": 10,
            }
        )
        response = await update_executor(mock_request)
        self.assertEqual(response.status_code, 200)
        self.assertEqual(p.access_code, "gpt-4-new")
        self.assertEqual(p.endpoint, "http://localhost:9000")
        self.assertEqual(p.max_jobs, 10)

    async def test_update_executor_not_found(self):
        mock_request = MagicMock()
        mock_request.json = AsyncMock(
            return_value={
                "access_code": "non-existent",
                "endpoint": "http://localhost:8000",
                "field": "max_jobs",
                "value": 5,
            }
        )
        response = await update_executor(mock_request)
        self.assertEqual(response.status_code, 404)

    async def test_update_executor_missing_fields(self):
        mock_request = MagicMock()
        mock_request.json = AsyncMock(return_value={"field": "max_jobs"})
        response = await update_executor(mock_request)
        self.assertEqual(response.status_code, 400)

    @patch("kuwa.kernel.routes.executor.aiohttp.ClientSession.get")
    async def test_shutdown_executor(self, mock_get):
        p = Processor("gpt-4", "http://localhost:8000")
        self.scheduler.processors.add(p)
        mock_request = MagicMock()
        mock_request.json = AsyncMock(
            return_value={"access_code": "gpt-4", "url": "http://localhost:8000"}
        )
        response = await shutdown_executor(mock_request)
        self.assertIsInstance(response, JSONResponse)
        self.assertEqual(response.status_code, 200)
        self.assertNotIn(p, self.scheduler.processors.get_all())

    async def test_shutdown_executor_missing_fields(self):
        mock_request = MagicMock()
        mock_request.json = AsyncMock(return_value={"access_code": "gpt-4"})
        response = await shutdown_executor(mock_request)
        self.assertEqual(response.status_code, 400)

    async def test_shutdown_executor_not_found(self):
        mock_request = MagicMock()
        mock_request.json = AsyncMock(
            return_value={"access_code": "non-existent", "url": "http://localhost:8000"}
        )
        response = await shutdown_executor(mock_request)
        self.assertEqual(response.status_code, 404)


if __name__ == "__main__":
    unittest.main()
