import unittest
import asyncio
from unittest.mock import MagicMock, patch, AsyncMock
import httpx
from starlette.datastructures import FormData
from urllib.parse import urlencode
import sys
import os
import json
import requests

# Add src/kernel/src to sys.path
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "src")))

from kuwa.kernel.routes.chat import (
    completions_backend,
    NonBufferedStreamingResponse,
    completions,
)
from kuwa.kernel.core.job import JobStateEnum


class TestChatRoute(unittest.IsolatedAsyncioTestCase):
    @patch("kuwa.kernel.routes.chat.Job")
    @patch("kuwa.kernel.routes.chat.JobScheduler")
    @patch("kuwa.kernel.routes.chat.logger")
    async def test_completions_job_timeout(
        self, mock_logger, mock_scheduler, mock_job_class
    ):
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData([("user_id", "user1"), ("input", "[]")])
        )
        mock_request.headers = {}
        mock_request.scope = {}

        mock_job = mock_job_class.return_value
        mock_job.job_id = "job1"
        mock_job.assigned.wait = AsyncMock(side_effect=asyncio.TimeoutError())
        mock_job.terminated.wait = AsyncMock()

        mock_scheduler = mock_scheduler.return_value
        mock_scheduler.get_queue_info.return_value = (0, 0.0)

        response = await completions(mock_request)
        event_stream = response.body_iterator

        chunks = []
        async for chunk in event_stream:
            chunks.append(chunk.decode("utf-8") if isinstance(chunk, bytes) else chunk)

        self.assertTrue(any("[Error] Request timed out" in c for c in chunks))
        mock_logger.warning.assert_called()

    @patch("kuwa.kernel.routes.chat.ResponseCache")
    @patch("kuwa.kernel.routes.chat.Job")
    @patch("kuwa.kernel.routes.chat.JobScheduler")
    async def test_completions_cache_hit(
        self, mock_scheduler, mock_job_class, mock_cache_class
    ):
        from kuwa.kernel.core import config
        original_val = config.RESPONSE_CACHE_ENABLED
        config.RESPONSE_CACHE_ENABLED = True
        try:
            mock_request = MagicMock()
            mock_request.form = AsyncMock(
                return_value=FormData(
                    [
                        ("user_id", "user1"),
                        ("input", json.dumps([{"isbot": False, "msg": "hello"}])),
                    ]
                )
            )
            mock_request.headers = {}
            mock_request.scope = {}

            # Configure the job instance returned by the class mock
            mock_job = mock_job_class.return_value
            mock_job.assigned.wait = AsyncMock()
            mock_job.terminated.wait = AsyncMock()
            mock_job.processor = MagicMock()
            mock_job.form = {"input": json.dumps([{"isbot": False, "msg": "hello"}])}

            mock_cache = mock_cache_class.return_value
            mock_cache.get.return_value = "cached response"

            response = await completions(mock_request)
            chunks = []
            async for chunk in response.body_iterator:
                chunks.append(chunk)

            self.assertEqual(chunks, ["cached response"])
            mock_cache.get.assert_called_with("hello")
        finally:
            config.RESPONSE_CACHE_ENABLED = original_val

    @patch("kuwa.kernel.routes.chat.Job")
    @patch("kuwa.kernel.routes.chat.JobScheduler")
    @patch("kuwa.kernel.routes.chat.logger")
    async def test_completions_cancellation_handling(
        self, mock_logger, mock_scheduler, mock_job_class
    ):
        # Mock request
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData([("user_id", "user1"), ("input", "[]")])
        )
        mock_request.headers = {}
        mock_request.scope = {}

        # Mock job
        mock_job = mock_job_class.return_value
        mock_job.job_id = "job1"
        mock_job.assigned.wait = AsyncMock(side_effect=asyncio.CancelledError())
        mock_job.terminated.wait = AsyncMock()

        # Call completions
        response = await completions(mock_request)
        self.assertIsInstance(response, NonBufferedStreamingResponse)

        # Manually trigger the event stream to test its error handling
        # The event_stream is a generator passed to NonBufferedStreamingResponse
        event_stream = response.body_iterator

        # Use a wrapper to consume the generator
        async for _ in event_stream:
            pass

        # Check if the logger recorded the cancellation
        mock_logger.info.assert_any_call(
            "Event stream cancelled (client disconnected)."
        )

    @patch("kuwa.kernel.routes.chat.Job")
    @patch("kuwa.kernel.routes.chat.JobScheduler")
    async def test_completions_status_chunks(
        self, mock_scheduler_class, mock_job_class
    ):
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData([("user_id", "user1"), ("input", json.dumps([{"isbot": False, "msg": "hi"}]))])
        )
        mock_request.headers = {}
        mock_request.scope = {}

        mock_job = mock_job_class.return_value
        mock_job.job_id = "job1"
        mock_job.assigned.wait = AsyncMock()
        mock_job.terminated.wait = AsyncMock()
        mock_job.state = JobStateEnum.CREATED
        mock_job.form = {"input": json.dumps([{"isbot": False, "msg": "hi"}]), "name": "test"}

        mock_scheduler = mock_scheduler_class.return_value
        mock_scheduler.get_queue_info.return_value = (1, 10.0)

        # Mock completions_backend to yield some text
        with patch("kuwa.kernel.routes.chat.completions_backend") as mock_backend:
            async def mock_generator(*args, **kwargs):
                yield "hello"
            mock_backend.side_effect = mock_generator

            response = await completions(mock_request)
            chunks = []
            async for chunk in response.body_iterator:
                chunks.append(chunk.decode("utf-8") if isinstance(chunk, bytes) else chunk)

            # Check for QUEUEING chunk
            self.assertTrue(any('"status": "QUEUEING"' in c and '"await": 1' in c and '"eta": 10.0' in c for c in chunks))
            # Check for PROCESSING chunk
            self.assertTrue(any('"status": "PROCESSING"' in c for c in chunks))
            # Check for actual content
            self.assertIn("hello", chunks)

    @patch("kuwa.kernel.routes.chat.Job")
    @patch("kuwa.kernel.routes.chat.JobScheduler")
    async def test_completions_terminated_status(
        self, mock_scheduler_class, mock_job_class
    ):
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData([("user_id", "user1"), ("input", "[]")])
        )
        mock_request.headers = {}
        mock_request.scope = {}

        mock_job = mock_job_class.return_value
        mock_job.job_id = "job1"
        # Simulate timeout and then terminated state
        mock_job.assigned.wait = AsyncMock(side_effect=asyncio.TimeoutError())
        mock_job.terminated.wait = AsyncMock()
        mock_job.state = JobStateEnum.TERMINATED

        mock_scheduler = mock_scheduler_class.return_value
        mock_scheduler.get_queue_info.return_value = (0, 0.0)

        response = await completions(mock_request)
        chunks = []
        async for chunk in response.body_iterator:
            chunks.append(chunk.decode("utf-8") if isinstance(chunk, bytes) else chunk)

        # Check for TERMINATED chunk
        self.assertTrue(any('"status": "TERMINATED"' in c for c in chunks))

    async def test_non_buffered_streaming_response_error_handling(self):
        async def error_iterator():
            yield "chunk1"
            raise httpx.RemoteProtocolError("Stream error")

        response = NonBufferedStreamingResponse(error_iterator())

        send_calls = []

        async def mock_send(message):
            send_calls.append(message)

        # Mocking the internal state needed for __call__
        scope = {"type": "http"}

        async def mock_receive():
            return {"type": "http.request"}

        with patch("kuwa.kernel.routes.chat.logger") as mock_logger:
            await response(scope, mock_receive, mock_send)

            # Should have sent headers and the first chunk
            self.assertTrue(any(m["type"] == "http.response.start" for m in send_calls))
            self.assertTrue(any(m.get("body") == b"chunk1" for m in send_calls))

            # Should NOT have sent the final empty chunk because it returned early on error
            self.assertFalse(
                any(
                    m.get("type") == "http.response.body"
                    and m.get("body") == b""
                    and not m.get("more_body")
                    for m in send_calls
                )
            )

            mock_logger.warning.assert_called()
            self.assertIn(
                "HTTP protocol error while streaming response",
                mock_logger.warning.call_args[0][0],
            )

    async def test_completions_backend_multipart_handling(self):
        # Prepare mock data
        chat_history = []
        model_id = "test_model"
        endpoint = "http://backend/completions"
        headers = {
            "Content-Type": "multipart/form-data; boundary=---12345",
            "Content-Length": "123",
            "Host": "localhost",
            "Authorization": "Bearer token",
            "User-Agent": "TestAgent",
        }

        # Simulate Starlette's FormData which has .multi_items()
        form = FormData(
            [
                ("name", "test_model"),
                ("input", "[]"),
                (
                    "file",
                    "this should be filtered out if it was an UploadFile, but here we test string filtering",
                ),
            ]
        )

        # Manually create a mock that behaves like a mix of string and non-string values
        class MockUploadFile:
            pass

        form_with_file = FormData(
            [("name", "test_model"), ("input", "[]"), ("file", MockUploadFile())]
        )

        # Use the undecorated function to focus on the logic inside
        target_func = (
            completions_backend.__wrapped__
            if hasattr(completions_backend, "__wrapped__")
            else completions_backend
        )

        # Mock httpx.AsyncClient
        with patch("httpx.AsyncClient") as mock_client_class:
            mock_client = MagicMock()
            mock_client_class.return_value.__aenter__.return_value = mock_client

            # Setup the async context manager for client.stream
            mock_response = MagicMock()

            async def async_iter_text():
                yield "chunk1"
                yield "chunk2"

            mock_response.aiter_text.return_value = async_iter_text()

            mock_stream_ctx = MagicMock()
            mock_stream_ctx.__aenter__ = AsyncMock(return_value=mock_response)
            mock_stream_ctx.__aexit__ = AsyncMock(return_value=None)
            mock_client.stream.return_value = mock_stream_ctx

            # Call the function
            chunks = []
            async for chunk in target_func(
                chat_history=chat_history,
                model_id=model_id,
                endpoint=endpoint,
                headers=headers,
                form=form_with_file,
            ):
                chunks.append(chunk)

            # Assertions
            self.assertEqual(chunks, ["chunk1", "chunk2"])

            # Check how stream was called
            mock_client.stream.assert_called_once()
            args, kwargs = mock_client.stream.call_args
            self.assertEqual(args[0], "POST")
            self.assertEqual(args[1], endpoint)

            sent_headers = kwargs["headers"]
            # Case-insensitive check for forbidden headers
            lower_sent_headers = {k.lower(): v for k, v in sent_headers.items()}
            self.assertNotIn("content-length", lower_sent_headers)
            self.assertNotIn("host", lower_sent_headers)
            self.assertNotIn("transfer-encoding", lower_sent_headers)

            # Content-type should be stripped (httpx sets it via data= param)
            self.assertNotIn("content-type", lower_sent_headers)

            # Other headers should be preserved
            self.assertEqual(sent_headers["Authorization"], "Bearer token")
            self.assertEqual(sent_headers["User-Agent"], "TestAgent")

            # Check keep-alive headers
            self.assertEqual(sent_headers["Connection"], "keep-alive")
            self.assertIn("timeout=", sent_headers["Keep-Alive"])

            # Check body - sent as data= dict
            sent_data = kwargs["data"]
            self.assertEqual(sent_data["name"], "test_model")
            self.assertEqual(sent_data["input"], "[]")

            # Check timeout
            self.assertIsInstance(kwargs["timeout"], httpx.Timeout)

    @patch("kuwa.kernel.routes.chat.logger")
    async def test_completions_backend_connect_error(self, mock_logger):
        chat_history = []
        model_id = "test_model"
        endpoint = "http://backend/completions"
        headers = {}
        form = FormData([])

        target_func = (
            completions_backend.__wrapped__
            if hasattr(completions_backend, "__wrapped__")
            else completions_backend
        )

        with patch("httpx.AsyncClient") as mock_client_class:
            mock_client = MagicMock()
            mock_client_class.return_value.__aenter__.return_value = mock_client
            mock_client.stream.side_effect = httpx.ConnectError("Connection failed")

            chunks = []
            async for chunk in target_func(
                chat_history=chat_history,
                model_id=model_id,
                endpoint=endpoint,
                headers=headers,
                form=form,
            ):
                chunks.append(chunk)

            self.assertEqual(chunks, [])
            mock_logger.exception.assert_called()
            self.assertIn(
                "Failed to connect to backend endpoint",
                mock_logger.exception.call_args[0][0],
            )

    @patch("kuwa.kernel.routes.chat.logger")
    async def test_completions_backend_remote_protocol_error(self, mock_logger):
        chat_history = []
        model_id = "test_model"
        endpoint = "http://backend/completions"
        headers = {}
        form = FormData([])

        target_func = (
            completions_backend.__wrapped__
            if hasattr(completions_backend, "__wrapped__")
            else completions_backend
        )

        with patch("httpx.AsyncClient") as mock_client_class:
            mock_client = MagicMock()
            mock_client_class.return_value.__aenter__.return_value = mock_client
            mock_client.stream.side_effect = httpx.RemoteProtocolError(
                "Test remote error"
            )

            chunks = []
            async for chunk in target_func(
                chat_history=chat_history,
                model_id=model_id,
                endpoint=endpoint,
                headers=headers,
                form=form,
            ):
                chunks.append(chunk)

            self.assertEqual(chunks, [])
            mock_logger.warning.assert_called()
            self.assertIn(
                "Remote endpoint closed connection early",
                mock_logger.warning.call_args[0][0],
            )

    @patch("kuwa.kernel.routes.chat.Job")
    @patch("kuwa.kernel.routes.chat.JobScheduler")
    @patch("kuwa.kernel.routes.chat.logger")
    async def test_event_stream_generic_exception(
        self, mock_logger, mock_scheduler, mock_job_class
    ):
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData([("user_id", "u1"), ("input", "[]")])
        )
        mock_job = mock_job_class.return_value
        mock_job.assigned.wait.side_effect = Exception("Generic error")
        mock_job.terminated.wait = AsyncMock()
        response = await completions(mock_request)
        async for _ in response.body_iterator:
            pass
        mock_logger.exception.assert_called_with("Error processing event string.")

    async def test_completions_backend_endpoint_fallback(self):
        target = (
            completions_backend.__wrapped__
            if hasattr(completions_backend, "__wrapped__")
            else completions_backend
        )
        with patch("httpx.AsyncClient") as mock_client_class:
            mock_client = MagicMock()
            mock_client_class.return_value.__aenter__.return_value = mock_client

            mock_response = MagicMock()

            async def async_iter_text():
                yield "ok"

            mock_response.aiter_text.return_value = async_iter_text()

            mock_stream_ctx = MagicMock()
            mock_stream_ctx.__aenter__ = AsyncMock(return_value=mock_response)
            mock_stream_ctx.__aexit__ = AsyncMock(return_value=None)
            mock_client.stream.return_value = mock_stream_ctx

            # Pass None as endpoint to trigger fallback
            async for _ in target([], "m", None, {}, {}):
                break
            self.assertEqual(
                mock_client.stream.call_args[0][1], "http://localhost:8000"
            )

    async def test_completions_backend_plain_dict_form(self):
        target = (
            completions_backend.__wrapped__
            if hasattr(completions_backend, "__wrapped__")
            else completions_backend
        )
        with patch("httpx.AsyncClient") as mock_client_class:
            mock_client = MagicMock()
            mock_client_class.return_value.__aenter__.return_value = mock_client

            mock_response = MagicMock()

            async def async_iter_text():
                yield "ok"

            mock_response.aiter_text.return_value = async_iter_text()

            mock_stream_ctx = MagicMock()
            mock_stream_ctx.__aenter__ = AsyncMock(return_value=mock_response)
            mock_stream_ctx.__aexit__ = AsyncMock(return_value=None)
            mock_client.stream.return_value = mock_stream_ctx

            # Pass a plain dict instead of FormData
            async for _ in target([], "m", "http://ok", {}, {"k": "v"}):
                break
            self.assertEqual(mock_client.stream.call_args[1]["data"]["k"], "v")

    @patch("kuwa.kernel.routes.chat.Job")
    @patch("kuwa.kernel.routes.chat.JobScheduler")
    @patch("kuwa.kernel.routes.chat.logger")
    @patch("kuwa.kernel.routes.chat.CLIENT_DISCONNECTS")
    async def test_event_stream_client_disconnect(
        self, mock_metric, mock_logger, mock_scheduler, mock_job_class
    ):
        from starlette.requests import ClientDisconnect

        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData([("user_id", "u1"), ("input", "[]")])
        )
        mock_job = mock_job_class.return_value
        mock_job.assigned.wait.side_effect = ClientDisconnect()
        mock_job.terminated.wait = AsyncMock()

        response = await completions(mock_request)
        async for _ in response.body_iterator:
            pass
        mock_metric.inc.assert_called()
        mock_logger.info.assert_any_call(
            "Event stream cancelled (client disconnected)."
        )

    @patch("kuwa.kernel.routes.chat.JobScheduler")
    @patch("kuwa.kernel.routes.chat.logger")
    async def test_completions_backend_all_errors(
        self, mock_logger, mock_scheduler_class
    ):
        mock_scheduler = mock_scheduler_class.return_value
        
        def setup_p():
            mock_p = MagicMock()
            mock_p.endpoint = "http://fail"
            mock_p.failure_count = 0
            mock_scheduler.processors = [mock_p]
            mock_scheduler.lock = MagicMock()
            return mock_p

        target_func = (
            completions_backend.__wrapped__
            if hasattr(completions_backend, "__wrapped__")
            else completions_backend
        )

        with patch("httpx.AsyncClient") as mock_client_class:
            mock_client = MagicMock()
            mock_client_class.return_value.__aenter__.return_value = mock_client

            # Test ConnectError removal
            mock_p = setup_p()
            mock_p.failure_count = 4
            mock_client.stream.side_effect = httpx.ConnectError("Fail")
            async for _ in target_func([], "m", "http://fail", {}, FormData([])):
                pass
            self.assertEqual(len(mock_scheduler.processors), 0)

            # Test RemoteProtocolError removal
            mock_p = setup_p()
            mock_p.failure_count = 4
            mock_client.stream.side_effect = httpx.RemoteProtocolError("Fail")
            async for _ in target_func([], "m", "http://fail", {}, FormData([])):
                pass
            self.assertEqual(len(mock_scheduler.processors), 0)

            # Test LocalProtocolError removal
            mock_p = setup_p()
            mock_p.failure_count = 4
            mock_client.stream.side_effect = httpx.LocalProtocolError("Fail")
            async for _ in target_func([], "m", "http://fail", {}, FormData([])):
                pass
            self.assertEqual(len(mock_scheduler.processors), 0)

    @patch("kuwa.kernel.routes.chat.JobScheduler")
    @patch("kuwa.kernel.routes.chat.httpx.AsyncClient")
    async def test_abort_logic_branches(self, mock_client_class, mock_scheduler_class):
        mock_scheduler = mock_scheduler_class.return_value
        
        # Mock httpx.AsyncClient context manager
        mock_client = AsyncMock()
        mock_client_class.return_value.__aenter__.return_value = mock_client

        # Mock a pending job
        mock_job_pending = MagicMock()
        mock_job_pending.state = JobStateEnum.PENDING
        mock_job_pending.form = {"history_id": 1}

        # Mock a processing job
        mock_p = MagicMock()
        mock_p.endpoint = "http://executor"
        mock_job_proc = MagicMock()
        mock_job_proc.job_id = "job-2"
        mock_job_proc.state = JobStateEnum.PROCESSING
        mock_job_proc.processor = mock_p
        mock_job_proc.form = {"history_id": 2}

        mock_scheduler.jobs.list_for_user.return_value = [
            mock_job_pending,
            mock_job_proc,
        ]

        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value={"history_id": "[1, 2]", "user_id": "u1"}
        )

        from kuwa.kernel.routes.chat import abort

        await abort(mock_request)

        # Verify pending was dequeued
        mock_scheduler.jobs.dequeue.assert_called_with(mock_job_pending)
        # Verify processing was signalled
        mock_client.get.assert_called_with(
            "http://executor/abort", params={"job_id": "job-2"}, timeout=10
        )


    @patch("kuwa.kernel.routes.chat.JobScheduler")
    @patch("kuwa.kernel.routes.chat.logger")
    @patch("kuwa.kernel.routes.chat.PROCESSORS_REMOVED")
    async def test_completions_backend_processor_removal(
        self, mock_metric, mock_logger, mock_scheduler_class
    ):
        mock_scheduler = mock_scheduler_class.return_value
        mock_p = MagicMock()
        mock_p.endpoint = "http://fail"
        mock_p.failure_count = 4  # One short of EXECUTOR_HEALTH_CHECK_RETRIES (5)
        mock_scheduler.processors = [mock_p]
        mock_scheduler.lock = MagicMock()

        target_func = (
            completions_backend.__wrapped__
            if hasattr(completions_backend, "__wrapped__")
            else completions_backend
        )

        with patch("httpx.AsyncClient") as mock_client_class:
            mock_client = MagicMock()
            mock_client_class.return_value.__aenter__.return_value = mock_client
            mock_client.stream.side_effect = httpx.ConnectError("Fail")

            async for _ in target_func([], "m", "http://fail", {}, FormData([])):
                pass

            self.assertEqual(len(mock_scheduler.processors), 0)
            mock_metric.labels.return_value.inc.assert_called()

    @patch("kuwa.kernel.routes.chat.Job")
    @patch("kuwa.kernel.routes.chat.JobScheduler")
    @patch("kuwa.kernel.routes.chat.logger")
    async def test_event_stream_protocol_error(
        self, mock_logger, mock_scheduler, mock_job_class
    ):
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData([("user_id", "u1"), ("input", "[]")])
        )
        mock_job = mock_job_class.return_value
        mock_job.assigned.wait.side_effect = httpx.LocalProtocolError("Protocol fail")
        mock_job.terminated.wait = AsyncMock()

        response = await completions(mock_request)
        async for _ in response.body_iterator:
            pass
        mock_logger.warning.assert_called_with(
            "HTTP protocol error in event stream: Protocol fail"
        )

    @patch("kuwa.kernel.routes.chat.JobScheduler")
    @patch("kuwa.kernel.routes.chat.logger")
    async def test_abort_error_handling(self, mock_logger, mock_scheduler_class):
        from kuwa.kernel.routes.chat import abort

        # Test invalid JSON in history_id
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value={"history_id": "invalid-json", "user_id": "u1"}
        )
        res = await abort(mock_request)
        self.assertEqual(res, "Failed")
        mock_logger.exception.assert_called_with("Failed to parse history_id")

        # Test non-list history_id (should be wrapped in list)
        mock_request.form = AsyncMock(
            return_value={"history_id": "123", "user_id": "u1"}
        )
        mock_scheduler = mock_scheduler_class.return_value
        mock_scheduler.jobs.list_for_user.return_value = []
        res = await abort(mock_request)
        self.assertEqual(res, "Success")

        # Test httpx error during abort call
        mock_p = MagicMock()
        mock_p.endpoint = "http://executor"
        mock_job = MagicMock()
        mock_job.state = JobStateEnum.PROCESSING
        mock_job.processor = mock_p
        mock_job.form = {"history_id": 123}
        mock_scheduler.jobs.list_for_user.return_value = [mock_job]

        with patch("httpx.AsyncClient") as mock_client_class:
            mock_client = AsyncMock()
            mock_client_class.return_value.__aenter__.return_value = mock_client
            mock_client.get.side_effect = httpx.HTTPError("Abort fail")

            res = await abort(mock_request)
            self.assertEqual(res, "Success")
            mock_logger.exception.assert_called_with(
                "Failed to abort job at http://executor"
            )


if __name__ == "__main__":
    unittest.main()
