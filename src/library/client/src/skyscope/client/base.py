import os
import re
import json
import asyncio
import requests
import logging
import httpx
from urllib.parse import urljoin
from typing import List

logger = logging.getLogger(__name__)


class StopAsyncGenerator(Exception):
    def __init__(self, value):
        self.value = value


class SkyscopeClient:
    def __init__(
        self,
        base_url=None,
        kernel_base_url="http://localhost:9000",
        model=".bot/.default",
        auth_token=None,
        limit: int = 3072,
    ):
        self.base_url = (
            base_url
            if base_url is not None
            else os.environ.get("SKYSCOPE_BASE_URL", "http://localhost")
        )
        self.kernel_base_url = kernel_base_url
        self.model = model
        self.auth_token = (
            auth_token
            if auth_token is not None
            else os.environ.get("SKYSCOPE_API_KEY", None)
        )
        self.limit = limit
        self.running_jobs = []

    def _request(self, endpoint, method="GET", json=None, files=None):
        headers = {
            "Authorization": f"Bearer {self.auth_token}",
            "Content-Type": "application/json" if json else None,
        }
        response = requests.request(
            method,
            f"{self.base_url}/{endpoint}",
            headers=headers,
            json=json,
            files=files,
        )
        response.raise_for_status()
        return response.json()

    def is_too_long(self, chat_history: [dict]):
        """
        A heuristic method to estimate the tokens
        """
        logger.debug(
            f"Estimated prompt tokens: {len(str(chat_history))}; Model input limit: {self.limit}"
        )
        return len(str(chat_history)) > self.limit

    async def get_available_llm(self):
        url = urljoin(self.kernel_base_url, "/v1.0/worker/list")

        loop = asyncio.get_running_loop()
        resp = await loop.run_in_executor(None, requests.get, url)
        if not resp.ok:
            return None
        llm = [
            executor
            for executor in reversed(resp.json())
            if not re.match(r"(.*[-_b]qa.*|whisper|painter|tool/.*)", executor)
        ]
        logger.debug(llm)
        llm.append(None)
        return llm[0]

    async def create_base_model(
        self,
        name: str,
        access_code: str,
        auth_token: str = None,
        order: int = None,
        version: str = None,
        description: str = None,
        system_prompt: str = None,
        react_btn: str = None,
    ):
        url = urljoin(self.base_url, "/api/user/create/base_model")
        auth_token = self.auth_token if self.auth_token is not None else auth_token
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {auth_token}",
        }
        request_body = {
            "name": name,
            "access_code": access_code,
            "order": order,
            "version": version,
            "description": description,
            "system_prompt": system_prompt,
            "react_btn": react_btn,
        }
        resp = requests.post(url, headers=headers, json=request_body)
        if not resp.ok:
            raise RuntimeError(
                f"Request failed with status {resp.status_code}, {resp.json()}"
            )
        return resp.json()

    async def create_bot(
        self,
        llm_access_code: str,
        bot_name: str,
        auth_token: str = None,
        modelfile: str = None,
        react_btn: str = None,
        bot_description: str = None,
        visibility: int = 3,
        type: str = "prompt",
    ):
        url = urljoin(self.base_url, "/api/user/create/bot")
        auth_token = self.auth_token if self.auth_token is not None else auth_token
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {auth_token}",
        }
        request_body = {
            "llm_access_code": llm_access_code,
            "modelfile": modelfile,
            "react_btn": react_btn,
            "bot_name": bot_name,
            "bot_describe": bot_description,
            "visibility": visibility,
            "bot_type": type,
        }
        logger.debug(f"Request body: {request_body}")
        resp = requests.post(url, headers=headers, json=request_body)
        if not resp.ok:
            raise RuntimeError(
                f"Request failed with status {resp.status_code}, {resp.text}"
            )
        return resp.json()

    async def chat_complete(
        self,
        auth_token: str = None,
        messages: list = [],
        timeout=120,
        streaming=True,
        botfile=None,
    ):
        generator = self.chat_complete_with_exit_code(
            auth_token=auth_token,
            messages=messages,
            timeout=timeout,
            streaming=streaming,
            botfile=botfile,
        )

        try:
            async for chunk in generator:
                yield chunk
        except StopAsyncGenerator:
            pass  # Omit the exit code

    async def chat_complete_with_exit_code(
        self,
        auth_token: str = None,
        messages: list = [],
        timeout=120,
        streaming=True,
        botfile=None,
    ):
        """
        Chat completion API with the exit code of bot.
        Since asynchronous generators does not allow "return" statement,
        the exit code will be throw as the value of StopAsyncGenerator exception.
        """
        url = urljoin(self.base_url, "/v1.0/chat/completions")
        auth_token = self.auth_token if self.auth_token is not None else auth_token
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {auth_token}",
        }
        model = self.model if self.model is not None else ".bot/.default"
        logger.debug(f"Use model {model}")
        request_body = {"messages": messages, "model": model, "stream": streaming}
        if botfile is not None:
            request_body["botfile"] = botfile

        client = httpx.AsyncClient(timeout=None)
        async with client.stream(
            "POST", url, headers=headers, json=request_body, timeout=timeout
        ) as response:
            response.raise_for_status()

            job_id = response.headers.get("x-request-id")
            exit_code = 0
            logger.debug(f"Job ID: {job_id}")
            self.running_jobs.append(job_id)

            async for line in response.aiter_lines():
                logger.debug(line)
                if not streaming:
                    result = json.loads(line)
                    yield result["choices"][0]["message"]["content"]
                    exit_code = result["choices"][0]["exit_code"]
                    continue

                if line == "data: [DONE]":
                    break
                elif line.startswith("data: "):
                    chunk = json.loads(line[len("data: ") :])["choices"][0]
                    chunk_exit_code = chunk["exit_code"]
                    if chunk_exit_code is not None:
                        exit_code = chunk_exit_code
                    if not chunk["delta"]:
                        continue
                    yield chunk["delta"]["content"]
            self.running_jobs.remove(job_id)
            raise StopAsyncGenerator(exit_code)

    async def abort(self, job_ids: List[str] | None = None, auth_token: str = None):
        if job_ids is None:
            job_ids = self.running_jobs

        url = urljoin(self.base_url, "/v1.0/chat/abort")
        auth_token = self.auth_token if self.auth_token is not None else auth_token
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {auth_token}",
        }
        logger.debug(f"Aborting jobs {job_ids}")
        request_body = {"ids": job_ids}

        async with httpx.AsyncClient(timeout=None) as client:
            resp = await client.post(url, headers=headers, json=request_body)
            resp.raise_for_status()
            logger.debug(f"Aborting response: {resp.json()}")
            return True
