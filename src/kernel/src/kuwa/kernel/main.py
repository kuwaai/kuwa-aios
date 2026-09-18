import time, re, os, click, requests, sys, asyncio
import logging.config
import argparse
from datetime import datetime
from fastapi import FastAPI, BackgroundTasks, APIRouter, Request
from fastapi.responses import HTMLResponse
from fastapi.staticfiles import StaticFiles
import logging
from apscheduler.schedulers.background import BackgroundScheduler

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from .core import config
from .core.config import (
    PROCESSOR_LIST_FILE,
    SAFETY_GUARD_UPDATE_INTERVAL_SEC,
    EXECUTOR_HEALTH_CHECK_INTERVAL_SEC,
)
from .core.state import download_jobs
from .core.scheduler import JobScheduler
from .core.health import periodic_health_check
from .core.persistence import (
    load_processor_list,
    load_processor_list_from_file,
    save_processor_list,
)
from .logger import KernelLoggerFactory
from .safety_middleware import update_safety_guard
from .routes.executor import executor
from .routes.model import model
from .routes.chat import chat
from .core.metrics import (
    DOWNLOAD_JOBS_ACTIVE,
    HTTP_REQUESTS_TOTAL,
    HTTP_REQUEST_DURATION,
)
from prometheus_client import generate_latest, CONTENT_TYPE_LATEST
from fastapi import Response

logger = logging.getLogger(__name__)

KUWA_KERNEL_API_VERSION = "v1.0"
MEGABYTE = 2**20
MAX_PART_SIZE = 512 * MEGABYTE


def main():
    parser = argparse.ArgumentParser(prog="Kuwa Kernel", description="Kuwa Kernel")
    parser.add_argument("--log_level", type=str, default="INFO", help="Log level")
    parser.add_argument("--port", type=int, default=9000, help="The port to serve")
    parser.add_argument(
        "--host", type=str, default="0.0.0.0", help="The host IP address to serve"
    )
    parser.add_argument(
        "--response_cache", action="store_true", help="Enable response cache"
    )
    args = parser.parse_args()
    logging.config.dictConfig(KernelLoggerFactory(level=args.log_level).get_config())

    config.RESPONSE_CACHE_ENABLED = args.response_cache

    if os.path.exists(PROCESSOR_LIST_FILE):
        load_processor_list(load_processor_list_from_file(PROCESSOR_LIST_FILE))

    # Schedule background job to update the Safety Guard
    logging.getLogger("apscheduler.executors.default").setLevel(logging.WARNING)
    scheduler = BackgroundScheduler()
    scheduler.add_job(
        func=update_safety_guard,
        trigger="interval",
        seconds=SAFETY_GUARD_UPDATE_INTERVAL_SEC,
        next_run_time=datetime.now(),
    )
    scheduler.add_job(
        func=periodic_health_check,
        trigger="interval",
        seconds=EXECUTOR_HEALTH_CHECK_INTERVAL_SEC,
    )
    scheduler.start()

    # Init FastAPI app
    app = FastAPI()

    @app.middleware("http")
    async def metrics_middleware(request: Request, call_next):
        method = request.method
        path = request.url.path
        start_time = time.time()

        # Track active download jobs (simplified update)
        DOWNLOAD_JOBS_ACTIVE.set(len(download_jobs))

        response = await call_next(request)

        duration = time.time() - start_time
        status_code = response.status_code

        HTTP_REQUESTS_TOTAL.labels(
            method=method, path=path, status_code=status_code
        ).inc()
        HTTP_REQUEST_DURATION.labels(method=method, path=path).observe(duration)

        return response

    @app.get("/metrics")
    async def metrics():
        return Response(content=generate_latest(), media_type=CONTENT_TYPE_LATEST)

    # Middleware to handle request cancellation
    class RequestCancelledMiddleware:
        def __init__(self, app):
            self.app = app

        async def __call__(self, scope, receive, send):
            if scope["type"] != "http":
                await self.app(scope, receive, send)
                return

            queue = asyncio.Queue()

            async def message_poller(sentinel, handler_task):
                nonlocal queue
                while True:
                    message = await receive()
                    if message["type"] == "http.disconnect":
                        handler_task.cancel()
                        return sentinel
                    await queue.put(message)

            sentinel = object()
            handler_task = asyncio.create_task(self.app(scope, queue.get, send))
            asyncio.create_task(message_poller(sentinel, handler_task))

            try:
                return await handler_task
            except Exception:
                if scope["path"].endswith("completions"):
                    job_id = scope["job_id"]
                    job = JobScheduler().jobs.get_by_id(job_id)
                    if job:
                        print(f"Cancelling job {job_id} due to disconnect")
                        JobScheduler().jobs.remove(job)
                    else:
                        print(f"Job {job_id} not found for cancellation")

    # app.add_middleware(RequestCancelledMiddleware)
    app.include_router(executor, prefix=f"/{KUWA_KERNEL_API_VERSION}/worker")
    app.include_router(chat, prefix=f"/{KUWA_KERNEL_API_VERSION}/chat")
    app.include_router(model, prefix=f"/{KUWA_KERNEL_API_VERSION}/model")

    @app.get("/health")
    async def health():
        return {"status": "ok"}

    logger.info("Route list:\n{}\n".format("\n".join([str(i) for i in app.routes])))
    logger.info("Server started")

    # Run the app
    import uvicorn

    try:
        uvicorn.run(app, host=args.host, port=args.port)
    except KeyboardInterrupt:
        "Exiting..."

    # Stop any active download jobs (if applicable)
    for model_name in list(download_jobs.keys()):
        job_details = download_jobs[model_name]
        job_details["stop_event"].set()
        if job_details["process"]:
            job_details["process"].terminate()
        job_details["thread"].join()

    # Stopped, saving to file
    save_processor_list(PROCESSOR_LIST_FILE)


if __name__ == "__main__":
    main()
