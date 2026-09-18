import logging, requests
import json, threading
import time, os, subprocess
import aiohttp, asyncio
from textwrap import dedent
from datetime import datetime
from urllib.parse import urlparse
from fastapi import APIRouter, Request, HTTPException
from fastapi.responses import (
    JSONResponse,
    RedirectResponse,
    StreamingResponse,
    HTMLResponse,
    PlainTextResponse,
)
from fastapi.encoders import jsonable_encoder
from ..core.config import PROCESSOR_LIST_FILE
from ..core.utils import endpoint_formatter, get_base_url
from ..core.scheduler import JobScheduler
from ..core.processor import Processor
from ..core.persistence import save_processor_list
from ..core.metrics import PROCESSORS_REMOVED

executor = APIRouter()

logger = logging.getLogger(__name__)


@executor.post("/schedule")
async def status(request: Request):
    # Deprecated function
    return PlainTextResponse("READY", media_type="text/html")


# Executor Router
@executor.post("/register")
async def register(request: Request):
    form = await request.form()
    llm_name_raw = form.get("name")
    endpoint_raw = form.get("endpoint")
    if llm_name_raw is None or endpoint_raw is None:
        return "Failed"

    llm_name, endpoint = str(llm_name_raw), str(endpoint_raw)
    if (
        not endpoint or not llm_name or not endpoint_formatter(endpoint)
    ):  # in [j[0] for j in active_executions.get(llm_name, [])]:
        return "Failed"

    formatted_endpoint = endpoint_formatter(endpoint)

    scheduler = JobScheduler()
    with scheduler.lock:
        stale_processors = []
        for p in scheduler.processors:
            if p.access_code == llm_name:
                if p.endpoint == formatted_endpoint:
                    p.failure_count = 0
                    p.registration_time = asyncio.get_event_loop().time()
                    logger.info(
                        f"Re-registered {llm_name} at {endpoint}, health status reset"
                    )
                    return "Success"
                continue
            if p.endpoint == formatted_endpoint:
                stale_processors.append(p)

        for stale in stale_processors:
            scheduler.processors.remove(stale)
            logger.info(
                f"Removed stale processor {stale.access_code} at {stale.endpoint} "
                f"before registering {llm_name} at {formatted_endpoint}."
            )

        scheduler.processors.add(
            Processor(
                llm_name, formatted_endpoint, max_jobs=int(str(form.get("limit", 1)))
            )
        )
    logger.info(f"A new {llm_name} is registered at {endpoint}")
    return "Success"


@executor.post("/unregister")
async def unregister(request: Request):
    form = await request.form()
    llm_name_raw = form.get("name")
    endpoint_raw = form.get("endpoint")
    if llm_name_raw is None or endpoint_raw is None:
        return "Failed"

    llm_name = str(llm_name_raw)
    endpoint = endpoint_formatter(str(endpoint_raw))

    scheduler = JobScheduler()
    with scheduler.lock:
        for i in scheduler.processors:
            if i.access_code != llm_name:
                continue
            if i.endpoint != endpoint:
                continue
            scheduler.processors.remove(i)
            PROCESSORS_REMOVED.labels(reason="unregistered").inc()
            save_processor_list(PROCESSOR_LIST_FILE)
            logger.info(f"{llm_name} , {i.endpoint} just unregistered from agent")
            return "Success"
    logger.warning(f"{llm_name} , {endpoint} failed to unregister")
    return "Failed"


@executor.api_route("/debug", methods=["GET", "POST"])
async def debug(request: Request):
    """
    This route is for debugging.
    """
    scheduler = JobScheduler()
    if request.method == "POST":
        form = await request.form()
        raw_active_executions = json.loads(str(form.get("active_executions")))
        scheduler.processors.clear()
        for i, o in raw_active_executions.items():
            for j in o:
                scheduler.processors.add(Processor(i, j[0], 1))
        return RedirectResponse(url="/v1.0/worker/debug", status_code=303)

    if request.headers.get("Accept") == "application/json":
        exported_active_executions = {}
        model_map = scheduler.processors.get_model_map()
        for i in model_map.keys():
            exported_group = [
                {
                    "endpoint": o.endpoint,
                    "status": "READY" if o.is_idle() else "BUSY",
                    "job_history_id": -1,
                    "job_user_id": -1,
                }
                for o in model_map[i]
            ]
            exported_active_executions[i] = exported_group
        return JSONResponse(content=exported_active_executions)
    else:
        exported_active_executions = {}
        model_map = scheduler.processors.get_model_map()
        for i in model_map.keys():
            exported_group = []
            for o in model_map[i]:
                exported_group.append(
                    [o.endpoint, "READY" if o.is_idle() else "BUSY", -1, -1]
                )
            exported_active_executions[i] = exported_group
        # Return HTML response explicitly
        return HTMLResponse(
            content=dedent("""<form method="POST">
                <textarea name="active_executions" rows="4" cols="50">{}</textarea><br>
                <input type="submit" value="Submit">
            </form>
            <script>
                document.querySelector("textarea").style.height = 'auto';
                document.querySelector("textarea").style.height = (document.querySelector("textarea").scrollHeight) + 'px';
            </script>
        """).format(str(json.dumps(exported_active_executions, indent=2)))
        )


@executor.get("/list")
async def list_executor():
    return JSONResponse(content=list(JobScheduler().processors.get_model_map().keys()))


@executor.get("/read")
async def read_executor():
    exported_active_executions = {}
    scheduler = JobScheduler()
    now_monotonic = time.monotonic()
    for p in scheduler.processors:
        registered_seconds_ago = max(0, int(now_monotonic - p.registration_time))
        meta = {
            "current_jobs": len(p.current_jobs),
            "req": len(p.current_jobs),
            "max_jobs": p.max_jobs,
            "lim": p.max_jobs,
            "alive": p.failure_count == 0,
            "failure_count": p.failure_count,
            "registered_at": p.registration_time,
            "registered_seconds_ago": registered_seconds_ago,
            "status": "READY" if p.is_idle() else "BUSY",
        }
        exported_active_executions.setdefault(p.access_code, []).append(
            [p.endpoint, "READY" if p.is_idle() else "BUSY", -1, -1, meta]
        )
    return JSONResponse(content=exported_active_executions)


@executor.post("/create")
async def create_executor(request: Request):
    form = await request.json()
    access_code = form.get("access_code")
    url = form.get("url") or form.get("endpoint")
    limit = form.get("limit", 1)
    if not access_code or not url or not endpoint_formatter(url):
        return JSONResponse(
            content={"error": "Invalid access_code or url"}, status_code=400
        )
    endpoint = endpoint_formatter(url)
    processor = Processor(access_code, endpoint, max_jobs=int(limit))
    healthy = await processor.async_health_check(max_failures=1)
    if not healthy:
        logger.warning(f"Health check failed for {endpoint}, refusing to register")
        return JSONResponse(
            content={"error": "Executor health check failed"}, status_code=502
        )
    JobScheduler().processors.add(processor)
    save_processor_list(PROCESSOR_LIST_FILE)
    logger.info(f"Created {processor}")
    return JSONResponse(content={"status": "success"})


@executor.post("/delete")
async def delete_executor(request: Request):
    form = await request.json()
    access_code = form.get("access_code")
    url = form.get("url") or form.get("endpoint")
    if not access_code or not url:
        return JSONResponse(
            content={"error": "Invalid access_code or url"}, status_code=400
        )
    base = get_base_url(url)
    scheduler = JobScheduler()
    for p in scheduler.processors:
        if p.access_code == access_code and get_base_url(p.endpoint) == base:
            scheduler.processors.remove(p)
            PROCESSORS_REMOVED.labels(reason="deleted").inc()
            save_processor_list(PROCESSOR_LIST_FILE)
            logger.info(f"Deleted executor {access_code} at {base}")
            return JSONResponse(content={"status": "success"})
    return JSONResponse(content={"error": "Executor not found"}, status_code=404)


@executor.post("/update")
async def update_executor(request: Request):
    form = await request.json()

    # Support lookup by original_* fields (sent by the frontend) or fallback to plain fields
    lookup_access_code = form.get("original_access_code") or form.get("access_code")
    lookup_url = (
        form.get("original_endpoint")
        or form.get("original_url")
        or form.get("url")
        or form.get("endpoint")
    )

    if not lookup_access_code or not lookup_url:
        return JSONResponse(
            content={"error": "Invalid access_code or url"}, status_code=400
        )

    lookup_base = get_base_url(lookup_url)

    scheduler = JobScheduler()
    for p in scheduler.processors:
        if (
            p.access_code == lookup_access_code
            and get_base_url(p.endpoint) == lookup_base
        ):
            field, value = form.get("field"), form.get("value")
            if field and value is not None:
                # Single-field update (from updateField)
                if field == "max_jobs":
                    p.max_jobs = int(value)
                elif field in ("access_code", "name"):
                    p.access_code = value
                elif field in ("url", "endpoint"):
                    p.endpoint = endpoint_formatter(value)
            else:
                # Bulk update (from updateData) — new values are the non-original_ fields
                new_access_code = form.get("access_code")
                new_endpoint = form.get("endpoint") or form.get("url")
                if new_access_code:
                    p.access_code = new_access_code
                if new_endpoint:
                    p.endpoint = endpoint_formatter(new_endpoint)
                if form.get("max_jobs") is not None:
                    p.max_jobs = int(form.get("max_jobs"))
            save_processor_list(PROCESSOR_LIST_FILE)
            logger.info(f"Updated executor {lookup_access_code} at {lookup_base}")
            return JSONResponse(content={"status": "success"})
    return JSONResponse(content={"error": "Executor not found"}, status_code=404)


@executor.post("/shutdown")
async def shutdown_executor(request: Request):
    form = await request.json()
    access_code = form.get("access_code")
    url = form.get("url") or form.get("endpoint")
    if not access_code or not url:
        return JSONResponse(
            content={"error": "Invalid access_code or url"}, status_code=400
        )
    base = get_base_url(url)
    scheduler = JobScheduler()
    for p in scheduler.processors:
        if p.access_code == access_code and get_base_url(p.endpoint) == base:
            try:
                async with aiohttp.ClientSession() as session:
                    await session.get(
                        base + "/shutdown", timeout=aiohttp.ClientTimeout(total=5)
                    )
            except Exception as e:
                logger.warning(
                    f"Could not reach executor shutdown endpoint at {base}: {e}"
                )
            scheduler.processors.remove(p)
            PROCESSORS_REMOVED.labels(reason="shutdown").inc()
            save_processor_list(PROCESSOR_LIST_FILE)
            logger.info(f"Shutdown executor {access_code} at {base}")
            return JSONResponse(content={"status": "success"})
    return JSONResponse(content={"error": "Executor not found"}, status_code=404)
