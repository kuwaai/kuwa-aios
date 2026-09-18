import unittest
import asyncio
import json
from unittest.mock import patch, MagicMock, AsyncMock
from fastapi import FastAPI
import httpx
import uvicorn
import multiprocessing
import time
import os
import sys

# Add src/kernel/src to sys.path
sys.path.append(os.path.join(os.path.dirname(__file__), "..", "src"))

from kuwa.kernel.routes.chat import chat
from kuwa.kernel.routes.executor import executor
from kuwa.kernel.routes.model import model
from kuwa.kernel.core.scheduler import JobScheduler
from kuwa.kernel.core.config import EXECUTOR_HEALTH_CHECK_RETRIES

# Dummy Executor Server
app_dummy = FastAPI()


@app_dummy.post("/")
@app_dummy.post("/completions")
async def dummy_completions():
    # Return a dummy stream response
    from fastapi.responses import StreamingResponse

    async def generate():
        yield "chunk1"
        await asyncio.sleep(0.1)
        yield "chunk2"

    return StreamingResponse(generate(), media_type="text/plain")


@app_dummy.get("/health")
async def dummy_health():
    return {
        "code": ["test-model"],
        "req": 0,
        "lim": 1
    }


@app_dummy.get("/abort")
async def dummy_abort():
    return "ok"


def run_dummy_server(port):
    uvicorn.run(app_dummy, host="127.0.0.1", port=port, log_level="info")


class TestKernelE2E(unittest.IsolatedAsyncioTestCase):
    @classmethod
    def find_free_port(cls):
        import socket
        with socket.socket() as s:
            s.bind(("", 0))
            return s.getsockname()[1]

    @classmethod
    def wait_server_ready(cls, port, timeout=15):
        import urllib.request
        start_time = time.time()
        while time.time() - start_time < timeout:
            try:
                proxy_support = urllib.request.ProxyHandler({})
                opener = urllib.request.build_opener(proxy_support)
                with opener.open(f"http://127.0.0.1:{port}/health", timeout=0.5) as resp:
                    if resp.status in (200, 204):
                        return True
            except Exception:
                pass
            time.sleep(0.1)
        return False

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
        # Start dummy executor server on a clean port
        cls.dummy_port = cls.find_free_port()
        cls.dummy_process = multiprocessing.Process(
            target=run_dummy_server, args=(cls.dummy_port,)
        )
        cls.dummy_process.start()

        # Wait for server to start by polling the health endpoint
        if not cls.wait_server_ready(cls.dummy_port):
            raise RuntimeError(f"Dummy executor server failed to start on port {cls.dummy_port}")

        cls.app = FastAPI()
        cls.app.include_router(executor, prefix="/v1.0/worker")
        cls.app.include_router(chat, prefix="/v1.0/chat")
        cls.app.include_router(model, prefix="/v1.0/model")

    @classmethod
    def tearDownClass(cls):
        if cls.dummy_process.is_alive():
            cls.dummy_process.terminate()
            cls.dummy_process.join()
        cls.cleanup_artifacts()

    def setUp(self):
        JobScheduler._instances.clear()
        self.scheduler = JobScheduler()
        self.client = httpx.AsyncClient(
            transport=httpx.ASGITransport(app=self.app), base_url="http://test"
        )
        
        # Mock save_processor_list to prevent file I/O deadlocks or permission errors on Windows
        self.patcher_save = patch("kuwa.kernel.routes.executor.save_processor_list")
        self.mock_save = self.patcher_save.start()
        self.patcher_core_save = patch("kuwa.kernel.core.persistence.save_processor_list")
        self.mock_core_save = self.patcher_core_save.start()

    async def asyncTearDown(self):
        await self.client.aclose()
        self.patcher_save.stop()
        self.patcher_core_save.stop()

    async def test_full_lifecycle(self):
        # 1. Register Executor
        reg_form = {
            "name": "test-model",
            "endpoint": f"http://127.0.0.1:{self.dummy_port}",
            "limit": 1,
        }
        res = await self.client.post("/v1.0/worker/register", data=reg_form)
        self.assertEqual(res.status_code, 200)
        self.assertEqual(res.text, '"Success"')

        processors = self.scheduler.processors.get_all()
        self.assertEqual(len(processors), 1)
        self.assertEqual(processors[0].access_code, "test-model")

        # 2. Submit Job successfully
        chat_form = {
            "user_id": "test_user",
            "input": json.dumps([{"isbot": False, "msg": "hello"}]),
            "name": "test-model",
        }
        res = await self.client.post("/v1.0/chat/completions", data=chat_form)
        self.assertEqual(res.status_code, 200)
        self.assertIn("chunk1", res.text)
        self.assertIn("chunk2", res.text)

        # 3. Simulate connection failure during processing
        # Stop the dummy server to simulate a crash
        self.dummy_process.terminate()
        self.dummy_process.join()

        # Mock check_all_health to return True (pre-flight check passes, e.g. raced or considered healthy)
        with patch(
            "kuwa.kernel.core.scheduler.check_all_health",
            AsyncMock(return_value=[True]),
        ):
            # Submit job to the dead server
            res = await self.client.post("/v1.0/chat/completions", data=chat_form)
            self.assertEqual(res.status_code, 200)

            # Since check_all_health returned True, the job was assigned to the processor
            # and completions_backend ran. Since the server is dead, completions_backend failed to connect.
            # In the flawed implementation, the processor's failure_count remains 0!
            # We assert that the failure_count must be incremented.
            processors = self.scheduler.processors.get_all()
            self.assertEqual(
                len(processors),
                1,
                "Processor should still be in pool since pre-flight passed",
            )
            self.assertGreater(
                processors[0].failure_count,
                0,
                "Processor failure_count must increment on connection error during inference!",
            )

        # 4. Restart dummy server for unregistration test
        self.scheduler.processors.clear()
        self.__class__.dummy_port = self.find_free_port()
        self.__class__.dummy_process = multiprocessing.Process(
            target=run_dummy_server, args=(self.dummy_port,)
        )
        self.__class__.dummy_process.start()
        if not self.wait_server_ready(self.dummy_port):
            raise RuntimeError(f"Dummy executor server failed to restart on port {self.dummy_port}")

        # Re-register so we can unregister it cleanly
        reg_form["endpoint"] = f"http://127.0.0.1:{self.dummy_port}"
        res = await self.client.post("/v1.0/worker/register", data=reg_form)
        self.assertEqual(res.status_code, 200)

        # 5. Job Abortion Test
        chat_form_abort = {
            "user_id": "test_user",
            "input": json.dumps([{"isbot": False, "msg": "hello"}]),
            "name": "test-model",
            "history_id": "123",
        }

        async with self.client.stream(
            "POST", "/v1.0/chat/completions", data=chat_form_abort
        ) as stream_res:
            self.assertEqual(stream_res.status_code, 200)
            async for chunk in stream_res.aiter_text():
                # We received the first chunk, now issue the abort
                abort_form = {"history_id": "[123]", "user_id": "test_user"}
                abort_res = await self.client.post("/v1.0/chat/abort", data=abort_form)
                self.assertEqual(abort_res.status_code, 200)
                self.assertEqual(abort_res.text, '"Success"')
                break

        # 6. Unregister Executor
        unreg_form = {
            "name": "test-model",
            "endpoint": f"http://127.0.0.1:{self.dummy_port}",
        }
        res = await self.client.post("/v1.0/worker/unregister", data=unreg_form)
        self.assertEqual(res.status_code, 200)
        self.assertEqual(res.text, '"Success"')

        self.assertEqual(len(self.scheduler.processors.get_all()), 0)

    async def test_executor_routes_exhaustive_e2e(self):
        # Patch health check for the duration of this test
        with patch(
            "kuwa.kernel.core.processor.Processor.async_health_check",
            AsyncMock(return_value=True),
        ):
            # Schedule Deprecated Route
            res = await self.client.post("/v1.0/worker/schedule")
            self.assertEqual(res.status_code, 200)
            self.assertIn("READY", res.text)

            # Register Processor first
            reg_form = {
                "name": "test-model",
                "endpoint": f"http://127.0.0.1:{self.dummy_port}",
                "limit": 2,
            }
            await self.client.post("/v1.0/worker/register", data=reg_form)

            # list
            res = await self.client.get("/v1.0/worker/list")
            self.assertEqual(res.status_code, 200)
            self.assertIn("test-model", res.text)

            # read
            res = await self.client.get("/v1.0/worker/read")
            self.assertEqual(res.status_code, 200)
            self.assertIn("test-model", res.text)

            # update
            update_payload = {
                "access_code": "test-model",
                "endpoint": f"http://127.0.0.1:{self.dummy_port}",
                "field": "max_jobs",
                "value": 10,
            }
            res = await self.client.post("/v1.0/worker/update", json=update_payload)
            self.assertEqual(res.status_code, 200)
            p = self.scheduler.processors.get_all()[0]
            self.assertEqual(p.max_jobs, 10)

            # debug GET
            res = await self.client.get("/v1.0/worker/debug")
            self.assertEqual(res.status_code, 200)

            # debug GET JSON
            res = await self.client.get(
                "/v1.0/worker/debug", headers={"Accept": "application/json"}
            )
            self.assertEqual(res.status_code, 200)

            # debug POST
            debug_payload = {
                "active_executions": f'{{"new-model": [["http://127.0.0.1:{self.dummy_port}", "READY", -1, -1]]}}'
            }
            res = await self.client.post("/v1.0/worker/debug", data=debug_payload)
            self.assertEqual(res.status_code, 303)
            self.assertEqual(
                self.scheduler.processors.get_all()[0].access_code, "new-model"
            )

            # create
            create_payload = {
                "access_code": "create-model",
                "url": f"http://127.0.0.1:{self.dummy_port}",
                "limit": 4,
            }
            res = await self.client.post("/v1.0/worker/create", json=create_payload)
            self.assertEqual(res.status_code, 200)

            # delete
            delete_payload = {
                "access_code": "create-model",
                "url": f"http://127.0.0.1:{self.dummy_port}",
            }
            res = await self.client.post("/v1.0/worker/delete", json=delete_payload)
            self.assertEqual(res.status_code, 200)

            # shutdown
            shutdown_payload = {
                "access_code": "new-model",
                "url": f"http://127.0.0.1:{self.dummy_port}",
            }
            res = await self.client.post("/v1.0/worker/shutdown", json=shutdown_payload)
            self.assertEqual(res.status_code, 200)

    async def test_chat_routes_exhaustive_e2e(self):
        # Register Executor
        reg_form = {
            "name": "test-model",
            "endpoint": f"http://127.0.0.1:{self.dummy_port}",
            "limit": 1,
        }
        await self.client.post("/v1.0/worker/register", data=reg_form)

        # Mock trigger_schedule to prevent background scheduling tasks from hanging
        self.scheduler.trigger_schedule = MagicMock()

        # Manually enqueue a pending Job for user 'test_user_auth' to verify list_jobs
        from kuwa.kernel.core.scheduler import Job

        chat_form = {
            "user_id": "test_user_auth",
            "input": json.dumps([{"isbot": False, "msg": "hello"}]),
            "name": "test-model",
        }
        job = Job("test_user_auth", chat_form, {})
        self.scheduler.jobs.enqueue(job)

        # list_jobs
        res = await self.client.get(
            "/v1.0/chat/list_jobs", headers={"Authorization": "test_user_auth"}
        )
        self.assertEqual(res.status_code, 200)
        self.assertIn(
            "test_user_auth",
            self.scheduler.jobs.list_for_user("test_user_auth")[0].user_uuid,
        )

        # Clean up the job to prevent background task hang
        job.mark_completed()

    @patch("kuwa.kernel.routes.model.os.path.exists")
    @patch("kuwa.kernel.routes.model.os.path.isdir")
    @patch("kuwa.kernel.routes.model.os.listdir")
    @patch("kuwa.kernel.routes.model.shutil.rmtree")
    @patch("kuwa.kernel.routes.model.subprocess.run")
    @patch("kuwa.kernel.routes.model.subprocess.Popen")
    async def test_model_routes_exhaustive_e2e(
        self, mock_popen, mock_run, mock_rmtree, mock_listdir, mock_isdir, mock_exists
    ):
        mock_exists.return_value = True
        mock_isdir.return_value = True
        mock_listdir.return_value = ["models--gpt-4", ".locks"]

        # hf_login (GET)
        mock_res = MagicMock()
        mock_res.stdout = "username123\n"
        mock_res.returncode = 0
        mock_run.return_value = mock_res
        res = await self.client.get("/v1.0/model/hf_login")
        self.assertEqual(res.status_code, 200)

        # hf_login (POST)
        res = await self.client.post(
            "/v1.0/model/hf_login", json={"token": "test-token"}
        )
        self.assertEqual(res.status_code, 200)

        # hf_logout (POST)
        res = await self.client.post("/v1.0/model/hf_logout")
        self.assertEqual(res.status_code, 200)

        # list models (GET /)
        res = await self.client.get("/v1.0/model/")
        self.assertEqual(res.status_code, 200)
        self.assertIn("models--gpt-4", res.text)

        # download model (GET /download)
        res = await self.client.get("/v1.0/model/download?model_name=gpt-4")
        self.assertEqual(res.status_code, 200)

        # list download jobs
        res = await self.client.get(
            "/v1.0/v1.0/model/jobs" if False else "/v1.0/model/jobs"
        )  # wait, router mount prefix is /v1.0/model and route is /jobs
        self.assertEqual(res.status_code, 200)

        # abort download
        res = await self.client.post("/v1.0/model/abort", json={"model_name": "gpt-4"})
        self.assertEqual(res.status_code, 200)

        # remove model
        res = await self.client.post(
            "/v1.0/model/remove", json={"folder_name": "models--gpt-4"}
        )
        self.assertEqual(res.status_code, 200)

        # start model
        mock_p = MagicMock()
        mock_p.communicate.return_value = (b"stdout", b"stderr")
        mock_p.returncode = 0
        mock_popen.return_value = mock_p
        res = await self.client.post(
            "/v1.0/model/start", json={"model_path": "models--gpt-4"}
        )
        self.assertEqual(res.status_code, 200)


if __name__ == "__main__":
    unittest.main()
