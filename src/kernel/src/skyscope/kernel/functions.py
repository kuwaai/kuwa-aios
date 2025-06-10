import os, logging, re, gzip, pickle, requests, aiohttp, asyncio
from urllib.parse import urlparse
from flask import make_response
from json import dumps
from .variable import *

logger = logging.getLogger(__name__)

def save_variable_to_file(filename, data):
    with gzip.open(filename, 'wb') as file:
        pickle.dump(data, file, protocol=pickle.HIGHEST_PROTOCOL)
    logger.info(f"Records saved\n{data}")

def load_variable_from_file(filename):
    with gzip.open(filename, 'rb') as file:
        return pickle.load(file)

def endpoint_formatter(endpoint):
    return endpoint[:-1] if endpoint.endswith("/") else endpoint
        
def get_base_url(url):
    parsed_url = urlparse(url)
    base_url = f"{parsed_url.scheme}://{parsed_url.netloc}"
    return base_url
    
# Define an asynchronous health check function
async def async_health_check(url, session):
    try:
        async with session.get(url, timeout=20) as resp:
            return resp.status == 204
    except aiohttp.ClientConnectionError:
        return False
    except asyncio.exceptions.TimeoutError:
        return False

# Asynchronously checks health status of all endpoints and maps results
async def check_all_health(endpoints):
    async with aiohttp.ClientSession() as session:
        tasks = {endpoint: async_health_check(get_base_url(endpoint) + "/health", session) for endpoint in endpoints}
        results = await asyncio.gather(*tasks.values())
        return dict(zip(tasks.keys(), results))

# Refactored load_records function
def load_records(var, keep_state=False):
    logger.info(f"Loading records, Here's before\n{data}")
    logger.info(f"Here's new records\n{var}")

    # Collect all endpoints to check health
    endpoints_to_check = [k[0] for i, o in var.items() for k in o]

    # Run asynchronous health check for all endpoints
    health_results = asyncio.run(check_all_health(endpoints_to_check))

    for i, o in var.items():
        data[i] = []
        for k in o:
            formatted_endpoint = endpoint_formatter(k[0])

            # Check if this endpoint passed the health check
            if health_results.get(k[0], False):
                data[i].append(k if keep_state else [formatted_endpoint, "READY", -1, -1])
            else:
                logger.info(f"Health check failed for {i} at {k[0]}, removed")

        # Remove empty entries
        if len(data[i]) == 0:
            del data[i]

    logger.info(f"Records loaded, Here's After\n{data}")
