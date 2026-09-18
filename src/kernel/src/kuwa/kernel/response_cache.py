import re
import datetime
import logging
from dataclasses import dataclass
from collections import OrderedDict
from .core.metrics import CACHE_SIZE, CACHE_EVICTIONS

logger = logging.getLogger(__name__)

RESPONSE_CACHE_CAPACITY = 100
RESPONSE_CACHE_TTL_SEC = 3600


@dataclass
class CacheItem:
    value: str
    expired_at: datetime.datetime


class ResponseCache:
    """
    A global, URL-keyed, TTL-supported, LRU cache that stores arbitrary strings.
    """

    _instance = None  # Singleton instance

    def __new__(cls, capacity=RESPONSE_CACHE_CAPACITY, ttl_sec=RESPONSE_CACHE_TTL_SEC):
        """
        Singleton implementation.
        """
        if cls._instance is None:
            cls._instance = super(ResponseCache, cls).__new__(cls)
            cls._instance.initialize(
                capacity, ttl_sec
            )  # Initialize upon first instantiation
        return cls._instance

    def initialize(self, capacity, ttl_sec):
        """
        Initializes the cache instance.  This is called only once per instance.
        """
        if not isinstance(capacity, int) or capacity <= 0:
            raise ValueError("Cache capacity must be a positive integer.")

        if not isinstance(ttl_sec, int) or ttl_sec <= 0:
            raise ValueError("Cache Time-To-Live (TTL) must be a positive integer.")

        self.capacity = capacity
        self.ttl = datetime.timedelta(seconds=ttl_sec)
        self.cache = OrderedDict()
        self.url_pattern = re.compile(
            r"^(https?://)?"  # Optional scheme
            r"([a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,6})"  # domain
            r"(:[0-9]{1,5})?"  # optional port
            r"(/.*)?$",  # optional path
            re.IGNORECASE,
        )  # Simplified URL regex.  Can be expanded for more rigor.

    def is_valid_url(self, url):
        """
        Validates if the given URL is in a valid format.
        """
        return bool(self.url_pattern.match(url))

    def get(self, url):
        """
        Retrieves the value associated with the URL from the cache.
        Returns None if no valid URL is found.
        """
        if not self.is_valid_url(url):
            return None

        if url not in self.cache:
            return None

        if datetime.datetime.now() >= self.cache[url].expired_at:
            # Clear expired item
            self.cache.pop(url)
            CACHE_SIZE.set(len(self.cache))
            return None

        # Move the URL to the end to mark it as recently used
        self.cache.move_to_end(url)
        return self.cache[url].value

    def put(self, url, value):
        """
        Adds or updates the URL-value pair in the cache.
        If the cache is full, the least recently used item is evicted.
        """
        if not self.is_valid_url(url):
            # Ignore non-URL requests.
            return

        cache_item = CacheItem(
            value=value,
            expired_at=datetime.datetime.now() + self.ttl,
        )

        if url in self.cache:
            # Update the value and move the URL to the end
            self.cache[url] = cache_item
            self.cache.move_to_end(url)
        else:
            # Add the URL-value pair to the end
            self.cache[url] = cache_item

            # If the cache is full, remove the least recently used item
            if len(self.cache) > self.capacity:
                self.cache.popitem(last=False)  # Remove from the beginning (LRU)
                CACHE_EVICTIONS.inc()

        CACHE_SIZE.set(len(self.cache))

    def __len__(self):
        """
        Returns the number of items currently in the cache.
        """
        return len(self.cache)

    def __contains__(self, url):
        """
        Checks if a URL exists in the cache.
        """
        return url in self.cache

    def clear(self):
        """
        Clears all items from the cache.
        """
        self.cache.clear()
        CACHE_SIZE.set(0)
