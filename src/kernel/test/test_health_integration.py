import unittest
import asyncio
import os
import sys
import multiprocessing
import time
import socket
import aiohttp
import uvicorn
from fastapi import FastAPI

# Add src/kernel/src to sys.path
sys.path.append(os.path.join(os.path.dirname(__file__), "..", "src"))

from kuwa.kernel.core.scheduler import JobScheduler
from kuwa.kernel.core.processor import Processor

def find_free_port():
    with socket.socket() as s:
        s.bind(("", 0))
        return s.getsockname()[1]

# Create a clean FastAPI dummy app representing the executor
app_dummy = FastAPI()

@app_dummy.get("/health")
async def dummy_health():
    # Return exactly the JSON structure served by the updated BaseExecutor
    return {
        "code": ["test-model"],
        "req": 0,
        "lim": 3
    }

def run_executor_server(port):
    uvicorn.run(app_dummy, host="127.0.0.1", port=port, log_config=None)

class TestHealthIntegration(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def setUpClass(cls):
        cls.port = find_free_port()
        cls.process = multiprocessing.Process(
            target=run_executor_server, args=(cls.port,)
        )
        cls.process.start()
        
        # Wait for dummy server to start
        timeout = 5
        start_time = time.time()
        while time.time() - start_time < timeout:
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
                if sock.connect_ex(("127.0.0.1", cls.port)) == 0:
                    break
            time.sleep(0.1)

    @classmethod
    def tearDownClass(cls):
        if cls.process.is_alive():
            cls.process.terminate()
            cls.process.join()

    def setUp(self):
        JobScheduler._instances.clear()
        self.scheduler = JobScheduler()
        self.scheduler.processors.clear()

    async def test_integration_health_check_and_update(self):
        # 1. Register the processor in kernel
        p = Processor(
            access_code="test-model",
            endpoint=f"http://127.0.0.1:{self.port}/chat",
            max_jobs=1
        )
        self.scheduler.processors.add(p)
        
        # 2. Run real health check
        async with aiohttp.ClientSession() as session:
            healthy = await p.async_health_check(session=session)
            self.assertTrue(healthy)
            
        # The health check report returned:
        # code: ["test-model"]
        # req: 0
        # lim: 3
        # Since lim is 3 and our processor's max_jobs is 1, it should update max_jobs to 3!
        self.assertEqual(p.max_jobs, 3)

    async def test_integration_health_check_mismatch_removes_processor(self):
        # 1. Register a processor with a MISMATCHING access code
        # The executor is configured with "test-model".
        # We register it as "mismatched-model" in the kernel.
        p = Processor(
            access_code="mismatched-model",
            endpoint=f"http://127.0.0.1:{self.port}/chat",
            max_jobs=1
        )
        self.scheduler.processors.add(p)
        self.assertIn(p, self.scheduler.processors.get_all())
        
        # 2. Run health check
        async with aiohttp.ClientSession() as session:
            healthy = await p.async_health_check(session=session)
            # Should be considered unhealthy immediately due to mismatch
            self.assertFalse(healthy)
            
        # 3. Verify it was immediately removed from the processors list
        self.assertNotIn(p, self.scheduler.processors.get_all())

if __name__ == "__main__":
    unittest.main()
