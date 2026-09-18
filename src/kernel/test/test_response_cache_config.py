import unittest
import asyncio
from unittest.mock import MagicMock, patch, AsyncMock
from starlette.datastructures import FormData
import sys
import os
import json

# Add src/kernel/src to sys.path
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "src")))

from kuwa.kernel.routes.chat import completions
import kuwa.kernel.core.config as config

class TestResponseCacheConfig(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        # Store original config
        self.original_cache_enabled = config.RESPONSE_CACHE_ENABLED

    def tearDown(self):
        # Restore original config
        config.RESPONSE_CACHE_ENABLED = self.original_cache_enabled

    @patch("kuwa.kernel.routes.chat.ResponseCache")
    @patch("kuwa.kernel.routes.chat.Job")
    @patch("kuwa.kernel.routes.chat.JobScheduler")
    async def test_cache_disabled_by_default(
        self, mock_scheduler, mock_job_class, mock_cache_class
    ):
        # Ensure it's disabled
        config.RESPONSE_CACHE_ENABLED = False
        
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [
                    ("user_id", "user1"),
                    ("input", json.dumps([{"role": "user", "content": "hello"}])),
                ]
            )
        )
        mock_request.headers = {}
        mock_request.scope = {}

        mock_job = mock_job_class.return_value
        mock_job.assigned.wait = AsyncMock()
        mock_job.terminated.wait = AsyncMock()
        mock_job.processor = MagicMock()
        mock_job.form = {"input": json.dumps([{"role": "user", "content": "hello"}])}

        mock_cache = mock_cache_class.return_value
        
        # Mock completions_backend to yield something
        with patch("kuwa.kernel.routes.chat.completions_backend") as mock_backend:
            async def mock_generator(*args, **kwargs):
                yield "hi"
            mock_backend.side_effect = mock_generator

            response = await completions(mock_request)
            async for _ in response.body_iterator:
                pass

            # Cache get/put should NOT be called
            mock_cache.get.assert_not_called()
            mock_cache.put.assert_not_called()

    @patch("kuwa.kernel.routes.chat.ResponseCache")
    @patch("kuwa.kernel.routes.chat.Job")
    @patch("kuwa.kernel.routes.chat.JobScheduler")
    @patch("kuwa.kernel.routes.chat.CACHE_REQUESTS")
    async def test_cache_enabled_honored(
        self, mock_cache_metrics, mock_scheduler, mock_job_class, mock_cache_class
    ):
        # Enable it
        config.RESPONSE_CACHE_ENABLED = True
        
        mock_request = MagicMock()
        mock_request.form = AsyncMock(
            return_value=FormData(
                [
                    ("user_id", "user1"),
                    ("input", json.dumps([{"role": "user", "content": "www.google.com"}])),
                ]
            )
        )
        mock_request.headers = {}
        mock_request.scope = {}

        mock_job = mock_job_class.return_value
        mock_job.assigned.wait = AsyncMock()
        mock_job.terminated.wait = AsyncMock()
        mock_job.processor = MagicMock()
        mock_job.form = {"input": json.dumps([{"role": "user", "content": "www.google.com"}])}

        mock_cache = mock_cache_class.return_value
        mock_cache.get.return_value = None # Cache miss

        # Mock completions_backend to yield something
        with patch("kuwa.kernel.routes.chat.completions_backend") as mock_backend:
            async def mock_generator(*args, **kwargs):
                yield "hi"
            mock_backend.side_effect = mock_generator

            response = await completions(mock_request)
            async for _ in response.body_iterator:
                pass

            # Cache get/put SHOULD be called
            # It's called twice in chat.py: once at the beginning of event_stream, once before backend call.
            self.assertGreaterEqual(mock_cache.get.call_count, 1)
            mock_cache.put.assert_called()

if __name__ == "__main__":
    unittest.main()
