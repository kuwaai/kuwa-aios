import os

download_jobs = {}
data = {}
record_file = "records.pickle"

# Set following environment variable before importing the Safety Guard client
os.environ['SAFETY_GUARD_MANAGER_URL'] = 'http://localhost:8000'
os.environ['SAFETY_GUARD_DETECTOR_URL'] = 'grpc://localhost:50051'
safety_guard_update_interval_sec = 30
