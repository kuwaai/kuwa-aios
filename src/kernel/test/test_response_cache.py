import unittest
import datetime
import re
from unittest.mock import MagicMock, patch
import sys
import os

# Add src/kernel/src to sys.path
sys.path.append(os.path.join(os.path.dirname(__file__), "..", "src"))

from kuwa.kernel.response_cache import ResponseCache, CacheItem


class TestResponseCache(unittest.TestCase):
    def setUp(self):
        # Reset singleton state before each test
        ResponseCache._instance = None
        self.cache = ResponseCache(capacity=3, ttl_sec=10)

    def tearDown(self):
        # Clear the cache to prevent test pollution
        self.cache.clear()
        ResponseCache._instance = None

    def test_singleton_behavior_and_capacity_retention(self):
        # Verify that multiple instantiations return the exact same object
        cache2 = ResponseCache(capacity=100, ttl_sec=500)
        self.assertIs(self.cache, cache2)

        # Verify that capacity and ttl were NOT changed by the second instantiation
        self.assertEqual(self.cache.capacity, 3)
        self.assertEqual(self.cache.ttl, datetime.timedelta(seconds=10))

    def test_invalid_capacity_and_ttl_initialization(self):
        # Ensure that invalid values raise ValueErrors during first initialization
        ResponseCache._instance = None
        with self.assertRaises(ValueError):
            ResponseCache(capacity=0)

        ResponseCache._instance = None
        with self.assertRaises(ValueError):
            ResponseCache(capacity=-5)

        ResponseCache._instance = None
        with self.assertRaises(ValueError):
            ResponseCache(ttl_sec=0)

        ResponseCache._instance = None
        with self.assertRaises(ValueError):
            ResponseCache(ttl_sec=-10)

    def test_put_and_get_valid_urls(self):
        url = "https://www.example.com"
        value = "cached_response_data"

        self.cache.put(url, value)
        self.assertEqual(self.cache.get(url), value)
        self.assertEqual(len(self.cache), 1)
        self.assertTrue(url in self.cache)

    def test_get_non_existent_valid_url(self):
        url = "https://www.example.com"
        self.assertIsNone(self.cache.get(url))

    def test_update_existing_entry(self):
        url = "https://www.example.com"
        self.cache.put(url, "value1")
        self.cache.put(url, "value2")
        self.assertEqual(self.cache.get(url), "value2")
        self.assertEqual(len(self.cache), 1)

    def test_put_and_get_invalid_urls(self):
        # Non-URL formats should be ignored by put and return None on get
        invalid_url = "this-is-not-a-valid-url"
        self.cache.put(invalid_url, "some_data")

        self.assertIsNone(self.cache.get(invalid_url))
        self.assertNotIn(invalid_url, self.cache)
        self.assertEqual(len(self.cache), 0)

    def test_lru_eviction(self):
        url1 = "https://www.example1.com"
        url2 = "https://www.example2.com"
        url3 = "https://www.example3.com"
        url4 = "https://www.example4.com"

        self.cache.put(url1, "value1")
        self.cache.put(url2, "value2")
        self.cache.put(url3, "value3")

        self.assertEqual(len(self.cache), 3)

        # Access url1 to make it most recently used
        self.cache.get(url1)

        # Now put url4, which exceeds capacity of 3. Least recently used is url2, so url2 should be evicted!
        self.cache.put(url4, "value4")

        self.assertIn(url1, self.cache)
        self.assertNotIn(url2, self.cache)  # Evicted!
        self.assertIn(url3, self.cache)
        self.assertIn(url4, self.cache)

    @patch("kuwa.kernel.response_cache.datetime")
    def test_ttl_expiration(self, mock_datetime):
        # Use fixed current time
        now = datetime.datetime(2026, 7, 1, 12, 0, 0)
        mock_datetime.datetime.now.return_value = now
        # Also need mock timedelta and datetime class behavior so normal instantiations work
        mock_datetime.timedelta = datetime.timedelta

        url = "https://www.example.com"
        self.cache.put(url, "value")

        # Verify it is in cache initially
        self.assertEqual(self.cache.get(url), "value")

        # Fast-forward time past TTL (ttl_sec = 10)
        mock_datetime.datetime.now.return_value = now + datetime.timedelta(seconds=11)

        # Get should now return None and remove the item
        self.assertIsNone(self.cache.get(url))
        self.assertEqual(len(self.cache), 0)

    def test_clear(self):
        self.cache.put("https://www.example.com", "value")
        self.assertEqual(len(self.cache), 1)

        self.cache.clear()
        self.assertEqual(len(self.cache), 0)


if __name__ == "__main__":
    unittest.main()
