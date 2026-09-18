import logging
import inspect
import json
import asyncio
import requests
import time
from typing import List
from .core.metrics import SAFETY_CHECKS, SAFETY_LATENCY

logger = logging.getLogger(__name__)


def safety_middleware(func, n_max_buffer=50, streaming=True):
    bypass = True
    try:
        from llm_safety_guard import LlmSafetyGuard

        bypass = False
    except ImportError:
        logger.warning(
            'Bypassing safety middleware due to the package "llm-safety-guard" is not installed.'
        )

    def wrap(*args, **kwargs):
        nonlocal func
        if bypass:
            return func(*args, **kwargs)

        # Forward path: Flask --[Convert]--> Safety Guard --[Convert]--> Chat completion backend.
        # Normal return path:  Chat completion backend --> Safety Guard --> Flask
        # Return path under violation of pre-filter rules:  Safety Guard --> Flask
        safety_guard = LlmSafetyGuard(n_max_buffer=n_max_buffer, streaming=streaming)
        local_func = to_safety_guard_signature(func)
        local_func = safety_guard.guard(local_func)
        local_func = to_completions_backend_signature(local_func)
        return local_func(*args, **kwargs)

    wrap.__signature__ = inspect.signature(func)
    return wrap


def async_safety_middleware(func, n_max_buffer=50, streaming=True):
    """
    Async-compatible variant of safety_middleware for use with async generator functions.
    The wrapped function must accept (chat_history, model_id, **kwargs) and be an async generator.
    """
    bypass = True
    safety_guard = None
    try:
        from llm_safety_guard import LlmSafetyGuard

        safety_guard = LlmSafetyGuard(n_max_buffer=n_max_buffer, streaming=streaming)
        if bool(safety_guard.disabled):
            logger.warning("[SafetyGuard] LlmSafetyGuard is DISABLED — bypassing.")
        else:
            bypass = False
            logger.info(
                "[SafetyGuard] llm-safety-guard package loaded successfully. Safety middleware is ACTIVE."
            )
    except ImportError:
        logger.warning(
            '[SafetyGuard] Bypassing safety middleware: package "llm-safety-guard" is not installed.'
        )

    async def wrap(chat_history: List[dict], model_id: str, **kwargs):
        nonlocal func
        safety_guard = None
        safety_guard_disabled = False
        if bypass:
            logger.debug(
                "[SafetyGuard] Bypassing (package not installed) for model_id=%r",
                model_id,
            )
        else:
            from llm_safety_guard import LlmSafetyGuard

            safety_guard = LlmSafetyGuard(
                n_max_buffer=n_max_buffer, streaming=streaming
            )
            safety_guard_disabled = safety_guard.disabled
            if safety_guard_disabled:
                logger.debug(
                    "[SafetyGuard] Bypassing (safety guard disabled) for model_id=%r",
                    model_id,
                )

        if bypass or safety_guard_disabled:
            SAFETY_CHECKS.labels(model_id=model_id, result="bypass").inc()
            async for chunk in func(
                chat_history=chat_history, model_id=model_id, **kwargs
            ):
                yield chunk
            return

        import queue
        import threading

        start_time = time.time()

        # Log target cache and detector status so we know if this request will be guarded
        target_cache = safety_guard.target_cache
        detector = safety_guard.detector
        detector_online = detector.is_online()
        in_targets = target_cache.should_guard(model_id)
        logger.info(
            "[SafetyGuard] Request received — model_id=%r | detector_online=%s | in_targets=%s | known_targets=%s",
            model_id,
            detector_online,
            in_targets,
            list(target_cache.targets),
        )
        if not detector_online:
            logger.warning(
                "[SafetyGuard] Detector is OFFLINE — guard will bypass this request."
            )
        if not in_targets:
            logger.warning(
                "[SafetyGuard] model_id=%r is NOT in the target list — guard will bypass. "
                "Check that this model is registered in the Manager and that update_safety_guard() has run.",
                model_id,
            )

        if not detector_online or not in_targets:
            SAFETY_CHECKS.labels(model_id=model_id, result="bypass").inc()
            async for chunk in func(
                chat_history=chat_history, model_id=model_id, **kwargs
            ):
                yield chunk
            return

        def sync_backend(chat_history: List[dict], model_id: str, *args, **kw):
            """
            Sync generator bridging the async completions_backend.
            Runs the async generator in a dedicated thread+event loop and
            pipes chunks back via a queue so LlmSafetyGuard._guard_impl can
            consume them one-by-one for pre/post-filter interception.
            """
            logger.info(
                "[SafetyGuard] sync_backend called — starting async→sync bridge for model_id=%r",
                model_id,
            )
            chunk_queue = queue.Queue()
            SENTINEL = object()

            def run_async_in_thread():
                loop = asyncio.new_event_loop()

                async def collect():
                    try:
                        async for chunk in func(
                            chat_history=chat_history, model_id=model_id, **kwargs
                        ):
                            chunk_queue.put(chunk)
                    except Exception:
                        logger.exception(
                            "[SafetyGuard] Error in async_safety_middleware backend."
                        )
                    finally:
                        chunk_queue.put(SENTINEL)

                loop.run_until_complete(collect())
                loop.close()

            t = threading.Thread(target=run_async_in_thread, daemon=True)
            t.start()

            chunk_count = 0
            while True:
                item = chunk_queue.get()
                if item is SENTINEL:
                    break
                chunk_count += 1
                yield item

            t.join()
            logger.info(
                "[SafetyGuard] sync_backend finished — %d chunks yielded for model_id=%r",
                chunk_count,
                model_id,
            )

        # Run the sync guarded generator in a dedicated thread so it never
        # blocks the asyncio event loop. Chunks are bridged back via an
        # asyncio.Queue so the async generator can properly suspend/resume.
        GUARD_SENTINEL = object()
        outer_loop = asyncio.get_running_loop()
        out_queue: asyncio.Queue = asyncio.Queue()

        def run_guarded():
            try:
                guarded = safety_guard.guard(sync_backend)
                gen = guarded(chat_history=chat_history, model_id=model_id)
                if isinstance(gen, str):
                    outer_loop.call_soon_threadsafe(out_queue.put_nowait, gen)
                else:
                    for chunk in gen:
                        outer_loop.call_soon_threadsafe(out_queue.put_nowait, chunk)
            except Exception:
                logger.exception(
                    "[SafetyGuard] Error in guarded pipeline for model_id=%r", model_id
                )
            finally:
                outer_loop.call_soon_threadsafe(out_queue.put_nowait, GUARD_SENTINEL)

        guard_thread = threading.Thread(target=run_guarded, daemon=True)
        guard_thread.start()
        logger.info(
            "[SafetyGuard] Passing request through guarded pipeline for model_id=%r",
            model_id,
        )

        out_count = 0
        while True:
            chunk = await out_queue.get()
            if chunk is GUARD_SENTINEL:
                break
            out_count += 1
            yield chunk

        guard_thread.join()
        SAFETY_LATENCY.labels(model_id=model_id).observe(time.time() - start_time)
        SAFETY_CHECKS.labels(model_id=model_id, result="pass").inc()
        logger.info(
            "[SafetyGuard] Guarded pipeline finished — %d chunks output for model_id=%r",
            out_count,
            model_id,
        )

    wrap.__wrapped__ = func
    return wrap


def to_safety_guard_signature(func):
    """
    Convert the function signature to the llm-safety-guard compatible one.
    """

    def wrap(chat_history: List[dict], model_id: str, *args, **kwargs):
        chat_history = [
            {"isbot": r["role"] == "assistant", "msg": r["content"]}
            for r in chat_history
        ]
        form = dict(kwargs.pop("form"))
        form["input"] = json.dumps(chat_history)
        form["name"] = model_id
        return func(form=form, *args, **kwargs)

    return wrap


def to_completions_backend_signature(func):
    """
    Convert the function signature to the completions_backend() compatible one.
    """

    def wrap(form: dict, *args, **kwargs):
        input = form.get("input", [])
        llm_name = form.get("name", "")
        if isinstance(input, str):
            input = json.loads(input)
        input = [
            {"role": "assistant" if r["isbot"] else "user", "content": r["msg"]}
            for r in input
        ]

        def at_exit():
            nonlocal kwargs
            dest = kwargs["dest"]
            requests.get(dest[0] + "/abort", timeout=10)
            dest[3] = -1
            dest[2] = -1
            dest[1] = "READY"
            print("Done")

        # Clean up kwargs to avoid "got multiple values for keyword argument" TypeError
        kwargs.pop("chat_history", None)
        kwargs.pop("model_id", None)
        kwargs.pop("form", None)

        return func(
            chat_history=input,
            model_id=llm_name,
            at_exit=at_exit,
            form=form,
            *args,
            **kwargs,
        )

    return wrap


def update_safety_guard():
    """
    The cronjob to update the safety guard.
    """

    try:
        from llm_safety_guard import LlmSafetyGuard

        if LlmSafetyGuard.is_disabled():
            return
        LlmSafetyGuard.update()
    except ImportError:
        pass
