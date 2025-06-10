import re
import json
import logging
import requests
import time
from fnmatch import fnmatch
from collections.abc import Iterable
from .base_executor import BaseExecutor
from .modelfile import Modelfile
from .cache import lru_cache_with_ttl

logger = logging.getLogger(__name__)


class LLMExecutor(BaseExecutor):
    """
    The specialized class for serving LLM process.
    """

    async def serve(self, header, content):
        param = dict(content)
        history = json.loads(param.pop("input", "[]"))
        history = to_openai_chat_format(history)
        history = rectify_chat_history(history)
        modelfile = Modelfile.from_json(param.pop("modelfile", "[]"))
        modelfile.parameters["_lang"] = header.get("Accept-Language")
        skyscope_api_base_url = header.get("X-Skyscope-Api-Base-Urls")
        if skyscope_api_base_url is not None:
            skyscope_api_base_url = skyscope_api_base_url.split(";")
        modelfile.parameters["_skyscope_api_base_urls"] = skyscope_api_base_url
        for k, v in param.items():
            modelfile.parameters[f"_{k}"] = v

        logger.debug(f"History: {history}")
        logger.debug(f"Modelfile: {modelfile}")
        async for chunk in self.llm_compute(history=history, modelfile=modelfile):
            yield chunk

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        raise NotImplementedError(
            'LLM Executor should implement the "llm_compute" method.'
        )


def to_openai_chat_format(history: list[dict]):
    """
    Convert the chat history from Kuwa's format to OpenAI's format.
    """
    history = [
        {
            "role": "assistant" if i["isbot"] else "user",
            "content": i["msg"] if i["msg"] is not None else "",
        }
        for i in history
    ]
    return history


def rectify_chat_history(history: list[dict]):
    """
    Ensure the history, exclude the system message, begin with "user".
    """
    if len(history) == 0:
        return history
    i = 0
    while(history[i]["role"] not in {"user", "assistant"}):
        i += 1
    if history[i]["role"] != "user":
        history.insert(i, {"role": "user", "content": ""})
    return history

URL_REGEX = r"(https?://[^\s]+)"
def extract_last_url(chat_history: list[dict]) -> (str, list[dict]):
    """
    Find the latest URL provided by the user and trim the chat history to there.
    Note: the input is OpenAI chat format.
    """

    url = None
    begin_index = 0
    for i, record in enumerate(reversed(chat_history)):
        if record["role"] != "user":
            continue

        urls_in_msg = re.findall(URL_REGEX, record["content"])
        if len(urls_in_msg) != 0:
            url = urls_in_msg[-1]
            begin_index = len(chat_history) - i - 1
            break

    logger.debug(
        "URL: {}\nFrom message: {}".format(url, chat_history[begin_index]["content"])
    )
    trimmed_chat_history = list(chat_history[begin_index:])
    trimmed_chat_history[0]["content"] = re.sub(
        URL_REGEX, "", trimmed_chat_history[0]["content"]
    ).strip()

    return url, trimmed_chat_history


@lru_cache_with_ttl()
def get_mime_type(url):
    try:
        response = requests.head(url, allow_redirects=True)
        response.raise_for_status()
        content_type = response.headers["content-type"]
        mime_type = content_type.split(";")[0].strip().lower()
    except Exception:
        logger
        mime_type = None
    return mime_type

def extract_user_attachment(
    chat_history: list[dict], allowed_mime_type: Iterable = []
) -> list[dict]:
    """
    Extract URLs of attachments form the user messages in chat history based on the allowed content type.
    Shell-style wildcard pattern can be used in the allowed_mime_type.
    """
    assert isinstance(allowed_mime_type, Iterable)
    allowed_mime_type = set(allowed_mime_type)
    new_chat_history = []
    for record in chat_history:
        new_chat_history.append(record.copy())
        if record["role"] != "user":
            continue

        text_content = record["content"]
        pos = 0
        attachments = []
        while (url_match := re.search(URL_REGEX, text_content[pos:], flags=re.IGNORECASE)) is not None:
            url, url_begin_pos, url_end_pos = url_match.group(), url_match.start(), url_match.end()
            mime_type = get_mime_type(url)
            if mime_type is not None:
                mime_type_match = [fnmatch(mime_type, pattern) for pattern in allowed_mime_type]
            else:
                mime_type_match = [False]
            if not any(mime_type_match):
                pos += url_end_pos
                continue

            attachments.append(dict(url=url, mime_type=mime_type))
            text_content = text_content[:pos+url_begin_pos]+text_content[pos+url_end_pos:]
        new_chat_history[-1]["attachments"] = attachments
        new_chat_history[-1]["content"] = text_content

    return new_chat_history


if __name__ == "__main__":
    executor = LLMExecutor()
    executor.run()
