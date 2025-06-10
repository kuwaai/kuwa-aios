import asyncio
import logging
import re
import functools
from typing import (
    TYPE_CHECKING,
    Callable,
    Iterator,
    List,
    Optional,
    Sequence,
    Set,
    Union,
)

import os
import requests
import mimetypes
from pathlib import Path
from urllib.parse import urlparse, unquote
from urllib.error import HTTPError

from langchain.docstore.document import Document
from langchain.document_loaders.base import BaseLoader
from langchain.utils.html import extract_sub_links
import aiohttp
import trafilatura
from markitdown import MarkItDown
from selenium import webdriver
from selenium.webdriver.chrome.service import Service

if TYPE_CHECKING:
    import aiohttp

logger = logging.getLogger(__name__)

async def _extractor(content: str, url: str, content_type: str) -> str:
    mime_extractor = {
        "text/html": html_extractor,
        "multipart/related": html_extractor,
        "application/xhtml+xml": html_extractor,
    }
    fallback_extractor = file_extractor

    mime_type = content_type.split(";", 1)[0]
    extractor = fallback_extractor
    if mime_type in mime_extractor:
        extractor = mime_extractor[mime_type]

    return await extractor(content, url, content_type)


async def html_extractor(content: str, url: str, content_type: str) -> str:
    """
    Asynchronous extract main text from web page.
    """
    text = ""
    try:
        config = trafilatura.settings.use_config()
        config.set("DEFAULT", "EXTRACTION_TIMEOUT", "0")

        loop = asyncio.get_event_loop()
        text = await loop.run_in_executor(
            None,
            functools.partial(
                trafilatura.extract,
                content,
                favor_precision=True,
                config=config,
            ),
        )
    except Exception:
        logger.exception("Failed to extract text.")

    return text


async def file_extractor(content: str, url: str, content_type: str) -> str:
    """
    Extract text contents from the retrieved file.
    """
    data_dir = Path("web_data")

    is_text = "text/" in content_type

    # Create local path for file storage
    url = urlparse(url)
    file_path = data_dir / Path("{}/{}".format(url.netloc.replace(".", "_"), url.path))
    if url.path == "" or url.path[-1] == "/":
        file_path = file_path / "index"

    # Correct the suffix
    guessed_suffixes = mimetypes.guess_all_extensions(content_type.split(";", 1)[0])
    if len(guessed_suffixes) > 0:
        file_path = file_path.with_suffix("").with_suffix(guessed_suffixes[0])

    # Make directory and write the file
    # Note that in most of OS, file operation is synchronous.
    file_path.parent.mkdir(parents=True, exist_ok=True)
    with open(file_path, "wb") as f:
        f.write(content.encode("utf-8") if is_text else content)
        f.flush()

    # Asynchronous extract text from fetched files.
    text = ""
    try:
        loop = asyncio.get_event_loop()
        converter = MarkItDown(enable_plugins=False)  # Set to True to enable plugins
        result = await loop.run_in_executor(None, converter.convert, str(file_path))
        text = result.text_content
    except Exception:
        logger.exception("Failed to extract text.")

    return text


def _metadata_extractor(raw_html: str, url: str, content_type: str) -> dict:
    """Extract metadata from raw html using BeautifulSoup."""
    metadata = {"source": url, "content-type": content_type}

    if "text/" not in content_type:
        metadata["filename"] = unquote(os.path.basename(url))
    else:
        try:
            from bs4 import BeautifulSoup
        except ImportError:
            logger.warning(
                "The bs4 package is required for default metadata extraction. "
                "Please install it with `pip install bs4`."
            )
            return metadata
        soup = BeautifulSoup(raw_html, "html.parser")
        if title := soup.find("title"):
            metadata["title"] = title.get_text()
        if description := soup.find("meta", attrs={"name": "description"}):
            metadata["description"] = description.get("content", None)
        if html := soup.find("html"):
            metadata["language"] = html.get("lang", None)
    return metadata


class RecursiveUrlMultimediaLoader(BaseLoader):
    """
    Load all child links from a URL page with
    exposed content-type information to the extractor.
    """

    def __init__(
        self,
        url: str,
        max_depth: Optional[int] = 2,
        use_async: Optional[bool] = None,
        extractor: Optional[Callable[[str, str, str], str]] = None,
        metadata_extractor: Optional[Callable[[str, str, str], str]] = None,
        exclude_dirs: Optional[Sequence[str]] = (),
        timeout: Optional[int] = 10,
        prevent_outside: Optional[bool] = True,
        link_regex: Union[str, re.Pattern, None] = None,
        headers: Optional[dict] = None,
        check_response_status: bool = True,
        cache_proxy_url: bool = None,
        forge_user_agent: str = None,
    ) -> None:
        """Initialize with URL to crawl and any subdirectories to exclude.
        Args:
            url: The URL to crawl.
            max_depth: The max depth of the recursive loading.
            use_async: Whether to use asynchronous loading.
                If True, this function will not be lazy, but it will still work in the
                expected way, just not lazy.
            extractor: A function to extract document contents from raw html.
                When extract function returns an empty string, the document is
                ignored.
            metadata_extractor: A function to extract metadata from raw html and the
                source url (args in that order). Default extractor will attempt
                to use BeautifulSoup4 to extract the title, description and language
                of the page.
            exclude_dirs: A list of subdirectories to exclude.
            timeout: The timeout for the requests, in the unit of seconds. If None then
                connection will not timeout.
            prevent_outside: If True, prevent loading from urls which are not children
                of the root url.
            link_regex: Regex for extracting sub-links from the raw html of a web page.
            check_response_status: If True, check HTTP response status and skip
                URLs with error responses (400-599).
        """

        self.url = url
        self.max_depth = max_depth if max_depth is not None else 2
        self.use_async = use_async if use_async is not None else False
        self.extractor = extractor if extractor is not None else _extractor
        self.metadata_extractor = (
            metadata_extractor
            if metadata_extractor is not None
            else _metadata_extractor
        )
        self.exclude_dirs = exclude_dirs if exclude_dirs is not None else ()

        if any(url.startswith(exclude_dir) for exclude_dir in self.exclude_dirs):
            raise ValueError(
                f"Base url is included in exclude_dirs. Received base_url: {url} and "
                f"exclude_dirs: {self.exclude_dirs}"
            )

        self.timeout = timeout
        self.prevent_outside = prevent_outside if prevent_outside is not None else True
        self.link_regex = link_regex
        self._lock = asyncio.Lock() if self.use_async else None
        if forge_user_agent is not None:
            headers = {} if headers is None else headers
            headers["User-Agent"] = forge_user_agent
            logger.debug(f"User-Agent: {forge_user_agent}")
        self.headers = headers
        self.check_response_status = check_response_status
        self.cache_proxy_url = cache_proxy_url

    def _get_child_links_recursive(
        self, url: str, visited: Set[str], *, depth: int = 0
    ) -> Iterator[Document]:
        """Recursively get all child links starting with the path of the input URL.

        Args:
            url: The URL to crawl.
            visited: A set of visited URLs.
            depth: Current depth of recursion. Stop when depth >= max_depth.
        """

        if depth >= self.max_depth:
            return

        # Get all links that can be accessed from the current URL
        visited.add(url)
        try:
            proxies = (
                {"http": self.cache_proxy_url, "https": self.cache_proxy_url}
                if self.cache_proxy_url is not None
                else None
            )
            response = requests.get(
                url, timeout=self.timeout, headers=self.headers, proxies=proxies
            )
            content_type = response.headers.get("content-type")
            if "text/" in content_type:
                raw_content = response.text
            else:
                raw_content = response.content
            if (
                self.check_response_status
                and 400 <= response.status_code <= 599
                and response.status_code != 403
            ):
                raise HTTPError(url, response.status_code, response.reason, None, None)
        except (HTTPError, Exception) as e:
            logger.warning(
                f"Unable to load from {url}. Received error {e} of type "
                f"{e.__class__.__name__}"
            )
            raise
        content = self.extractor(raw_content, url, content_type)

        if content:
            yield Document(
                page_content=content,
                metadata=self.metadata_extractor(raw_content, url, content_type),
            )

        if "text/" in content_type:
            # Store the visited links and recursively visit the children
            sub_links = extract_sub_links(
                raw_content,
                url,
                base_url=self.url,
                pattern=self.link_regex,
                prevent_outside=self.prevent_outside,
                exclude_prefixes=self.exclude_dirs,
            )
            for link in sub_links:
                # Check all unvisited links
                if link not in visited:
                    yield from self._get_child_links_recursive(
                        link, visited, depth=depth + 1
                    )

    async def _async_get_child_links_recursive(
        self,
        url: str,
        visited: Set[str],
        *,
        session: Optional[aiohttp.ClientSession] = None,
        depth: int = 0,
    ) -> List[Document]:
        """Recursively get all child links starting with the path of the input URL.

        Args:
            url: The URL to crawl.
            visited: A set of visited URLs.
            depth: To reach the current url, how many pages have been visited.
        """
        try:
            import aiohttp
        except ImportError:
            raise ImportError(
                "The aiohttp package is required for the RecursiveUrlLoader. "
                "Please install it with `pip install aiohttp`."
            )
        if depth >= self.max_depth:
            return []

        # Disable SSL verification because websites may have invalid SSL certificates,
        # but won't cause any security issues for us.
        close_session = session is None
        session = (
            session
            if session is not None
            else aiohttp.ClientSession(
                connector=aiohttp.TCPConnector(ssl=False),
                timeout=aiohttp.ClientTimeout(total=self.timeout),
                headers=self.headers,
                trust_env=True,
            )
        )
        async with self._lock:  # type: ignore
            visited.add(url)
        raw_content = ""
        try:
            async with session.get(url, proxy=self.cache_proxy_url) as response:
                content_type = response.headers.get("content-type")
                if "text/" in content_type:
                    raw_content = await response.text(errors="ignore")
                else:
                    raw_content = await response.read()
                if (
                    self.check_response_status
                    and 400 <= response.status <= 599
                    and response.status != 403
                ):
                    raise HTTPError(url, response.status, response.reason, None, None)
        except (aiohttp.client_exceptions.InvalidURL, HTTPError, Exception) as e:
            logger.warning(
                f"Unable to load {url}. Received error {e} of type "
                f"{e.__class__.__name__}"
            )
            logger.debug(f"Content: {raw_content}")
            if close_session:
                await session.close()
            raise
        results = []
        content = await self.extractor(raw_content, url, content_type)
        if not content:
            content = ""

        # Ugly hot patch to fetch the content of Client-Side rendering page
        csr_threshold = 100
        if "text/" in content_type and len(content) < csr_threshold:
            raw_content = await self.fetch_page_by_browser(url)
            content = await self.extractor(raw_content, url, content_type)

        if content:
            results.append(
                Document(
                    page_content=content,
                    metadata=self.metadata_extractor(raw_content, url, content_type),
                )
            )
        if "text/" in content_type and depth < self.max_depth - 1:
            sub_links = extract_sub_links(
                raw_content,
                url,
                base_url=self.url,
                pattern=self.link_regex,
                prevent_outside=self.prevent_outside,
                exclude_prefixes=self.exclude_dirs,
            )

            # Recursively call the function to get the children of the children
            sub_tasks = []
            async with self._lock:  # type: ignore
                to_visit = set(sub_links).difference(visited)
                for link in to_visit:
                    sub_tasks.append(
                        self._async_get_child_links_recursive(
                            link, visited, session=session, depth=depth + 1
                        )
                    )
            next_results = await asyncio.gather(*sub_tasks)
            for sub_result in next_results:
                if isinstance(sub_result, Exception) or sub_result is None:
                    # We don't want to stop the whole process, so just ignore it
                    # Not standard html format or invalid url or 404 may cause this.
                    continue
                # locking not fully working, temporary hack to ensure deduplication
                results += [r for r in sub_result if r not in results]
        if close_session:
            await session.close()
        return results

    async def fetch_page_by_browser(self, url: str):
        service = Service()

        options = webdriver.ChromeOptions()
        options.add_argument("--headless")
        options.add_argument("--disable-gpu")
        options.add_argument("--disable-extensions")
        options.add_argument("--disable-infobars")
        options.add_argument("--start-maximized")
        options.add_argument("--disable-notifications")
        options.add_argument("--no-sandbox")
        options.add_argument("--disable-dev-shm-usage")

        loop = asyncio.get_running_loop()
        browser = webdriver.Chrome(service=service, options=options)
        await loop.run_in_executor(None, browser.get, url)
        html = browser.page_source
        browser.quit()
        return html

    def lazy_load(self) -> Iterator[Document]:
        """Lazy load web pages.
        When use_async is True, this function will not be lazy,
        but it will still work in the expected way, just not lazy."""
        visited: Set[str] = set()
        if self.use_async:
            results = asyncio.run(
                self._async_get_child_links_recursive(self.url, visited)
            )
            return iter(results or [])
        else:
            return self._get_child_links_recursive(self.url, visited)

    def load(self) -> List[Document]:
        """Load web pages."""
        return list(self.lazy_load())

    async def async_load(self) -> List[Document]:
        visited: Set[str] = set()
        results = await self._async_get_child_links_recursive(self.url, visited)
        return results
