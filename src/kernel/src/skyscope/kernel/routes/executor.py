import logging, requests
import json, threading
import time, os, subprocess
from textwrap import dedent
from datetime import datetime
from urllib.parse import urlparse
from flask import Blueprint, request, json, redirect, url_for, jsonify, Response, stream_with_context
from ..variable import *
from ..functions import save_variable_to_file, endpoint_formatter, get_base_url, load_records
executor = Blueprint('executor', __name__)

logger = logging.getLogger(__name__)

@executor.route("/schedule", methods=["POST"])
def status():
    # This will check if any LLM that is READY, then return "READY", if every is busy, return "BUSY"
    # Parameters: name, history_id, user_id
    llm_name, history_id, user_id = request.form.get("name"), request.form.get("history_id"), request.form.get("user_id")
    if llm_name and history_id:
        if data.get(llm_name):
            for i in data[llm_name]:
                if i[1] == "READY" and i[2] == -1 and i[3] == -1:
                    i[2] = history_id
                    i[3] = user_id
                    logger.info(f"Scheduled {llm_name},{i[0]} for {history_id},{user_id}")
                    return "READY"
        else:
            logger.warning(f"No machine for {llm_name} has founded, returning NOMACHINE code")
            return "NOMACHINE"
    logger.warning(f"No READY machine for {llm_name}, returning BUSY code")
    return "BUSY"
   
@executor.route("/register", methods=["POST"])
def register():
    # For Online LLM register themself
    # Parameters: name, endpoint
    llm_name, endpoint = request.form.get("name"), request.form.get("endpoint")
    if endpoint == None or llm_name == None or endpoint_formatter(endpoint) in [j[0] for j in data.get(llm_name, [])]: return "Failed"
    data.setdefault(llm_name, []).append([endpoint_formatter(endpoint), "READY", -1, -1])
    save_variable_to_file(record_file, data)
    logger.info(f"A new {llm_name} is registered at {endpoint}")
    return "Success"

@executor.route("/unregister", methods=["POST"])
def unregister():
    # For Offline LLM to unregister themself
    # Parameters: name, endpoint
    llm_name, endpoint = request.form.get("name"), get_base_url(request.form.get("endpoint"))
    if llm_name in data:
        old = len(data[llm_name])
        data[llm_name] = [i for i in data[llm_name] if get_base_url(i[0]) != endpoint]
        if data[llm_name] == []: del data[llm_name]
        if data.get(llm_name) == None or old != len(data[llm_name]):
            save_variable_to_file(record_file, data)
            logger.info(f"{llm_name} , {endpoint} just unregistered from agent")
            return "Success"
    logger.warning(f"{llm_name} , {endpoint} failed to unregister")
    return "Failed"
    
@executor.route("/debug", methods=["GET", "POST"])
def debug():
    # This route is for debugging
    if request.method == 'POST':
        load_records(json.loads(request.form.get('data')), True)
        return redirect(url_for('executor.debug'))
    if request.headers.get("Accept") == "application/json":
        exported_data = {}
        for access_code, group in data.items():
            exported_group = []
            for executor in group:
                exported_group.append({
                    "endpoint": executor[0],
                    "status": executor[1],
                    "job_history_id": executor[2],
                    "job_user_id": executor[3]
                })
            exported_data[access_code] = exported_group
        return jsonify(exported_data)
    return dedent("""
        <form method="POST">
            <textarea name="data" rows="4" cols="50">{0}</textarea><br>
            <input type="submit" value="Submit">
        </form>
        <script>
            document.querySelector("textarea").style.height = 'auto';
            document.querySelector("textarea").style.height = (document.querySelector("textarea").scrollHeight) + 'px';
        </script>
        """).format(str(json.dumps(data, indent=2)))

@executor.route("/list", methods=["GET"])
def list_executor():
    return jsonify(list(data.keys()))

@executor.route("/shutdown", methods=["POST"])
def shutdown_executor():
    try:
        request_data = request.get_json()
        access_code = request_data['access_code']
        endpoint = request_data['endpoint']
        status = request_data['status']
        history_id = request_data['history_id']
        user_id = request_data['user_id']
        base_url = urlparse(request_data['endpoint'])
        record = find_and_pop_record(access_code, endpoint, status, int(history_id), int(user_id))
        if record:
            response = requests.get(f"{base_url.scheme}://{base_url.netloc}/shutdown", timeout=10)

            if response.status_code == 200:
                return jsonify({"status": "success", "message": "Shutdown triggered successfully"}), 200
            return jsonify({"status": "error", "message": f"Failed to trigger shutdown: {response.status_code}"}), 500
        return jsonify({"status": "error", "message": f"No records founded"}), 404
    except requests.Timeout:
        return jsonify({"status": "error", "message": "Request to shutdown timed out"}), 500
    except Exception as e:
        return jsonify({"status": "error", "message": str(e)}), 500

@executor.route("/read", methods=["GET"])
def read_executor():
    return jsonify(data), 200

def find_and_pop_record(access_code, endpoint, status, history_id, user_id, pop=False):
    """
    Find the first record in the data for the given access code and endpoint.
    If pop is True, delete the record from the data before returning it.
    """
    if access_code in data:
        for index, record in enumerate(data[access_code]):
            if record == [endpoint, status, history_id, user_id]:
                # Found the record
                if pop:
                    # Delete the record if pop is True
                    return data[access_code].pop(index)  # This deletes the record and returns it
                return record  # Just return the record without deleting
    return None  # Return None if no record was found


@executor.route("/update", methods=["POST"])
def update_executor():
    try:
        request_data = request.get_json()
        
        original_access_code = request_data['original_access_code']
        original_endpoint = request_data['original_endpoint']
        original_status = request_data['original_status']
        original_history_id = request_data['original_history_id']
        original_user_id = request_data['original_user_id']
        
        new_access_code = request_data['access_code']
        new_endpoint = request_data['endpoint']
        new_status = request_data['status']
        new_history_id = request_data['history_id']
        new_user_id = request_data['user_id']

        # Find and optionally delete the original record
        original_record = find_and_pop_record(original_access_code, original_endpoint, original_status, int(original_history_id), int(original_user_id), pop=True)

        # If record was found and deleted
        if original_record is not None:
            # If the new access code doesn't exist, create it
            if new_access_code not in data:
                data[new_access_code] = []

            # Insert the new record into the correct access code
            new_record = [new_endpoint, new_status, int(new_history_id), int(new_user_id)]
            data[new_access_code].append(new_record)

            return jsonify({"status": "success", "message": "Record updated successfully"}), 200
        else:
            return jsonify({"status": "error", "message": "Record not found"}), 404
    except Exception as e:
        return jsonify({"status": "error", "message": str(e)}), 500
