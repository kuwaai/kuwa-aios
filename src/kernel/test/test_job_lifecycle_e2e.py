import unittest
import asyncio
import json
from unittest.mock import patch, AsyncMock
from fastapi import FastAPI
import httpx
import uvicorn
import multiprocessing
import time
import os
import sys

# Add src/kernel/src to sys.path
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "src"))

from kuwa.kernel.routes.chat import chat
from kuwa.kernel.routes.executor import executor
from kuwa.kernel.core.scheduler import JobScheduler
from kuwa.kernel.core.job import Job, JobStateEnum

# Dummy Executor Server
app_dummy = FastAPI()

@app_dummy.post("/")
@app_dummy.post("/completions")
async def dummy_completions():
    from fastapi.responses import StreamingResponse
    async def generate():
        yield "chunk1"
        await asyncio.sleep(0.5) # Give time to check PROCESSING state
        yield "chunk2"
    return StreamingResponse(generate(), media_type="text/plain")

@app_dummy.get("/health")
async def dummy_health():
    return {"code": ["test-model"], "req": 0, "lim": 1}

def run_dummy_server(port):
    uvicorn.run(app_dummy, host="127.0.0.1", port=port, log_level="error")

class TestJobLifecycleE2E(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def find_free_port(cls):
        import socket
        with socket.socket() as s:
            s.bind(("", 0))
            return s.getsockname()[1]

    @classmethod
    def wait_server_ready(cls, port, timeout=15):
        import urllib.request
        import urllib.error
        start_time = time.time()
        while time.time() - start_time < timeout:
            try:
                proxy_support = urllib.request.ProxyHandler({})
                opener = urllib.request.build_opener(proxy_support)
                with opener.open(f"http://127.0.0.1:{port}/health", timeout=0.5) as resp:
                    if resp.status in (200, 204):
                        return True
            except (urllib.error.URLError, TimeoutError, ConnectionError):
                # Server may not be ready yet; retry until timeout.
                pass
            time.sleep(0.1)
        return False

    @classmethod
    def setUpClass(cls):
        # Start dummy executor server
        cls.dummy_port = cls.find_free_port()
        cls.dummy_process = multiprocessing.Process(
            target=run_dummy_server, args=(cls.dummy_port,)
        )
        cls.dummy_process.start()

        if not cls.wait_server_ready(cls.dummy_port):
            raise RuntimeError(f"Dummy executor server failed to start on port {cls.dummy_port}")

        cls.app = FastAPI()
        cls.app.include_router(executor, prefix="/v1.0/worker")
        cls.app.include_router(chat, prefix="/v1.0/chat")

    @classmethod
    def tearDownClass(cls):
        if cls.dummy_process.is_alive():
            cls.dummy_process.terminate()
            cls.dummy_process.join()

    def setUp(self):
        JobScheduler._instances.clear()
        self.scheduler = JobScheduler()
        self.client = httpx.AsyncClient(
            transport=httpx.ASGITransport(app=self.app), base_url="http://test"
        )
        
        # Mock persistence to avoid file I/O
        self.patcher_save = patch("kuwa.kernel.routes.executor.save_processor_list")
        self.patcher_save.start()
        self.patcher_core_save = patch("kuwa.kernel.core.persistence.save_processor_list")
        self.patcher_core_save.start()

    async def asyncTearDown(self):
        await self.client.aclose()
        patch.stopall()

    async def test_job_lifecycle_states(self):
        # Register Executor
        reg_form = {
            "name": "test-model",
            "endpoint": f"http://127.0.0.1:{self.dummy_port}",
            "limit": 1,
        }
        res = await self.client.post("/v1.0/worker/register", data=reg_form)
        self.assertEqual(res.status_code, 200)

        # We will intercept Job.__init__ to track states of all created jobs
        states_tracked = []
        original_init = Job.__init__
        
        def tracked_init(self, *args, **kwargs):
            original_init(self, *args, **kwargs)
            self._lifecycle_states = [self.state] # Should be CREATED
            
            # Wrap mark methods to track state transitions
            original_mark_pending = self.mark_pending
            def tracked_mark_pending(*args, **kwargs):
                self._lifecycle_states.append(JobStateEnum.PENDING)
                original_mark_pending(*args, **kwargs)
            self.mark_pending = tracked_mark_pending

            original_mark_processing = self.mark_processing
            def tracked_mark_processing(*args, **kwargs):
                self._lifecycle_states.append(JobStateEnum.PROCESSING)
                original_mark_processing(*args, **kwargs)
            self.mark_processing = tracked_mark_processing

            original_mark_completed = self.mark_completed
            def tracked_mark_completed(*args, **kwargs):
                self._lifecycle_states.append(JobStateEnum.COMPLETED)
                original_mark_completed(*args, **kwargs)
            self.mark_completed = tracked_mark_completed

            original_mark_terminated = self.mark_terminated
            def tracked_mark_terminated(*args, **kwargs):
                self._lifecycle_states.append(JobStateEnum.TERMINATED)
                original_mark_terminated(*args, **kwargs)
            self.mark_terminated = tracked_mark_terminated
            
            states_tracked.append(self._lifecycle_states)

        with patch.object(Job, "__init__", tracked_init):
            chat_form = {
                "user_id": "test_user",
                "input": json.dumps([{"isbot": False, "msg": "hello"}]),
                "name": "test-model",
            }
            
            # Submit job and read response stream to completion
            async with self.client.stream("POST", "/v1.0/chat/completions", data=chat_form) as response:
                self.assertEqual(response.status_code, 200)
                async for chunk in response.aiter_text():
                    pass

        # Verify lifecycle states for the job
        self.assertGreater(len(states_tracked), 0)
        job_states = states_tracked[0]
        
        # Expected sequence: CREATED, PENDING, PROCESSING, COMPLETED, TERMINATED
        expected_sequence = [
            JobStateEnum.CREATED,
            JobStateEnum.PENDING,
            JobStateEnum.PROCESSING,
            JobStateEnum.COMPLETED,
            JobStateEnum.TERMINATED
        ]
        
        # Note: Depending on timing, some states might be recorded multiple times if methods are called twice, 
        # but the order should be preserved. Let's filter for unique consecutive states.
        actual_sequence = []
        for state in job_states:
            if not actual_sequence or actual_sequence[-1] != state:
                actual_sequence.append(state)
        
        self.assertEqual(actual_sequence, expected_sequence, f"Actual sequence: {actual_sequence}")

if __name__ == "__main__":
    unittest.main()
