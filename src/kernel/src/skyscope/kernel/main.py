# -#- coding: UTF-8 -*-
import time, re, os, click, requests, sys
import logging.config
import argparse
from datetime import datetime
from flask import Flask
from flask_sse import ServerSentEventsBlueprint
from apscheduler.schedulers.background import BackgroundScheduler

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from .variable import *
from .functions import load_records, load_variable_from_file, save_variable_to_file
from .logger import KernelLoggerFactory
from .safety_middleware import update_safety_guard
from .routes.executor import executor
from .routes.model import model
from .routes.chat import chat

logger = logging.getLogger(__name__)

SKYSCOPE_KERNEL_API_VERSION="v1.0"

def main():
    parser = argparse.ArgumentParser(prog='Skyscope Kernel', description='Skyscope Kernel')
    parser.add_argument('--log_level', type=str, default="INFO", help="Log level")
    parser.add_argument('--port', type=int, default=9000, help="The port to serve")
    parser.add_argument('--host', type=str, default="0.0.0.0", help="The host IP address to serve")
    args = parser.parse_args()
    logging.config.dictConfig(KernelLoggerFactory(level=args.log_level).get_config())
    
    # Load savefile
    if os.path.exists(record_file):
        load_records(load_variable_from_file(record_file))

    # Schedule background job to update the Safety Guard
    logging.getLogger('apscheduler.executors.default').setLevel(logging.WARNING)
    scheduler = BackgroundScheduler()
    scheduler.add_job(
        func=update_safety_guard,
        trigger="interval",
        seconds=safety_guard_update_interval_sec,
        next_run_time=datetime.now()
    )
    scheduler.start()

    # Init Flask Apps
    app = Flask(__name__)
    app.config["REDIS_URL"] = "redis://localhost:6379/0"
    sse = ServerSentEventsBlueprint('sse', __name__)
    app.register_blueprint(sse, url_prefix='/')
    app.register_blueprint(executor, url_prefix=f'/{SKYSCOPE_KERNEL_API_VERSION}/worker')
    app.register_blueprint(chat, url_prefix=f'/{SKYSCOPE_KERNEL_API_VERSION}/chat')
    app.register_blueprint(model, url_prefix=f'/{SKYSCOPE_KERNEL_API_VERSION}/model')
    logger.info("Route list:\n{}\n".format('\n'.join([str(i) for i in app.url_map.iter_rules()])))
    logger.info("Server started")
    app.run(port=args.port, host=args.host, threaded=True)
    for model_name in list(download_jobs.keys()):
        job_details = download_jobs[model_name]
        job_details['stop_event'].set()
        if job_details['process']:
            job_details['process'].terminate()
        job_details['thread'].join()
    #Stopped, saving to file
    save_variable_to_file(record_file, data)

if __name__ == '__main__':
    main()
