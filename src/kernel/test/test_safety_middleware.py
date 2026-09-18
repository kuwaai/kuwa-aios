import unittest
import asyncio
import json
import queue
import threading
from unittest.mock import MagicMock, patch, AsyncMock
import sys
import os

# Add src/kernel/src to sys.path
sys.path.append(os.path.join(os.path.dirname(__file__), "..", "src"))

# We patch sys.modules to mock llm_safety_guard for active middleware testing
mock_safety_guard_module = MagicMock()
mock_guard_instance = MagicMock()
mock_safety_guard_module.LlmSafetyGuard.return_value = mock_guard_instance

# Setup LlmSafetyGuard stub properties
mock_guard_instance.disabled = False
mock_guard_instance.target_cache.should_guard.return_value = True
mock_guard_instance.detector.is_online.return_value = True
mock_guard_instance.target_cache.targets = {"gpt-4"}

_original_llm_safety_guard = None

def setUpModule():
    global _original_llm_safety_guard
    _original_llm_safety_guard = sys.modules.get("llm_safety_guard")
    sys.modules["llm_safety_guard"] = mock_safety_guard_module
    if "kuwa.kernel.safety_middleware" in sys.modules:
        import importlib
        importlib.reload(sys.modules["kuwa.kernel.safety_middleware"])

def tearDownModule():
    if _original_llm_safety_guard is not None:
        sys.modules["llm_safety_guard"] = _original_llm_safety_guard
    else:
        sys.modules.pop("llm_safety_guard", None)
    # Unload cached kuwa modules to force clean re-import
    sys.modules.pop("kuwa.kernel.safety_middleware", None)
    sys.modules.pop("kuwa.kernel.routes.chat", None)

from kuwa.kernel.safety_middleware import (
    safety_middleware,
    async_safety_middleware,
    to_safety_guard_signature,
    to_completions_backend_signature,
    update_safety_guard,
)


class TestSafetyMiddleware(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        # Reset mocks to ensure test isolation
        mock_safety_guard_module.LlmSafetyGuard.reset_mock()
        mock_guard_instance.reset_mock()
        # Explicitly clear side_effect as reset_mock() might not always clear it depending on how it was set
        mock_guard_instance.guard.side_effect = None
        # Restore default state
        mock_guard_instance.disabled = False
        mock_guard_instance.target_cache.should_guard.return_value = True
        mock_guard_instance.detector.is_online.return_value = True

    def test_sync_safety_middleware_bypass(self):
        # Test synchrononous safety_middleware when LlmSafetyGuard exists
        def dummy_func(form, *args, **kwargs):
            return "passed"

        # Mock the decorator to return the function unchanged
        mock_guard_instance.guard.side_effect = lambda f: f

        wrapped = safety_middleware(dummy_func)
        result = wrapped(
            chat_history=[{"role": "user", "content": "hello"}],
            model_id="gpt-4",
            form={"other": "data"},
        )
        self.assertEqual(result, "passed")

    def test_to_safety_guard_signature(self):
        def dummy_func(form, *args, **kwargs):
            return form

        wrapped = to_safety_guard_signature(dummy_func)
        result = wrapped(
            chat_history=[{"role": "user", "content": "hello"}],
            model_id="gpt-4",
            form={"other": "data"},
        )
        self.assertIn("input", result)
        self.assertEqual(result["name"], "gpt-4")
        self.assertEqual(result["other"], "data")

    def test_to_completions_backend_signature(self):
        def dummy_func(chat_history, model_id, at_exit, form, *args, **kwargs):
            return chat_history, model_id

        wrapped = to_completions_backend_signature(dummy_func)
        history, model_id = wrapped(
            form={"input": '[{"isbot": false, "msg": "hello"}]', "name": "gpt-4"},
            dest=["http://localhost:8000", "READY", -1, -1],
        )
        self.assertEqual(model_id, "gpt-4")
        self.assertEqual(history[0]["content"], "hello")

    async def test_async_safety_middleware_disabled(self):
        # Toggle guard to disabled
        mock_guard_instance.disabled = True

        async def dummy_gen(chat_history, model_id, **kwargs):
            yield "chunk1"
            yield "chunk2"

        wrapped = async_safety_middleware(dummy_gen)
        chunks = []
        async for chunk in wrapped(chat_history=[], model_id="gpt-4"):
            chunks.append(chunk)

        self.assertEqual(chunks, ["chunk1", "chunk2"])

    async def test_async_safety_middleware_active(self):
        # Toggle guard to enabled
        mock_guard_instance.disabled = False
        mock_guard_instance.target_cache.should_guard.return_value = True
        mock_guard_instance.detector.is_online.return_value = True

        async def dummy_gen(chat_history, model_id, **kwargs):
            yield "chunk1"
            yield "chunk2"

        # Mock safety_guard.guard to just return the sync backend generator
        def mock_guard_wrapper(sync_backend):
            return sync_backend

        mock_guard_instance.guard.side_effect = mock_guard_wrapper

        wrapped = async_safety_middleware(dummy_gen)
        chunks = []
        async for chunk in wrapped(chat_history=[], model_id="gpt-4"):
            chunks.append(chunk)

        # Chunks are bridged via queue threads and yield correctly
        self.assertEqual(chunks, ["chunk1", "chunk2"])

    @patch("kuwa.kernel.safety_middleware.logger")
    async def test_async_safety_middleware_error(self, mock_logger):
        mock_guard_instance.disabled = False
        mock_guard_instance.target_cache.should_guard.return_value = True
        mock_guard_instance.detector.is_online.return_value = True

        async def dummy_gen(chat_history, model_id, **kwargs):
            yield "chunk1"
            raise Exception("Guard error")

        # Mock safety_guard.guard to just return the sync backend generator
        def mock_guard_wrapper(sync_backend):
            return sync_backend

        mock_guard_instance.guard.side_effect = mock_guard_wrapper

        wrapped = async_safety_middleware(dummy_gen)
        chunks = []
        async for chunk in wrapped(chat_history=[], model_id="gpt-4"):
            chunks.append(chunk)

        self.assertEqual(chunks, ["chunk1"])
        mock_logger.exception.assert_called()
        self.assertIn(
            "Error in async_safety_middleware backend",
            mock_logger.exception.call_args[0][0],
        )

    def test_update_safety_guard(self):
        # Call the updater
        mock_safety_guard_module.LlmSafetyGuard.is_disabled.return_value = False
        update_safety_guard()
        mock_safety_guard_module.LlmSafetyGuard.update.assert_called_once()

    def test_safety_middleware_import_error(self):
        with patch.dict("sys.modules", {"llm_safety_guard": None}):

            def dummy(form):
                return "ok"

            # Since it's imported at module level in some places, we might need to re-import or use a fresh mock
            # But the safety_middleware function has a local try-except
            from kuwa.kernel.safety_middleware import safety_middleware as sm_fresh

            wrapped = sm_fresh(dummy)
            self.assertEqual(wrapped({"form": {}}), "ok")

    @patch("kuwa.kernel.safety_middleware.logger")
    async def test_async_safety_middleware_detector_offline(self, mock_logger):
        mock_guard_instance.disabled = False
        mock_guard_instance.detector.is_online.return_value = False

        async def dummy_gen(chat_history, model_id, **kwargs):
            yield "bypass_chunk"

        wrapped = async_safety_middleware(dummy_gen)
        chunks = []
        async for chunk in wrapped(chat_history=[], model_id="gpt-4"):
            chunks.append(chunk)

        self.assertEqual(chunks, ["bypass_chunk"])
        mock_logger.warning.assert_any_call(
            "[SafetyGuard] Detector is OFFLINE — guard will bypass this request."
        )

    @patch("kuwa.kernel.safety_middleware.requests.get")
    def test_to_completions_backend_signature_at_exit(self, mock_get):
        def dummy_func(chat_history, model_id, at_exit, form, *args, **kwargs):
            # Capture the at_exit callback
            self.at_exit_cb = at_exit
            return "ok"

        wrapped = to_completions_backend_signature(dummy_func)
        dest_obj = ["http://executor", "BUSY", 123, 456]
        wrapped(form={"input": "[]", "name": "m"}, dest=dest_obj)

        # Trigger the callback
        self.at_exit_cb()

        mock_get.assert_called_with("http://executor/abort", timeout=10)
        self.assertEqual(dest_obj[1], "READY")


    async def test_async_safety_middleware_not_in_targets(self):
        mock_guard_instance.disabled = False
        mock_guard_instance.detector.is_online.return_value = True
        mock_guard_instance.target_cache.should_guard.return_value = False

        async def dummy_gen(chat_history, model_id, **kwargs):
            yield "bypass_chunk"

        wrapped = async_safety_middleware(dummy_gen)
        chunks = []
        async for chunk in wrapped(chat_history=[], model_id="gpt-4"):
            chunks.append(chunk)

        self.assertEqual(chunks, ["bypass_chunk"])

    async def test_async_safety_middleware_string_response(self):
        mock_guard_instance.disabled = False
        mock_guard_instance.target_cache.should_guard.return_value = True
        mock_guard_instance.detector.is_online.return_value = True

        async def dummy_gen(**kwargs):
            yield "unused"

        # Mock guard to return a lambda that returns a string
        mock_guard_instance.guard.side_effect = lambda f: (lambda **kw: "single string response")

        wrapped = async_safety_middleware(dummy_gen)
        chunks = []
        async for chunk in wrapped(chat_history=[], model_id="gpt-4"):
            chunks.append(chunk)

        self.assertEqual(chunks, ["single string response"])

    @patch("kuwa.kernel.safety_middleware.logger")
    async def test_async_safety_middleware_backend_error_logging(self, mock_logger):
        mock_guard_instance.disabled = False
        mock_guard_instance.target_cache.should_guard.return_value = True
        mock_guard_instance.detector.is_online.return_value = True

        async def dummy_gen_fail(**kwargs):
            yield "chunk1"
            raise Exception("Backend failure")

        # Mock guard to just return the sync backend
        mock_guard_instance.guard.side_effect = lambda f: f

        wrapped = async_safety_middleware(dummy_gen_fail)
        async for _ in wrapped(chat_history=[], model_id="gpt-4"):
            pass

        # Ensure the backend thread has finished and logged
        await asyncio.sleep(0.1)
        mock_logger.exception.assert_any_call(
            "[SafetyGuard] Error in async_safety_middleware backend."
        )

    async def test_async_safety_middleware_pipeline_error(self):
        mock_guard_instance.disabled = False
        mock_guard_instance.target_cache.should_guard.return_value = True
        mock_guard_instance.detector.is_online.return_value = True

        async def dummy_gen(chat_history, model_id, **kwargs):
            yield "chunk1"

        def mock_guarded_fail(*args, **kwargs):
            yield "chunk1"
            raise Exception("Pipeline failure")

        # Use side_effect to return the failing generator
        mock_guard_instance.guard.side_effect = lambda f: mock_guarded_fail

        # Directly patch the logger in the module
        import kuwa.kernel.safety_middleware as sm
        with patch.object(sm, "logger") as mock_logger:
            wrapped = async_safety_middleware(dummy_gen)
            # We must consume the generator to trigger the thread and its exception
            async for _ in wrapped(chat_history=[], model_id="gpt-4"):
                pass

            # Ensure the thread has time to log the exception
            for _ in range(20):
                if mock_logger.exception.called:
                    break
                await asyncio.sleep(0.05)
            
            mock_logger.exception.assert_called()
            self.assertIn(
                "Error in guarded pipeline",
                mock_logger.exception.call_args[0][0],
            )

    def test_update_safety_guard_disabled(self):
        mock_safety_guard_module.LlmSafetyGuard.is_disabled.return_value = True
        update_safety_guard()
        # Should return early and not call update()
        mock_safety_guard_module.LlmSafetyGuard.update.assert_not_called()

    def test_update_safety_guard_import_error(self):
        with patch.dict("sys.modules", {"llm_safety_guard": None}):
            from kuwa.kernel.safety_middleware import update_safety_guard as usg_fresh
            # Should not raise exception
            usg_fresh()

    @patch("kuwa.kernel.safety_middleware.logger")
    async def test_async_safety_middleware_dynamic_disabled(self, mock_logger):
        # 1. Create with disabled=False so bypass=False
        mock_guard_instance.disabled = False
        
        async def mock_func(**kwargs):
            yield "chunk"
            
        wrapped = async_safety_middleware(mock_func)
        
        # 2. Set disabled=True for call time
        mock_guard_instance.disabled = True
        async for _ in wrapped(chat_history=[], model_id="gpt-4"):
            break
            
        mock_logger.debug.assert_any_call(
            "[SafetyGuard] Bypassing (safety guard disabled) for model_id=%r",
            "gpt-4"
        )


if __name__ == "__main__":
    unittest.main()
