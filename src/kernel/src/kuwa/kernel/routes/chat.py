import requests
import httpx, time
from urllib.parse import urlencode
from typing import List, Optional
from fastapi import APIRouter, Request, HTTPException, Query, Header
from fastapi.responses import StreamingResponse
from fastapi.background import BackgroundTasks
from starlette.responses import StreamingResponse
from starlette.requests import ClientDisconnect
from starlette.types import Receive, Scope, Send
from ..safety_middleware import safety_middleware, async_safety_middleware
from ..response_cache import ResponseCache
from ..core import config
from ..core.job import Job, JobStateEnum
from ..core.scheduler import JobScheduler
from ..core.metrics import CLIENT_DISCONNECTS, CACHE_REQUESTS, PROCESSORS_REMOVED
from datetime import datetime
import threading
import random
import json
import asyncio
import logging

logger = logging.getLogger(__name__)

# Create the router for handling chat-related functionality
chat = APIRouter()


def format_status_chunk(status: str, details: dict = None):
    return f'data: {json.dumps({"delta": [{"type": "status", "status": {"status": status, "timestamp": time.time(), "details": details or {}}}]})}\n\n'


class NonBufferedStreamingResponse(StreamingResponse):
    async def __call__(self, scope: Scope, receive: Receive, send: Send):
        # Send the initial HTTP response start message
        await send(
            {
                "type": "http.response.start",
                "status": self.status_code,
                "headers": self.raw_headers,  # Use raw_headers directly (already in bytes)
            }
        )

        # Stream the response body
        try:
            async for chunk in self.body_iterator:
                if isinstance(chunk, str):
                    chunk = chunk.encode("utf-8")  # Ensure proper encoding
                await send(
                    {
                        "type": "http.response.body",
                        "body": chunk,
                        "more_body": True,  # Indicate more content will follow
                    }
                )
        except (httpx.LocalProtocolError, httpx.RemoteProtocolError) as e:
            logger.warning(f"HTTP protocol error while streaming response: {e}")
            return

        # Signal the end of the response body
        await send(
            {
                "type": "http.response.body",
                "body": b"",
                "more_body": False,
            }
        )


@chat.post("/completions")
async def completions(request: Request):
    start = time.time()
    form = await request.form()
    job = Job(form.get("user_id"), form, request.headers)
    request.scope["job_id"] = job.job_id

    async def event_stream():
        response_cache = ResponseCache()
        enqueued = False
        try:
            # Check cache
            user_input = ""
            if config.RESPONSE_CACHE_ENABLED:
                try:
                    chat_history = json.loads(job.form.get("input", "[]"))
                    if chat_history:
                        last_msg = chat_history[-1]
                        user_input = last_msg.get("content", last_msg.get("msg", ""))
                except Exception:
                    logger.warning(f"Failed to parse input for job {job.job_id}")

                cached_response = response_cache.get(user_input)
                if cached_response:
                    logger.info(f"Cache hit for job {job.job_id}")
                    yield cached_response
                    return

            JobScheduler().jobs.enqueue(job)
            enqueued = True
            try:
                await_count, eta = JobScheduler().get_queue_info(job)
            except Exception:
                logger.exception("Failed to get queue info")
                await_count, eta = 0, 0.0
            yield format_status_chunk("QUEUEING", {"await": await_count, "eta": eta})

            queue = time.time()
            # Wait for the job endpoint to be set
            try:
                await asyncio.wait_for(job.assigned.wait(), timeout=config.COMPLETION_TIMEOUT)
            except asyncio.TimeoutError:
                if job.state == JobStateEnum.TERMINATED:
                    yield format_status_chunk("TERMINATED")
                    return
                logger.warning(
                    f"Job {job.job_id} timed out waiting for an available processor after {config.COMPLETION_TIMEOUT} seconds."
                )
                yield "[Error] Request timed out waiting for an available processor."
                return

            if job.state == JobStateEnum.TERMINATED:
                yield format_status_chunk("TERMINATED")
                return

            yield format_status_chunk("PROCESSING")

            processing = time.time()

            input_data = json.loads(job.form["input"])
            # input_data is in OpenAI format: [{'role': ..., 'content': ..., 'attachments': [...]}]
            # No conversion needed; pass through as-is
            chat_history = input_data
            model_id = job.form.get("name", "")

            prompt = input_data[-1].get("content", "").strip() if input_data else ""
            if config.RESPONSE_CACHE_ENABLED:
                cached = response_cache.get(prompt)

                if cached is not None:
                    CACHE_REQUESTS.labels(result="hit").inc()
                    logging.info(f'Cache hit for prompt "{prompt}"')
                    yield cached
                    return

                CACHE_REQUESTS.labels(result="miss").inc()

            full_response = ""

            async for chunk in completions_backend(
                chat_history=chat_history,
                model_id=model_id,
                endpoint=job.processor.endpoint if job.processor else "",
                headers=job.headers,
                form=job.form,
                job_id=job.job_id,
            ):
                yield chunk
                full_response += chunk

            if config.RESPONSE_CACHE_ENABLED:
                response_cache.put(prompt, full_response)

            finished = time.time()
            with open("result.log", "a+") as file:
                file.write(
                    "\t".join(
                        [
                            str(datetime.fromtimestamp(i))
                            for i in [start, queue, processing, finished]
                        ]
                    )
                    + "\n"
                )
        except (asyncio.CancelledError, ClientDisconnect):
            CLIENT_DISCONNECTS.inc()
            logger.info("Event stream cancelled (client disconnected).")
        except (httpx.LocalProtocolError, httpx.RemoteProtocolError) as e:
            logger.warning(f"HTTP protocol error in event stream: {e}")
        except Exception:
            logger.exception("Error processing event string.")
        finally:
            logger.info("Event stream finished.")
            job.mark_completed()
            if enqueued:
                await job.terminated.wait()
            else:
                job.mark_terminated()

            if job.state == JobStateEnum.TERMINATED:
                yield format_status_chunk("TERMINATED")

    # Return a non-buffered streaming response
    return NonBufferedStreamingResponse(event_stream(), media_type="text/plain")


@async_safety_middleware
async def completions_backend(
    chat_history: list, model_id: str, endpoint: str, headers: dict, form: dict,
    job_id: str = None,
):
    """
    The backend portion of the completions endpoint. It forwards the user
    request to the backend. The @async_safety_middleware decorator installs the
    safety guard so that inputs/outputs are checked before/after reaching the backend.
    Arguments:
        chat_history: The conversation history in role/content format.
        model_id: The LLM access code / model name.
        endpoint: The backend endpoint URL.
        headers: The request headers to forward.
        form: The form data to forward.
        job_id: The kernel-side job id, forwarded so the executor can scope
            a later /abort call to this specific job instead of whatever it
            happens to be running at the time.
    Yields:
        Response chunks from the backend.
    """
    try:
        if not isinstance(endpoint, str):
            endpoint = "http://localhost:8000"

        keepalive_headers = {
            "Connection": "keep-alive",
            "Keep-Alive": f"timeout={config.COMPLETION_TIMEOUT}, max=1",
        }
        # Strip headers that httpx must set itself based on the re-encoded body.
        # Forwarding the original Content-Type (multipart/form-data) and Content-Length
        # from the browser causes h11 to raise "Too little data for declared Content-Length"
        # because the body is re-encoded as application/x-www-form-urlencoded.
        _do_not_forward = frozenset(
            {
                "content-type",
                "content-length",
                "transfer-encoding",
                "host",
            }
        )
        filtered_headers = {
            k: v for k, v in headers.items() if k.lower() not in _do_not_forward
        }

        # Executors expect form-encoded input:
        # - "input" field: JSON string of messages
        # - "modelfile" field: Bot configuration override
        input_json = json.dumps(chat_history)
        form_data = {
            "input": input_json,
            "name": model_id,
            "modelfile": form.get("modelfile", ""),
        }
        if job_id:
            form_data["job_id"] = job_id
        # Forward any other form fields that might be present
        for key in form.keys():
            if key not in form_data and key not in ("input", "name", "modelfile"):
                try:
                    form_data[key] = form.get(key, "")
                except Exception:
                    pass  # Skip fields that can't be retrieved

        async with httpx.AsyncClient() as client:
            async with client.stream(
                "POST",
                endpoint,
                headers={**filtered_headers, **keepalive_headers},
                data=form_data,
                timeout=httpx.Timeout(
                    connect=10,
                    read=config.COMPLETION_TIMEOUT,
                    write=config.COMPLETION_TIMEOUT,
                    pool=config.COMPLETION_TIMEOUT,
                ),
            ) as response:
                async for chunk in response.aiter_text():
                    yield chunk
    except httpx.ConnectError:
        logger.exception(f"Failed to connect to backend endpoint: {endpoint}")
        scheduler = JobScheduler()
        with scheduler.lock:
            for p in scheduler.processors:
                if p.endpoint == endpoint:
                    p.failure_count += 1
                    if p.failure_count >= config.EXECUTOR_HEALTH_CHECK_RETRIES:
                        scheduler.processors.remove(p)
                        PROCESSORS_REMOVED.labels(
                            reason="inference_connection_error"
                        ).inc()
        return
    except httpx.RemoteProtocolError as e:
        logger.warning(f"Remote endpoint closed connection early: {endpoint} | {e}")
        scheduler = JobScheduler()
        with scheduler.lock:
            for p in scheduler.processors:
                if p.endpoint == endpoint:
                    p.failure_count += 1
                    if p.failure_count >= config.EXECUTOR_HEALTH_CHECK_RETRIES:
                        scheduler.processors.remove(p)
                        PROCESSORS_REMOVED.labels(
                            reason="inference_connection_error"
                        ).inc()
        return
    except httpx.LocalProtocolError as e:
        logger.warning(
            f"Local protocol error while communicating with endpoint: {endpoint} | {e}"
        )
        scheduler = JobScheduler()
        with scheduler.lock:
            for p in scheduler.processors:
                if p.endpoint == endpoint:
                    p.failure_count += 1
                    if p.failure_count >= config.EXECUTOR_HEALTH_CHECK_RETRIES:
                        scheduler.processors.remove(p)
                        PROCESSORS_REMOVED.labels(
                            reason="inference_connection_error"
                        ).inc()
        return


@chat.get("/list_jobs")
async def list_jobs(Authorization: str = Header(...)):
    job_list = [
        {"job_id": job.job_id, "access_code": job.form.get("name"), "status": job.state}
        for job in JobScheduler().jobs.list_for_user(Authorization)
    ]
    return {"jobs": job_list}


@chat.post("/abort")
async def abort(request: Request):
    """
    Abort ongoing processes based on history_id and user_id.
    """
    form = await request.form()
    history_id_str, user_id = form.get("history_id"), form.get("user_id")

    if history_id_str and user_id:
        try:
            history_ids = json.loads(history_id_str)
            if not isinstance(history_ids, list):
                history_ids = [history_ids]
            history_ids = set(int(h) for h in history_ids)
        except Exception:
            logger.exception("Failed to parse history_id")
            return "Failed"

        scheduler = JobScheduler()
        for job in scheduler.jobs.list_for_user(user_id):
            job_history_id = job.form.get("history_id")
            if job_history_id is not None and int(job_history_id) in history_ids:
                if job.state == JobStateEnum.PENDING:
                    logger.info(f"Aborting pending job {job.job_id}")
                    scheduler.jobs.dequeue(job)
                elif job.state == JobStateEnum.PROCESSING and job.processor:
                    logger.info(
                        f"Aborting processing job {job.job_id} at {job.processor.endpoint}"
                    )
                    endpoint = job.processor.endpoint
                    try:
                        async with httpx.AsyncClient() as client:
                            # job_id scopes the abort to this specific job on the
                            # executor side, so a stale/delayed abort call can't
                            # cancel a *different* job that has since started
                            # running on the same processor slot.
                            await client.get(
                                endpoint + "/abort",
                                params={"job_id": job.job_id},
                                timeout=10,
                            )
                    except httpx.HTTPError:
                        logger.exception(
                            f"Failed to abort job at {endpoint}"
                        )
                    finally:
                        job.mark_completed()

    return "Success"
