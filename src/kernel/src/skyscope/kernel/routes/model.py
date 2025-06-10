import os
import threading
import time
import subprocess
import shutil
from datetime import datetime
from flask import Blueprint, request, jsonify, Response, stream_with_context

model = Blueprint('model', __name__)
download_jobs = {}

def ensure_cache_directory():
    cache_dir = os.path.join(os.path.expanduser("~"), ".cache", "huggingface", "hub")
    os.makedirs(cache_dir, exist_ok=True)
    return cache_dir

def clean_up_partial_download(model_name):
    time.sleep(1)
    base_model_dir = os.path.join(os.path.expanduser("~"), ".cache", "huggingface", "hub", "models--" + model_name.replace("/", "--"))
    try:
        shutil.rmtree(base_model_dir)
        shutil.rmtree(os.path.join(os.path.expanduser("~"), ".cache", "huggingface", "hub", ".locks", "models--" + model_name.replace("/", "--")))
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
    command = ["huggingface-cli", "download", model_name, "--cache-dir", cache_dir]
    result_list.append("Executing: " + " ".join(command))
    process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, bufsize=1)
    download_jobs[model_name]['process'] = process

    stdout_thread = threading.Thread(target=capture_output, args=(process.stdout, result_list, stop_event))
    stderr_thread = threading.Thread(target=capture_output, args=(process.stderr, result_list, stop_event))
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

@model.route("/abort", methods=["POST"])
def stop_download():
    model_name = request.json.get("model_name")
    if not model_name or model_name not in download_jobs:
        return jsonify({"error": "Valid model_name parameter is required"}), 400

    job_details = download_jobs[model_name]
    job_details['stop_event'].set()

    if job_details['process']:
        job_details['process'].terminate()

    clean_up_partial_download(model_name)

    return jsonify({"message": f"Download job for model '{model_name}' is being stopped and cleaned up."}), 200

@model.route("/remove", methods=["POST"])
def remove_model():
    folder_name = request.json.get("folder_name")
    if not folder_name:
        return jsonify({"error": "folder_name parameter is required"}), 400

    base_model_dir = os.path.join(os.path.expanduser("~"), ".cache", "huggingface", "hub",  folder_name)
    
    # Check if the model directory exists
    if not os.path.exists(base_model_dir):
        return jsonify({"error": f"Model '{folder_name}' does not exist."}), 404
    
    try:
        shutil.rmtree(base_model_dir)
        # Clean up any associated locks
        lock_dir = os.path.join(os.path.expanduser("~"), ".cache", "huggingface", "hub", ".locks", folder_name)
        if os.path.exists(lock_dir):
            shutil.rmtree(lock_dir)
        
        return jsonify({"message": f"Model '{folder_name}' has been removed successfully."}), 200
    except Exception as e:
        return jsonify({"error": f"Failed to remove model '{folder_name}': {str(e)}"}), 500

@model.route("/start", methods=["POST"])
def start_model():
    model_path = request.json.get("model_path")
    if not model_path:
        return jsonify({"error": "model_path parameter is required"}), 400

    # Base command and optional parameters
    command = ["skyscope-executor", "huggingface", "--access_code", "hf/" + model_path.replace('/','--')]
    for arg in ["model_path", "visible_gpu", "limit", "timeout"]:
        value = request.json.get(arg)
        if value is not None:
            command.extend([f"--{arg}", str(value)])

    # Attempt to start the process
    try:
        process = subprocess.Popen(
            command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True
        )
        stdout, stderr = process.communicate()
        if process.returncode != 0:
            return jsonify({"error": f"Failed to start model '{model_path}': {stderr.decode().strip()}"}), 500
        return jsonify({"message": f"Model '{model_path}' has been started successfully."}), 200
    except Exception as e:
        return jsonify({"error": f"Unexpected error: {str(e)}"}), 500

@model.route("/", methods=["GET"])
def list_models():
    cache_dir = os.path.join(os.path.expanduser("~"), ".cache", "huggingface", "hub")
    cached_models = [
        d for d in os.listdir(cache_dir) 
        if os.path.isdir(os.path.join(cache_dir, d)) and not d.startswith('.')
    ] if os.path.exists(cache_dir) else []
    
    downloading_models = {
        "models--" + model_name.replace("/", "--") 
        for model_name in download_jobs.keys()
    }
    
    available_models = [model for model in cached_models if model not in downloading_models]
    
    return jsonify(models=sorted(available_models)), 200

@model.route("/download", methods=["GET"])
def download_model():
    model_name = request.args.get("model_name")
    if not model_name:
        return jsonify({"error": "model_name parameter is required"}), 400

    if model_name in download_jobs:
        return jsonify({"error": f"Download for model '{model_name}' is already in progress."}), 400

    result_list = []
    stop_event = threading.Event()
    start_time = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    download_jobs[model_name] = {
        'result_list': result_list,
        'stop_event': stop_event,
        'process': None,
        'thread': None,
        'start_time': start_time
    }

    download_thread = threading.Thread(target=download_model_cli, args=(model_name, result_list, stop_event))
    download_jobs[model_name]['thread'] = download_thread
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
                yield 'Complete!\n'
            else:
                yield 'Aborted!\n'
        except GeneratorExit:
            stop_event.set()
            if download_jobs[model_name]['process']:
                download_jobs[model_name]['process'].terminate()
            download_thread.join()

    return Response(stream_with_context(generate()), mimetype='text/plain')

@model.route("/jobs", methods=["GET"])
def list_download_jobs():
    active_jobs = [
        {"model_name": model_name, "start_time": details['start_time']}
        for model_name, details in download_jobs.items()
    ]
    return jsonify({"active_jobs": active_jobs}), 200
    

@model.route("/hf_login", methods=["GET", "POST"])
def hf_login():
    try:
        if request.method == "GET":
            command = ["huggingface-cli", "whoami"]
            result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

            username = result.stdout.strip()
            logged_in = username != 'Not logged in'
            return jsonify({"logged_in": logged_in, "username": username if logged_in else None}), 200

        # POST method to log in
        token = request.json.get("token")
        if not token:
            return jsonify({"error": "Token is required."}), 400

        command = ["huggingface-cli", "login", "--token", token]
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

        if result.returncode == 0:
            return jsonify({"logged_in": True, "message": "Logged in successfully."}), 200
        return jsonify({"logged_in": False, "error": result.stderr.strip()}), 401

    except Exception as e:
        return jsonify({"logged_in": False, "error": str(e)}), 500

@model.route("/hf_logout", methods=["POST"])
def hf_logout():
    try:
        # Use the huggingface-cli logout command
        command = ["huggingface-cli", "logout"]
        result = subprocess.run(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )

        if result.returncode == 0:
            return jsonify({"logged_out": True, "message": "Logged out successfully."}), 200
        else:
            error_message = result.stderr.strip()
            return jsonify({"logged_out": False, "error": error_message}), 401

    except Exception as e:
        return jsonify({"logged_out": False, "error": str(e)}), 500
