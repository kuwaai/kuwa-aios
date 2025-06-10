import requests
import sys
import argparse
import socket
import time
import logging
import atexit
import signal
import asyncio
import json
import traceback
from urllib.parse import urljoin
from typing import Optional
from functools import reduce
from itertools import compress

import uvicorn
import prometheus_client
from retry import retry
from fastapi import FastAPI, Response, Request
from fastapi.responses import JSONResponse, StreamingResponse

from .metrics import ExecutorMetrics
from .logger import ExecutorLoggerFactory
from .message import BaseChunk, TextChunk, LogChunk, ExitCodeChunk, LogLevel

logger = logging.getLogger(__name__)


class AdvancedJSONEncoder(json.JSONEncoder):
    def default(self, obj):
        if hasattr(obj, "__jsonencode__"):
            return obj.__jsonencode__()

        if isinstance(obj, set):
            return list(obj)
        return super().default(obj)


def find_free_port():
    port = None
    with socket.socket() as s:
        port = s.bind(("", 0)) or s.getsockname()[1]
    return port


class BaseExecutor:
    """
    The basic functionality of an Executor.
    Including serving HTTP requests and communicate with the kernel.
    """

    executor_iface_version: str = "v1.0"
    kernel_url: str = "http://127.0.0.1:9000/"
    ignore_kernel: bool = False
    https: bool = False
    host: Optional[str] = None
    port: Optional[int] = None
    executor_path: str = "/chat"
    access_codes: Optional[str] = []

    concurrent_requests: int = 0
    concurrent_req_limit: int = 1
    ready: bool = False

    log_level: str = "INFO"
    metrics: Optional[ExecutorMetrics] = None

    def __init__(self):
        self.app = FastAPI()
        self.parser = self._create_parser()
        self.extend_arguments(parser=self.parser)

    def _create_parser(self):
        parser = argparse.ArgumentParser(
            description="Base executor, Please make sure your kernel is working before use.",
            formatter_class=argparse.ArgumentDefaultsHelpFormatter,
        )
        group = parser.add_argument_group("General Options")
        group.add_argument("--access_code", nargs="+", help="Access code")
        group.add_argument("--ignore_kernel", action="store_true", help="Ignore kernel")
        group.add_argument(
            "--https",
            action="store_true",
            help="Register the executor endpoint with https scheme",
        )
        group.add_argument(
            "--host",
            default=None,
            help="The hostname or IP address that will be stored in Kernel, Make sure the location are accessible by Kernel",
        )
        group.add_argument(
            "--port",
            type=int,
            default=None,
            help="The port to serve. By choosing None, it'll assign an unused port",
        )
        group.add_argument(
            "--executor_path",
            default=self.executor_path,
            help="The path this model executor is going to use",
        )
        group.add_argument(
            "--kernel_url",
            default=self.kernel_url,
            help="Base URL of Kernel's executor management API",
        )
        group.add_argument(
            "--concurrent_req_limit",
            default=self.concurrent_req_limit,
            help="The number of allowed concurrent requests.",
        )
        group.add_argument(
            "--log",
            type=str.upper,
            default=self.log_level,
            help="The logging level.",
            choices=["NOTSET", "DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"],
        )

        return parser

    def extend_arguments(self, parser: argparse.ArgumentParser):
        """
        Append command-line arguments.
        """
        pass

    def _setup(self):
        # Setup logger
        self.log_level = self.args.log.upper()
        logging.config.dictConfig(
            ExecutorLoggerFactory(level=self.log_level).get_config()
        )

        # Registration information
        self.kernel_url = self.args.kernel_url
        self.ignore_kernel = self.args.ignore_kernel
        self.access_codes = self.args.access_code
        if self.access_codes is None or len(self.access_codes) == 0:
            raise ValueError("Argument --access_code is mandatory.")

        # Serving URL
        self.host = self.args.host or socket.gethostbyname(socket.gethostname())
        self.port = self.args.port or find_free_port()
        self.https = self.args.https
        self.executor_path = self.args.executor_path

        # Metrics
        self.metrics = ExecutorMetrics(self.access_codes[0])
        self.metrics.state.state("idle")

        self._register_routes()

    def setup(self):
        """
        User defined setup procedure
        """
        pass

    def _register_routes(self):
        @self.app.post(self.executor_path)
        async def api(request: Request):
            if self.concurrent_requests >= self.concurrent_req_limit:
                return JSONResponse(
                    {"msg": "Processing another request."}, status_code=429
                )
            content = await request.form()
            header = request.headers
            if not content:
                logger.debug("Received empty request!")
                return JSONResponse({"msg": "Received empty request!"}, status_code=400)
            logger.debug(f"HTTP headers: {header}")
            resp = StreamingResponse(
                self._serve(header=header, content=content),
                media_type="text/event-stream",
                headers={"Content-Type": "text/event-stream; charset=utf-8"},
            )
            return resp

        @self.app.get("/shutdown")
        async def shutdown(request: Request):
            """
            Gracefully shut down the server.
            """
            logger.info("Shutdown requested")
            signal.raise_signal(signal.SIGINT)
            return JSONResponse({"msg": "Shutting down..."}, status_code=200)

        @self.app.get("/health")
        async def health_check():
            return Response(status_code=204)

        @self.app.get(urljoin(f"{self.executor_path}/", "./abort"))
        async def abort():
            if hasattr(self, "abort") and callable(self.abort):
                return JSONResponse({"msg": await self.abort()})
            return JSONResponse({"msg": "No abort method configured"}, status_code=404)

        @self.app.get("/metrics")
        async def get_metrics():
            return Response(
                content=prometheus_client.generate_latest(), media_type="text/plain"
            )

    def run(self):
        self.args = self.parser.parse_args()
        self._setup()
        self.setup()
        atexit.register(self._shut_down)
        self._start_server()

    def get_reg_endpoint(self) -> str:
        scheme = "https" if self.args.https else "http"
        return urljoin(f"{scheme}://{self.host}:{self.port}/", self.executor_path)

    def in_debug(self) -> bool:
        return self.log_level.upper() == "DEBUG"

    def _shut_down(self):
        if not hasattr(self, "registered") or not self.registered:
            return
        for access_code in self.access_codes:
            try:
                response = requests.post(
                    urljoin(
                        self.kernel_url,
                        f"{self.executor_iface_version}/worker/unregister",
                    ),
                    data={"name": access_code, "endpoint": self.get_reg_endpoint()},
                )
                if not response.ok or response.text == "Failed":
                    raise RuntimeWarning()
                else:
                    logger.info(f"Unregistered {access_code} from kernel.")
                    self.registered = False
            except requests.exceptions.ConnectionError:
                logger.exception(f"Failed to unregister {access_code} from kernel")

    @retry(tries=5, delay=1, backoff=2, jitter=(0, 1), logger=logger)
    def _try_register(self, access_code):
        resp = requests.post(
            url=urljoin(
                self.kernel_url, f"{self.executor_iface_version}/worker/register"
            ),
            data={"name": access_code, "endpoint": self.get_reg_endpoint()},
        )
        if not resp.ok or resp.text == "Failed":
            raise RuntimeWarning("The server failed to register to kernel.")

    def _start_server(self):
        self.registered = False
        if not self.ignore_kernel:
            try:
                for access_code in self.access_codes:
                    self._try_register(access_code)
                    logger.info(f'Registered with the name "{access_code}"')
                self.registered = True

            except Exception:
                logger.exception("Failed to register to kernel.")

                if not self.ignore_kernel:
                    logger.info("The program will exit now.")
                    sys.exit(0)
        self.concurrent_requests = 0
        uvicorn.run(
            self.app,
            host=self.host,
            port=self.port,
            log_config=ExecutorLoggerFactory(level=self.log_level).get_config(),
        )

    def _update_statistics(self, duration_sec: float, total_output_length: int):
        """
        Update the internal statistical metrics.
        """
        if duration_sec > 0:
            throughput = total_output_length / duration_sec
            self.metrics.process_time_seconds.observe(duration_sec)
            self.metrics.output_length_charters.observe(total_output_length)
            self.metrics.output_throughput_charters_per_second.observe(throughput)

    def _format_sse(self, data: dict):
        json_data = json.dumps(data, cls=AdvancedJSONEncoder)
        return f"data: {json_data}\n"

    async def _serve(self, header, content):
        """
        The middle layer between the actual executor logic and API server logic.
        Interception of the request-response can be done in this layer.
        """

        self.concurrent_requests += 1
        self.metrics.state.state("busy")
        try:
            start_time = time.time()
            total_output_length = 0
            exit_code_chunks = [ExitCodeChunk(exit_code=ExitCodeChunk.OK)]

            async for chunks in self.serve(header=header, content=content):
                if isinstance(chunks, str):
                    chunks = TextChunk(chunks)
                if not isinstance(chunks, list):
                    chunks = [chunks]
                unsupported_chunk = [not isinstance(x, BaseChunk) for x in chunks]
                if any(unsupported_chunk):
                    raise RuntimeError(
                        f"Unsupported chunk type: {[type(x) for x in compress(chunks, unsupported_chunk)]}"
                    )
                exit_code_chunks += list(filter(lambda x: isinstance(x, ExitCodeChunk), chunks))
                total_output_length += reduce(lambda x, y: x + len(y), chunks, 0)
                yield self._format_sse({"finish_reason": None, "delta": chunks})

                # Yield control to the event loop.
                # So that other coroutine, like aborting, can run.
                await asyncio.sleep(0)

            duration_sec = time.time() - start_time
            self._update_statistics(duration_sec, total_output_length)

            yield self._format_sse(
                {
                    "finish_reason": "stop",
                    "delta": exit_code_chunks[-1:],
                    "usage": {
                        "prompt_tokens": 0,  # [TODO]
                        "completion_tokens": total_output_length,
                        "total_tokens": total_output_length,
                    },
                }
            )

        except Exception:
            logger.exception("Error occurs during generation.")
            self.metrics.failed.inc()
            display_messages = [
                LogChunk(
                    "Error occurred. Please consult support.", level=LogLevel.ERROR
                ),
                ExitCodeChunk(exit_code=ExitCodeChunk.FAILURE)
            ]
            if self.in_debug():
                display_messages.append(
                    LogChunk("\n" + traceback.format_exc(), level=LogLevel.ERROR)
                )
            yield self._format_sse(
                {
                    "finish_reason": "exception",
                    "delta": display_messages,
                    "usage": {
                        "prompt_tokens": 0,  # [TODO]
                        "completion_tokens": total_output_length,
                        "total_tokens": total_output_length,
                    },
                }
            )

        finally:
            self.metrics.state.state("idle")
            self.concurrent_requests -= 1

    async def serve(self, header, content):
        raise NotImplementedError('Executor should implement the "serve" method.')


if __name__ == "__main__":
    executor = BaseExecutor()
    executor.run()
