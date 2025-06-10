import logging
import asyncio
import os
import errno
import time
import copy
import threading
from pathlib import Path
from urllib.parse import urlparse
from urllib.request import url2pathname
from functools import lru_cache
from langchain_community.document_loaders import DirectoryLoader

from .file_text_loader import FileTextLoader
from .document_store import DocumentStore
from .crawler import Crawler

logger = logging.getLogger(__name__)


def file_uri2path(url: str):
    p = urlparse(url)
    if p.scheme != "file":
        return None
    path = Path(url2pathname(p.path))
    return path


def path2file_url(path: str):
    return Path(path).resolve().as_uri()


class FrozenDict(dict):
    def __hash__(self):
        return hash(frozenset(self.items()))


# Make async function cacheable
# Ref: https://stackoverflow.com/a/46723144
class ThreadSafeCacheable:
    def __init__(self, co):
        self.co = co
        self.done = False
        self.result = None
        self.lock = threading.Lock()

    def __await__(self):
        while True:
            if self.done:
                return self.result
            if self.lock.acquire(blocking=False):
                self.result = yield from self.co.__await__()
                self.done = True
                return self.result
            else:
                yield from asyncio.sleep(0.005).__await__()


def cacheable(f):
    def wrapped(*args, **kwargs):
        r = f(*args, **kwargs)
        return ThreadSafeCacheable(r)

    return wrapped


class DocumentStoreFactory:
    """
    Manage the DocumentStore instances.
    A DocumentStore instance is identified by a set of URLs. And will be cached by TTL seconds.
    If the URL points to a VectorDB, fetch and load it.
    If the URL points to a raw document, fetch it and construct a VectorDB from it.
    """

    def __init__(
        self,
        ttl_sec=600,
        document_store: DocumentStore = DocumentStore,
    ):
        self.ttl_sec = ttl_sec
        self.document_store = document_store
        self.crawler = None

    def set_crawler(self, crawler: Crawler):
        self.crawler = crawler

    def is_pre_built_db(self, urls: frozenset):
        if len(urls) > 1:
            return False

        path = file_uri2path(list(urls)[0])
        if path is None:
            return False
        if not path.exists():
            path = Path(os.environ.get("SKYSCOPE_ROOT", ".")) / path
        if path.is_dir():
            return (path / DocumentStore.config_filename).is_file()
        else:
            return str(path).endswith(".zip")

    @lru_cache()
    @cacheable
    async def _get_document_store(self, kwargs: FrozenDict):
        if kwargs is None:
            return DocumentStore()
        else:
            return DocumentStore(**kwargs)

    @lru_cache()
    @cacheable
    async def _load_documents(self, urls: frozenset):
        jobs = []
        for url in list(urls):
            path = file_uri2path(url)
            if path is None:
                jobs.append(self.crawler.fetch_documents(url))
            else:
                if os.path.isdir(path):
                    loader = DirectoryLoader(
                        path,
                        recursive=True,
                        loader_cls=FileTextLoader,
                        use_multithreading=True,
                        show_progress=True,
                    )
                else:
                    loader = FileTextLoader(file_path=path)
                jobs.append(loader.aload())
        docs = await asyncio.gather(*jobs)
        docs = [doc for sub_docs in docs for doc in sub_docs]
        logger.info(f"Successfully loaded {len(docs)} documents.")
        return docs

    @lru_cache()
    @cacheable
    async def _construct_document_store(
        self,
        urls: frozenset,
        document_store_kwargs: FrozenDict,
        ttl_hash: int = None,
    ):
        if len(urls) == 0:
            return None

        logger.debug("Constructing document store...")
        docs = None
        document_store = None
        if self.is_pre_built_db(urls):
            db_path = file_uri2path(list(urls)[0])
            if not db_path.exists():
                raise FileNotFoundError(
                    errno.ENOENT, os.strerror(errno.ENOENT), db_path
                )
            document_store = DocumentStore.load(str(db_path))
        else:
            docs = await self._load_documents(urls)
            document_store = copy.deepcopy(
                await self._get_document_store(document_store_kwargs)
            )
            await document_store.from_documents(docs)
        logger.debug("Document store constructed")
        return document_store, docs

    async def construct_document_store(self, urls: [str], document_store_kwargs: dict):
        """
        To build a vector database by extracting information from given URLs, first
        check if a cached version is available. If a cached version exists and
        hasn't exceeded the specified time to live (ttl_sec), utilize it; otherwise,
        fetch and cache the data from the provided URLs before constructing the
        final vector database.

        Parameters:
        urls: The URLs to fetch documents.
        ttl_sec: The time to live of the cached documents in unit of seconds.

        Return:
        The object of constructed vector database. Raise exception if no document was fetched.
        """

        urls = frozenset(urls)
        document_store_kwargs = FrozenDict(document_store_kwargs)
        ttl_hash = time.time() // self.ttl_sec

        logger.debug(f"Parameter to cache: {urls}, {document_store_kwargs}, {ttl_hash}")

        document_store, docs = await self._construct_document_store(
            urls, document_store_kwargs, ttl_hash
        )
        logger.debug(f"Cache info: {self._construct_document_store.cache_info()}")
        return document_store, docs

    async def load_document_store(self, path: str):
        urls = [path2file_url(path)]
        document_store, docs = await self.construct_document_store(urls, {})
        loop = asyncio.get_running_loop()
        await loop.run_in_executor(None, document_store.load_embedding_model)
        return document_store
