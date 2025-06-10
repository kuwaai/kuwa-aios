#!/usr/local/bin/python

import os
import platform
import subprocess
import requests
from urllib.parse import urljoin
from importlib.metadata import version

def get_nvidia_smi_version():
  """
  Executes "nvidia-smi --version" and returns the stdout.
  Handles the case where "nvidia-smi" does not exist.

  Returns:
    str: The stdout of the command, or an empty string if the command fails.
  """
  try:
    # Execute the command and capture stdout
    result = subprocess.run(["nvidia-smi", "--version"], capture_output=True, text=True)
    return result.stdout.strip()
  except FileNotFoundError:
    # If the command is not found, return an empty string
    return None

def get_sys_info():
    nvidia_version = get_nvidia_smi_version()
    if nvidia_version is not None:
        nvidia_version = ["- nvidia-smi"] + [ f"  - {line}" for line in nvidia_version.split('\n') ]
    else:
        nvidia_version = []
    result = [
            "**System information**",
            f"- Platform: {platform.platform()}",
        ] + \
        nvidia_version + \
        [
            f"- Skyscope-executor version: {version('skyscope-executor')}"
        ]
    return result
    
def get_torch_info():
    try:
        import torch
    except ImportError:
        return ["- Torch is not installed."]
    result = [
        "**Torch information**",
        f"- Pytorch version: {torch.__version__}",
        f"- Is CUDA available?: {torch.cuda.is_available()}",
        ]
    if torch.cuda.is_available():
        device = torch.device('cuda')
        gpu_count = torch.cuda.device_count()
        result += [
            f"- Linked CUDA version: {torch.version.cuda}",
            f"- Number of CUDA devices: {gpu_count}",
        ]
        for i in range(gpu_count):
            result += [f"  {i+1}. {torch.cuda.get_device_name(i)}"]
        result += [
            f"- A torch tensor: {torch.rand(5).to(device)}",
        ]
    return result

def get_env_var():
    result = ["**Environment Variables**"]
    env = dict(os.environ)
    env["SKYSCOPE_API_KEY"] = "******"
    env = dict(sorted(env.items()))
    result.append("```shell")
    result.extend([f'{k}={v}' for k,v in env.items()])
    result.append("```")

    return result

def get_kernel_info():
    result = ["**Registered Executors**"]
    kernel_debug_url = urljoin(os.environ.get("SKYSCOPE_KERNEL_BASE_URL"), "./worker/debug")

    try:
        resp = requests.get(kernel_debug_url, headers={"Accept":"application/json"})
        resp.raise_for_status()
        registered_executors = resp.json()
        result.extend([
            "| Access Code (ID) | Endpoint | Status | Job History ID | Job User ID |",
            "|---|---|---|---|---|",
        ])
        for access_code, group in registered_executors.items():
            result.extend([
                "|{access_code}|{endpoint}|{status}|{job_history_id}|{job_user_id}|".format(
                    access_code=access_code,
                    **executor,
                )
                for executor in group
            ])
    except Exception as e:
        result.append(f"Failed to get registered executors form kernel: {repr(e)}")
    finally:
        return result


if __name__ == "__main__":
    sysinfo = [get_sys_info(), get_torch_info(), get_env_var(), get_kernel_info()]
    print('\n\n'.join(['\n'.join(section) for section in sysinfo]))