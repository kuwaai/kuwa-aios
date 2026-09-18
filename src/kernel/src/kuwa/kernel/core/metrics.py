from prometheus_client import Counter, Gauge, Histogram, Summary

# Job & Scheduling Metrics
JOBS_TOTAL = Counter(
    "kuwa_kernel_jobs_total", "Total number of jobs handled.", ["status"]
)

JOBS_ACTIVE = Gauge(
    "kuwa_kernel_jobs_active", "Number of jobs currently in each state.", ["status"]
)

JOB_WAIT_TIME = Histogram(
    "kuwa_kernel_job_wait_time_seconds",
    "Time jobs spend in the Pending queue before being assigned to a processor.",
    buckets=(0.1, 0.5, 1.0, 2.0, 5.0, 10.0, 30.0, 60.0, float("inf")),
)

JOB_PROCESSING_TIME = Histogram(
    "kuwa_kernel_job_processing_time_seconds",
    "Time taken from assignment to completion.",
    buckets=(1.0, 5.0, 10.0, 30.0, 60.0, 120.0, 300.0, float("inf")),
)

SCHEDULING_ERRORS = Counter(
    "kuwa_kernel_scheduling_errors_total",
    "Number of times the scheduler failed to find a healthy processor.",
)

# Processor (Executor) Metrics
PROCESSORS_REGISTERED = Gauge(
    "kuwa_kernel_processors_registered_total",
    "Total number of registered processors per model.",
    ["model_id"],
)

PROCESSORS_IDLE = Gauge(
    "kuwa_kernel_processors_idle_total",
    "Number of processors currently ready to accept new jobs.",
    ["model_id"],
)

PROCESSOR_HEALTH_CHECKS = Gauge(
    "kuwa_kernel_processor_health_checks",
    "Number of processors currently in each health state.",
    ["status"],
)

PROCESSORS_REMOVED = Counter(
    "kuwa_kernel_processors_removed_total",
    "Number of processors removed from the pool.",
    ["reason"],
)

# API Performance Metrics
HTTP_REQUESTS_TOTAL = Counter(
    "kuwa_kernel_http_requests_total",
    "Total HTTP requests handled by the kernel.",
    ["method", "path", "status_code"],
)

HTTP_REQUEST_DURATION = Histogram(
    "kuwa_kernel_http_request_duration_seconds",
    "HTTP request latency distribution.",
    ["method", "path"],
    buckets=(0.01, 0.05, 0.1, 0.5, 1.0, 2.5, 5.0, 10.0, float("inf")),
)

CLIENT_DISCONNECTS = Counter(
    "kuwa_kernel_client_disconnects_total",
    "Number of times a client disconnected before a response was finished.",
)

# Cache Metrics
CACHE_REQUESTS = Counter(
    "kuwa_kernel_cache_requests_total", "Total cache lookups.", ["result"]
)

CACHE_SIZE = Gauge(
    "kuwa_kernel_cache_size", "Current number of items in the LRU cache."
)

CACHE_EVICTIONS = Counter(
    "kuwa_kernel_cache_evictions_total",
    "Number of items removed due to capacity limits.",
)

# Safety Guard Metrics
SAFETY_CHECKS = Counter(
    "kuwa_kernel_safety_checks_total",
    "Count of inputs/outputs passing through the safety guard.",
    ["model_id", "result"],
)

SAFETY_LATENCY = Histogram(
    "kuwa_kernel_safety_latency_seconds",
    "Overhead added by the safety guard processing.",
    ["model_id"],
    buckets=(0.01, 0.05, 0.1, 0.5, 1.0, 2.0, float("inf")),
)

# System Metrics
DOWNLOAD_JOBS_ACTIVE = Gauge(
    "kuwa_kernel_download_jobs_active",
    "Number of model download processes currently running.",
)
