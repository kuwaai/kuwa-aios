import gzip
import pickle
import logging
import asyncio
import copy
from .utils import endpoint_formatter
from .health import check_all_health

logger = logging.getLogger(__name__)


def save_processor_list(filename):
    from .scheduler import JobScheduler

    # Processors contain transient state (current_jobs) which may include
    # non-picklable asyncio objects (Events, Tasks).
    # We create clean copies for persistence.
    processors_to_save = []
    for p in JobScheduler().processors:
        p_copy = copy.copy(p)
        p_copy.current_jobs = []
        processors_to_save.append(p_copy)

    try:
        with gzip.open(filename, "wb") as file:
            pickle.dump(processors_to_save, file, protocol=pickle.HIGHEST_PROTOCOL)
        logger.info(f"Records saved to {filename}")
    except Exception as e:
        logger.error(f"Failed to save records to {filename}: {e}")


def load_processor_list_from_file(filename):
    with gzip.open(filename, "rb") as file:
        return pickle.load(file)


def load_processor_list(records, keep_state=False):
    """
    Loads records and removes processors that fail health checks.
    """
    from .scheduler import JobScheduler

    logger.info(f"Loading records. Current processors: {JobScheduler().processors}")

    try:
        # Temporarily set to check endpoints, will be refined below
        JobScheduler().processors.set_all(records)
    except (AttributeError, TypeError):
        logger.warning("Detected old or invalid record.pickle, Failed to load records")
        return None

    # Run asynchronous health check for all processors
    health_results = asyncio.run(check_all_health(records))

    # Safely iterate and remove unhealthy processors
    healthy_processors = []
    for p, healthy in zip(records, health_results):
        p.endpoint = endpoint_formatter(p.endpoint)
        if not keep_state:
            p.current_jobs = []
        # Retain processor only if the health check passes
        if healthy:
            healthy_processors.append(p)
        else:
            logger.info(f"Health check failed for {p.endpoint}. Processor removed.")

    JobScheduler().processors.set_all(healthy_processors)
    logger.info(
        f"Records loaded. Updated processors: {JobScheduler().processors.get_all()}"
    )
