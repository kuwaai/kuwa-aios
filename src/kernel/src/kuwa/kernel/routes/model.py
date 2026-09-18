import os
import threading
import time
import subprocess
import shutil
from datetime import datetime
from fastapi import APIRouter, HTTPException, BackgroundTasks
from fastapi.responses import JSONResponse
from pydantic import BaseModel
from typing import List, Optional

from ..core.state import download_jobs

model = APIRouter()


# Pydantic models for input validation
class ModelRequest(BaseModel):
    model_name: Optional[str] = None
    folder_name: Optional[str] = None
    model_path: Optional[str] = None
    visible_gpu: Optional[str] = None
    limit: Optional[int] = None
    timeout: Optional[int] = None
    token: Optional[str] = None


def ensure_cache_directory():
    cache_dir = os.path.join(os.path.expanduser("~"), ".cache", "huggingface", "hub")
    os.makedirs(cache_dir, exist_ok=True)
    return cache_dir


def clean_up_partial_download(model_name):
    time.sleep(1)
    base_model_dir = os.path.join(
        os.path.expanduser("~"),
        ".cache",
        "huggingface",
        "hub",
        "models--" + model_name.replace("/", "--"),
    )
    try:
        shutil.rmtree(base_model_dir)
        shutil.rmtree(
            os.path.join(
                os.path.expanduser("~"),
                ".cache",
                "huggingface",
                "hub",
                ".locks",
                "models--" + model_name.replace("/", "--"),
            )
        )
    except Exception as e:
        print(f"Error during cleanup: {e}")


def capture_output(pipe, output_list, stop_event):
    for line in iter(pipe.readline, ""):
        if stop_event.is_set():
            pipe.close()
            break
        output_list.append(line.strip())
    pipe.close()


def download_model_cli(model_name, result_list, stop_event):
    cache_dir = ensure_cache_directory()
    command = ["hf", "download", model_name, "--cache-dir", cache_dir]
    result_list.append("Executing: " + " ".join(command))
    process = subprocess.Popen(
        command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, bufsize=1
    )
    download_jobs[model_name]["process"] = process

    stdout_thread = threading.Thread(
        target=capture_output, args=(process.stdout, result_list, stop_event)
    )
    stderr_thread = threading.Thread(
        target=capture_output, args=(process.stderr, result_list, stop_event)
    )
    stdout_thread.start()
    stderr_thread.start()

    try:
        process.wait()
    except Exception as e:
        result_list.append(f"Process error: {e}")

    stdout_thread.join()
    stderr_thread.join()

    if not stop_event.is_set():
        result_list.append(f"Model downloaded and cached at: {cache_dir}")
    else:
        clean_up_partial_download(model_name)

    del download_jobs[model_name]


# API Endpoints


@model.post("/abort")
async def stop_download(request: ModelRequest):
    model_name = request.model_name
    if not model_name or model_name not in download_jobs:
        raise HTTPException(
            status_code=400, detail="Valid model_name parameter is required"
        )

    job_details = download_jobs[model_name]
    job_details["stop_event"].set()

    if job_details["process"]:
        job_details["process"].terminate()

    clean_up_partial_download(model_name)

    return JSONResponse(
        content={
            "message": f"Download job for model '{model_name}' is being stopped and cleaned up."
        }
    )


@model.post("/remove")
async def remove_model(request: ModelRequest):
    folder_name = request.folder_name
    if not folder_name:
        raise HTTPException(status_code=400, detail="folder_name parameter is required")

    base_model_dir = os.path.join(
        os.path.expanduser("~"), ".cache", "huggingface", "hub", folder_name
    )

    if not os.path.exists(base_model_dir):
        raise HTTPException(
            status_code=404, detail=f"Model '{folder_name}' does not exist."
        )

    try:
        shutil.rmtree(base_model_dir)
        lock_dir = os.path.join(
            os.path.expanduser("~"),
            ".cache",
            "huggingface",
            "hub",
            ".locks",
            folder_name,
        )
        if os.path.exists(lock_dir):
            shutil.rmtree(lock_dir)

        return JSONResponse(
            content={"message": f"Model '{folder_name}' has been removed successfully."}
        )
    except Exception as e:
        raise HTTPException(
            status_code=500, detail=f"Failed to remove model '{folder_name}': {str(e)}"
        )


@model.get("/")
async def list_models():
    cache_dir = os.path.join(os.path.expanduser("~"), ".cache", "huggingface", "hub")
    cached_models = (
        [
            d
            for d in os.listdir(cache_dir)
            if os.path.isdir(os.path.join(cache_dir, d)) and not d.startswith(".")
        ]
        if os.path.exists(cache_dir)
        else []
    )

    downloading_models = {
        "models--" + model_name.replace("/", "--")
        for model_name in download_jobs.keys()
    }

    available_models = [
        model for model in cached_models if model not in downloading_models
    ]

    return JSONResponse(content={"models": sorted(available_models)})


@model.get("/download")
async def download_model(model_name: str, background_tasks: BackgroundTasks):
    if not model_name:
        raise HTTPException(status_code=400, detail="model_name parameter is required")

    if model_name in download_jobs:
        raise HTTPException(
            status_code=400,
            detail=f"Download for model '{model_name}' is already in progress.",
        )

    result_list = []
    stop_event = threading.Event()
    start_time = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    download_jobs[model_name] = {
        "result_list": result_list,
        "stop_event": stop_event,
        "process": None,
        "thread": None,
        "start_time": start_time,
    }

    download_thread = threading.Thread(
        target=download_model_cli, args=(model_name, result_list, stop_event)
    )
    download_jobs[model_name]["thread"] = download_thread
    download_thread.start()

    def generate():
        try:
            while download_thread.is_alive() or result_list:
                time.sleep(0.1)
                if result_list:
                    yield result_list.pop(0) + "\n"
                else:
                    yield " "
            if not stop_event.is_set():
                yield "Complete!\n"
            else:
                yield "Aborted!\n"
        except GeneratorExit:
            stop_event.set()
            if download_jobs[model_name]["process"]:
                download_jobs[model_name]["process"].terminate()
            download_thread.join()

    background_tasks.add_task(generate)

    return JSONResponse(
        content={"message": f"Download for model '{model_name}' started."}
    )


@model.get("/jobs")
async def list_download_jobs():
    active_jobs = [
        {"model_name": model_name, "start_time": details["start_time"]}
        for model_name, details in download_jobs.items()
    ]
    return JSONResponse(content={"active_jobs": active_jobs})


@model.get("/hf_login")
async def hf_login_get():
    command = ["hf", "whoami"]
    result = subprocess.run(
        command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )
    username = result.stdout.strip()
    logged_in = username != "Not logged in"
    return JSONResponse(
        content={"logged_in": logged_in, "username": username if logged_in else None}
    )


@model.post("/hf_login")
async def hf_login_post(request: ModelRequest):
    token = request.token
    if not token:
        raise HTTPException(status_code=400, detail="Token is required.")

    command = ["hf", "login", "--token", token]
    result = subprocess.run(
        command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )

    if result.returncode == 0:
        return JSONResponse(
            content={"logged_in": True, "message": "Logged in successfully."}
        )
    raise HTTPException(status_code=401, detail=result.stderr.strip())


@model.post("/hf_logout")
async def hf_logout():
    command = ["huggingface-cli", "logout"]
    result = subprocess.run(
        command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )

    if result.returncode == 0:
        return JSONResponse(
            content={"logged_out": True, "message": "Logged out successfully."}
        )
    raise HTTPException(status_code=401, detail=result.stderr.strip())


# Executor Monitor
@model.post("/start")
async def start_model(request: ModelRequest):
    model_path = request.model_path
    if not model_path:
        raise HTTPException(status_code=400, detail="model_path parameter is required")

    command = [
        "kuwa-executor",
        "huggingface",
        "--access_code",
        "hf/" + model_path.replace("/", "--"),
    ]
    for arg in ["model_path", "visible_gpu", "limit", "timeout"]:
        value = getattr(request, arg)
        if value is not None:
            command.extend([f"--{arg}", str(value)])

    try:
        process = subprocess.Popen(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            start_new_session=True,
        )
        stdout, stderr = process.communicate()
        if process.returncode != 0:
            raise HTTPException(
                status_code=500,
                detail=f"Failed to start model '{model_path}': {stderr.decode().strip()}",
            )
        return JSONResponse(
            content={"message": f"Model '{model_path}' has been started successfully."}
        )
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Unexpected error: {str(e)}")
