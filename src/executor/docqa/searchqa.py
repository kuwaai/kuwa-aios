#!/bin/python3
# -#- coding: UTF-8 -*-

import os
import sys
import logging
import asyncio
import functools
import itertools
import requests
import i18n
from urllib.parse import quote_plus
from bs4 import BeautifulSoup

sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from skyscope.executor import LLMExecutor, Modelfile
from skyscope.executor.modelfile import ParameterDict
from skyscope.rag.crawler import Crawler

from docqa import DocQaExecutor, NoUrlException

logger = logging.getLogger(__name__)


async def google_search(query, api_key, cse_id):
    endpoint = "https://customsearch.googleapis.com/customsearch/v1"
    params = {"key": api_key, "cx": cse_id, "q": query}
    urls = []

    try:
        loop = asyncio.get_running_loop()
        resp = await loop.run_in_executor(
            None, functools.partial(requests.get, endpoint, params=params)
        )
        logger.debug(f"Google Search response ({resp.status_code}):\n{resp.content}")

        if not resp.ok or "error" in resp.json():
            raise RuntimeError(
                "Error occurs while getting URLs form Google searching API"
            )
        resp = resp.json()

        urls = [item["link"] for item in resp["items"]]
        # titles = [item['title'] for item in resp['items']]
        # urls = list(zip(urls, titles))

    except Exception:
        logger.exception("Error occurs while getting URLs form Google searching API")

    finally:
        return urls


async def extract_links(url, user_agent=None):
    crawler = Crawler(max_depth=1, user_agent=user_agent, clean=False)
    docs = await crawler.fetch_documents(url)
    if len(docs) == 0:
        raise RuntimeError(f'Error occurs while requesting "{url}"')

    content = docs[0].page_content
    mime_type = docs[0].metadata["content-type"].split(";", 1)[0]

    urls = []
    match mime_type.lower():
        case "text/html":
            soup = BeautifulSoup(content, "html.parser")
            urls = [
                link.get("href")
                for link in soup.findAll("a")
                if link.get("href") is not None
            ]
        case _:
            raise NotImplementedError(
                f"Extract links from type '{mime_type}' is not implemented."
            )

    return urls


def deduplicate_ordered(input_list):
    """Deduplicates elements in a list while preserving their relative order.

    Args:
        input_list: The list to deduplicate.

    Returns:
        A new list with duplicate elements removed, preserving order.
    """
    seen = set()
    result = []
    for item in input_list:
        if item in seen:
            continue
        seen.add(item)
        result.append(item)
    return result


class SearchQaExecutor(LLMExecutor):
    doc_qa: DocQaExecutor

    def __init__(self):
        self.doc_qa = DocQaExecutor()
        super().__init__()

    def extend_arguments(self, parser):
        self.doc_qa.extend_arguments(parser)

        search_group = parser.add_argument_group("Search Engine Options")
        search_group.add_argument(
            "--google_api_key", default=None, help="The API key of Google API."
        )
        search_group.add_argument(
            "--google_cse_id",
            default=None,
            help="The ID of Google Custom Search Engine.",
        )
        search_group.add_argument(
            "--advanced_search_params",
            default="-site:youtube.com -site:facebook.com -site:instagram.com -site:twitter.com -site:threads.net -site:play.google.com -site:apps.apple.com -site:www.messenger.com",
            help="Advanced search parameters",
        )
        search_group.add_argument(
            "--num_url",
            default=3,
            type=int,
            help="Search results reference before RAG.",
        )
        search_group.add_argument(
            "--num_skip_url",
            default=11,
            type=int,
            help="The offset of the URLs from the searching result.",
        )
        search_group.add_argument(
            "--engine_url",
            default="https://www.google.com/search?q={{}}",
            help='The URL template of third party search engine. Use "{{}}" placeholder for user prompt.',
        )
        search_group.add_argument(
            "--no_extract_url",
            action="store_true",
            help="Whether should SearchQA extract URLs from the third party search engine.",
        )

    def setup(self):
        self.doc_qa.args = self.args
        self.doc_qa.setup()

        if self.args.visible_gpu:
            os.environ["CUDA_VISIBLE_DEVICES"] = self.args.visible_gpu

        self.google_api_key = self.args.google_api_key
        self.searching_engine_id = self.args.google_cse_id
        self.user_agent = self.args.user_agent

        loop = asyncio.get_event_loop()
        task = loop.create_task(self._app_setup())
        loop.run_until_complete(task)

    async def _app_setup(self, params: ParameterDict = ParameterDict()):
        await self.doc_qa._app_setup(params=params)

        search_params = params["search_"]

        self.advanced_search_params = search_params.get(
            "advanced_params", self.args.advanced_search_params
        )
        self.num_url = search_params.get("num_url", self.args.num_url)
        self.num_skip_url = search_params.get("num_skip_url", self.args.num_skip_url)
        self.engine_url = search_params.get("engine_url", self.args.engine_url)
        self.no_extract_url = search_params.get(
            "no_extract_url", self.args.no_extract_url
        )

    def generate_url_from_query(self, template, query):
        if self.engine_url is None:
            return None
        query = quote_plus(query)
        url = template.replace("{{}}", query)
        return url

    async def is_url_reachable(self, url: str, timeout=5) -> bool:
        loop = asyncio.get_running_loop()
        resp = None
        try:
            resp = await loop.run_in_executor(
                None,
                functools.partial(
                    requests.get,
                    url,
                    timeout=timeout,
                    headers={}
                    if self.user_agent is None
                    else {"User-Agent": self.user_agent},
                    verify=False,
                ),
            )
        except Exception as e:
            logger.debug(str(e))
        finally:
            return resp is not None and resp.ok

    async def search_url(
        self, chat_history: [dict], parsed_modelfile
    ) -> ([dict[str, str]], [str]):
        """
        Get first URL from the search result.
        """

        latest_user_record = next(
            filter(lambda x: x["role"] == "user", reversed(chat_history))
        )
        latest_user_msg = latest_user_record["content"]

        query = "{user} {params}".format(
            user=latest_user_msg,
            params=self.advanced_search_params,
        ).strip()
        logger.debug(f"Query: {query}")

        urls = []

        try:
            if self.engine_url is None:
                if self.google_api_key and self.searching_engine_id:
                    urls = await google_search(
                        query=query,
                        api_key=self.google_api_key,
                        cse_id=self.searching_engine_id,
                    )
                else:
                    raise ValueError("Empty Google API key or CSE ID.")
            else:
                urls = [
                    self.generate_url_from_query(template=self.engine_url, query=query)
                ]
                if not self.no_extract_url:
                    urls = await extract_links(url=urls[0], user_agent=self.user_agent)

            logger.debug(f"All URLs: {urls}")
            urls = urls[min(len(urls) - 1, self.num_skip_url) :]
            logger.debug(f"Filtered URLs: {urls}")
            urls = deduplicate_ordered(urls)
            logger.debug(f"Deduplicated URLs: {urls}")
            urls_reachable = await asyncio.gather(
                *[self.is_url_reachable(url) for url in urls]
            )
            logger.debug(f"URL reachability: {list(zip(urls, urls_reachable))}")
            urls = list(itertools.compress(urls, urls_reachable))
            urls = urls[: min(len(urls), self.num_url)]

        except Exception:
            logger.exception(
                "Error occurs while getting URLs form the searching engine"
            )

        finally:
            return urls, [latest_user_record]

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        await self._app_setup(params=modelfile.parameters)

        try:
            urls, history = await self.search_url(history, modelfile)

            if len(urls) == 0 and not self.doc_qa.allow_failback:
                raise NoUrlException(i18n.t("searchqa.search_unreachable"))

            self.proc = True
            response_generator = self.doc_qa.doc_qa(
                urls=urls,
                chat_history=history,
                modelfile=modelfile,
            )

            async for reply in response_generator:
                if not self.proc:
                    await response_generator.aclose()
                yield reply

        except NoUrlException:
            await asyncio.sleep(2)  # To prevent SSE error of web page.
            yield

        except Exception:
            await asyncio.sleep(2)  # To prevent SSE error of web page.
            logger.exception("Unexpected error")
            yield i18n.t("searchqa.default_exception_msg")
        finally:
            logger.info("Done")

    async def abort(self):
        if self.proc:
            self.proc = False
            await self.doc_qa.abort()
            logger.debug("aborted")
            return "Aborted"
        return "No process to abort"


if __name__ == "__main__":
    executor = SearchQaExecutor()
    executor.run()
