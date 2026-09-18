import unittest
import asyncio
import os
import shutil
import subprocess
import threading
from unittest.mock import MagicMock, patch, AsyncMock
import sys
from fastapi import HTTPException, BackgroundTasks

# Add src/kernel/src to sys.path
sys.path.append(os.path.join(os.path.dirname(__file__), "..", "src"))

from kuwa.kernel.routes.model import (
    ModelRequest,
    stop_download,
    remove_model,
    list_models,
    download_model,
    list_download_jobs,
    hf_login_get,
    hf_login_post,
    hf_logout,
    start_model,
    clean_up_partial_download,
    download_model_cli,
)
from kuwa.kernel.core.state import download_jobs


class TestModelRoute(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        download_jobs.clear()

    def tearDown(self):
        download_jobs.clear()

    def test_clean_up_partial_download_exception(self):
        with patch("kuwa.kernel.routes.model.shutil.rmtree") as mock_rmtree:
            mock_rmtree.side_effect = Exception("RM error")
            # Should not raise exception (logs to stdout)
            clean_up_partial_download("gpt-4")

    @patch("kuwa.kernel.routes.model.subprocess.Popen")
    @patch("kuwa.kernel.routes.model.ensure_cache_directory")
    def test_download_model_cli_wait_exception(self, mock_cache, mock_popen):
        mock_cache.return_value = "/tmp"
        mock_p = MagicMock()
        # Mock pipes to return empty string immediately to avoid thread hang
        mock_p.stdout.readline.return_value = ""
        mock_p.stderr.readline.return_value = ""
        mock_p.wait.side_effect = Exception("Wait error")
        mock_popen.return_value = mock_p

        result_list = []
        stop_event = threading.Event()
        download_jobs["gpt-4"] = {"process": None}

        download_model_cli("gpt-4", result_list, stop_event)
        self.assertIn("Process error: Wait error", result_list)

    @patch("kuwa.kernel.routes.model.clean_up_partial_download")
    async def test_stop_download_active(self, mock_cleanup):
        # Setup active download job
        mock_process = MagicMock()
        mock_stop_event = MagicMock()
        download_jobs["gpt-4"] = {
            "process": mock_process,
            "stop_event": mock_stop_event,
            "start_time": "12:00:00",
        }

        request = ModelRequest(model_name="gpt-4")
        response = await stop_download(request)

        mock_stop_event.set.assert_called_once()
        mock_process.terminate.assert_called_once()
        mock_cleanup.assert_called_once_with("gpt-4")
        self.assertEqual(response.status_code, 200)

    async def test_stop_download_invalid_model(self):
        # Model not in active downloads
        request = ModelRequest(model_name="non-existent")
        with self.assertRaises(HTTPException) as context:
            await stop_download(request)

        self.assertEqual(context.exception.status_code, 400)

    @patch("kuwa.kernel.routes.model.os.path.exists")
    @patch("kuwa.kernel.routes.model.shutil.rmtree")
    async def test_remove_model_success(self, mock_rmtree, mock_exists):
        mock_exists.return_value = True

        request = ModelRequest(folder_name="models--gpt-4")
        response = await remove_model(request)

        self.assertEqual(response.status_code, 200)
        self.assertEqual(mock_rmtree.call_count, 2)  # Removes model dir & locks dir

    @patch("kuwa.kernel.routes.model.os.path.exists")
    @patch("kuwa.kernel.routes.model.shutil.rmtree")
    async def test_remove_model_exception(self, mock_rmtree, mock_exists):
        mock_exists.return_value = True
        mock_rmtree.side_effect = Exception("Delete error")

        request = ModelRequest(folder_name="models--gpt-4")
        with self.assertRaises(HTTPException) as context:
            await remove_model(request)
        self.assertEqual(context.exception.status_code, 500)

    @patch("kuwa.kernel.routes.model.os.path.exists")
    async def test_remove_model_not_found(self, mock_exists):
        mock_exists.return_value = False

        request = ModelRequest(folder_name="non-existent")
        with self.assertRaises(HTTPException) as context:
            await remove_model(request)

        self.assertEqual(context.exception.status_code, 404)

    @patch("kuwa.kernel.routes.model.os.path.isdir")
    @patch("kuwa.kernel.routes.model.os.path.exists")
    @patch("kuwa.kernel.routes.model.os.listdir")
    async def test_list_models(self, mock_listdir, mock_exists, mock_isdir):
        mock_exists.return_value = True
        mock_isdir.return_value = True
        mock_listdir.return_value = ["models--gpt-4", "models--claude-3", ".locks"]

        # Active download job to exclude from available list
        download_jobs["claude-3"] = {}

        response = await list_models()
        self.assertEqual(response.status_code, 200)

        # Verify claude-3 is filtered out because it is downloading, and .locks is filtered
        import json

        data = json.loads(response.body.decode())
        self.assertEqual(data["models"], ["models--gpt-4"])

    async def test_download_model_already_downloading(self):
        download_jobs["gpt-4"] = {}

        bg_tasks = BackgroundTasks()
        with self.assertRaises(HTTPException) as context:
            await download_model(model_name="gpt-4", background_tasks=bg_tasks)

        self.assertEqual(context.exception.status_code, 400)

    @patch("kuwa.kernel.routes.model.threading.Thread")
    async def test_download_model_success_start(self, mock_thread):
        mock_t = MagicMock()
        mock_thread.return_value = mock_t

        bg_tasks = BackgroundTasks()
        response = await download_model(model_name="gpt-4", background_tasks=bg_tasks)

        self.assertEqual(response.status_code, 200)
        self.assertIn("gpt-4", download_jobs)
        mock_t.start.assert_called_once()

    async def test_list_download_jobs(self):
        download_jobs["gpt-4"] = {"start_time": "12:00:00"}

        response = await list_download_jobs()
        import json

        data = json.loads(response.body.decode())
        active_jobs = data["active_jobs"]
        self.assertEqual(len(active_jobs), 1)
        self.assertEqual(active_jobs[0]["model_name"], "gpt-4")

    @patch("kuwa.kernel.routes.model.subprocess.run")
    async def test_hf_login_get_logged_in(self, mock_run):
        mock_res = MagicMock()
        mock_res.stdout = "username123\n"
        mock_run.return_value = mock_res

        response = await hf_login_get()
        import json

        data = json.loads(response.body.decode())
        self.assertTrue(data["logged_in"])
        self.assertEqual(data["username"], "username123")

    @patch("kuwa.kernel.routes.model.subprocess.run")
    async def test_hf_login_get_not_logged_in(self, mock_run):
        mock_res = MagicMock()
        mock_res.stdout = "Not logged in\n"
        mock_run.return_value = mock_res

        response = await hf_login_get()
        import json

        data = json.loads(response.body.decode())
        self.assertFalse(data["logged_in"])
        self.assertIsNone(data["username"])

    @patch("kuwa.kernel.routes.model.subprocess.run")
    async def test_hf_login_post_success(self, mock_run):
        mock_res = MagicMock()
        mock_res.returncode = 0
        mock_run.return_value = mock_res

        request = ModelRequest(token="token123")
        response = await hf_login_post(request)
        self.assertEqual(response.status_code, 200)

    @patch("kuwa.kernel.routes.model.subprocess.run")
    async def test_hf_login_post_failure(self, mock_run):
        mock_res = MagicMock()
        mock_res.returncode = 1
        mock_res.stderr = "Invalid token\n"
        mock_run.return_value = mock_res

        request = ModelRequest(token="token123")
        with self.assertRaises(HTTPException) as context:
            await hf_login_post(request)

        self.assertEqual(context.exception.status_code, 401)

    @patch("kuwa.kernel.routes.model.subprocess.run")
    async def test_hf_logout_success(self, mock_run):
        mock_res = MagicMock()
        mock_res.returncode = 0
        mock_run.return_value = mock_res

        response = await hf_logout()
        self.assertEqual(response.status_code, 200)

    @patch("kuwa.kernel.routes.model.subprocess.Popen")
    async def test_start_model_success(self, mock_popen):
        mock_p = MagicMock()
        mock_p.communicate.return_value = (b"stdout", b"stderr")
        mock_p.returncode = 0
        mock_popen.return_value = mock_p

        request = ModelRequest(model_path="gpt-4", visible_gpu="0", limit=1)
        response = await start_model(request)

        self.assertEqual(response.status_code, 200)
        mock_popen.assert_called_once()
        args, kwargs = mock_popen.call_args
        self.assertIn("kuwa-executor", args[0])
        self.assertIn("--visible_gpu", args[0])
        self.assertIn("--limit", args[0])

    @patch("kuwa.kernel.routes.model.subprocess.Popen")
    async def test_start_model_failure(self, mock_popen):
        mock_p = MagicMock()
        mock_p.communicate.return_value = (b"stdout", b"Some GPU error")
        mock_p.returncode = 1
        mock_popen.return_value = mock_p

        request = ModelRequest(model_path="gpt-4")
        with self.assertRaises(HTTPException) as context:
            await start_model(request)

        self.assertEqual(context.exception.status_code, 500)
        self.assertIn("Some GPU error", context.exception.detail)

    @patch("kuwa.kernel.routes.model.subprocess.Popen")
    async def test_start_model_popen_exception(self, mock_popen):
        mock_popen.side_effect = Exception("Popen failed")

        request = ModelRequest(model_path="gpt-4")
        with self.assertRaises(HTTPException) as context:
            await start_model(request)
        self.assertEqual(context.exception.status_code, 500)
        self.assertIn("Unexpected error: Popen failed", context.exception.detail)

    async def test_stop_download_missing_name(self):
        request = ModelRequest(model_name=None)
        with self.assertRaises(HTTPException) as context:
            await stop_download(request)
        self.assertEqual(context.exception.status_code, 400)

    async def test_remove_model_missing_folder(self):
        request = ModelRequest(folder_name=None)
        with self.assertRaises(HTTPException) as context:
            await remove_model(request)
        self.assertEqual(context.exception.status_code, 400)

    async def test_download_model_missing_name(self):
        with self.assertRaises(HTTPException) as context:
            await download_model(model_name=None, background_tasks=BackgroundTasks())
        self.assertEqual(context.exception.status_code, 400)

    async def test_hf_login_post_missing_token(self):
        request = ModelRequest(token=None)
        with self.assertRaises(HTTPException) as context:
            await hf_login_post(request)
        self.assertEqual(context.exception.status_code, 400)

    async def test_start_model_missing_path(self):
        request = ModelRequest(model_path=None)
        with self.assertRaises(HTTPException) as context:
            await start_model(request)
        self.assertEqual(context.exception.status_code, 400)

    @patch("kuwa.kernel.routes.model.threading.Thread")
    async def test_download_model_generate_loop(self, mock_thread_class):
        mock_thread = mock_thread_class.return_value
        mock_thread.is_alive.side_effect = [True, False, False]

        bg_tasks = BackgroundTasks()
        await download_model(model_name="gpt-4", background_tasks=bg_tasks)

        # After download_model is called, download_jobs["gpt-4"] is populated by the function itself.
        # We can now inject our mock data into the newly created job details
        download_jobs["gpt-4"]["result_list"].append("progress1")

        generator_func = bg_tasks.tasks[0].func
        gen = generator_func()

        self.assertEqual(next(gen), "progress1\n")
        self.assertEqual(next(gen), "Complete!\n")
        with self.assertRaises(StopIteration):
            next(gen)

    @patch("kuwa.kernel.routes.model.threading.Thread")
    async def test_download_model_generate_yield_empty(self, mock_thread_class):
        mock_thread = mock_thread_class.return_value
        mock_thread.is_alive.return_value = True

        bg_tasks = BackgroundTasks()
        await download_model(model_name="gpt-4", background_tasks=bg_tasks)

        # Ensure result_list is empty
        download_jobs["gpt-4"]["result_list"] = []

        generator_func = bg_tasks.tasks[0].func
        gen = generator_func()

        self.assertEqual(next(gen), " ")

    @patch("kuwa.kernel.routes.model.threading.Thread")
    async def test_download_model_generate_exit(self, mock_thread_class):
        mock_thread = mock_thread_class.return_value
        
        bg_tasks = BackgroundTasks()
        await download_model(model_name="gpt-4", background_tasks=bg_tasks)
        
        # Mock a process to be terminated
        mock_process = MagicMock()
        download_jobs["gpt-4"]["process"] = mock_process

        generator_func = bg_tasks.tasks[0].func
        gen = generator_func()

        # Start the generator so it enters the try block
        try:
            next(gen)
        except StopIteration:
            pass

        # Close the generator to trigger GeneratorExit
        gen.close()
        # Should have set stop_event and terminated process
        self.assertTrue(download_jobs["gpt-4"]["stop_event"].is_set())
        mock_process.terminate.assert_called_once()
        mock_thread.join.assert_called_once()

    @patch("kuwa.kernel.routes.model.threading.Thread")
    async def test_download_model_generate_aborted(self, mock_thread_class):
        mock_thread = mock_thread_class.return_value
        mock_thread.is_alive.return_value = False

        bg_tasks = BackgroundTasks()
        await download_model(model_name="gpt-4", background_tasks=bg_tasks)
        
        download_jobs["gpt-4"]["stop_event"].set()

        generator_func = bg_tasks.tasks[0].func
        gen = generator_func()
        
        self.assertEqual(next(gen), "Aborted!\n")

    @patch("kuwa.kernel.routes.model.subprocess.run")
    async def test_hf_logout_failure(self, mock_run):
        mock_res = MagicMock()
        mock_res.returncode = 1
        mock_res.stderr = "Logout failed\n"
        mock_run.return_value = mock_res

        with self.assertRaises(HTTPException) as context:
            await hf_logout()
        self.assertEqual(context.exception.status_code, 401)


if __name__ == "__main__":
    unittest.main()
