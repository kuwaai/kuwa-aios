import logging
import asyncio
import traceback
import trafilatura
from typing import List, AsyncIterator
from pathlib import Path
from langchain.docstore.document import Document
from langchain_community.document_loaders.text import TextLoader
from magika import Magika
from markitdown import MarkItDown

logger = logging.getLogger(__name__)
magika = Magika()


class PrefixDict(dict):
    def __missing__(self, key):
        """
        Longest prefix match the key if not exact match.
        """
        prefix_dict = {k: v for k, v in self.items() if key.startswith(k)}
        match len(prefix_dict):
            case 0:
                return None
            case 1:
                return list(prefix_dict.values())[0]
            case _:
                return prefix_dict[max(prefix_dict.keys(), key=len)]


class FileTextLoader(TextLoader):
    """Extract text from files.

    Args:
        file_path: Path to the file to load.
    """

    def __init__(
        self, file_path: str, encoding="utf-8", autodetect_encoding: bool = False
    ):
        self.file_path = file_path
        self.encoding = encoding
        self.autodetect_encoding = autodetect_encoding

    def lazy_load(self) -> List[Document]:
        """Load text from file path."""

        file_magic_result = magika.identify_path(Path(self.file_path))
        file_mime_type = file_magic_result.output.mime_type.split(";", 1)[0]

        mime_extractors = PrefixDict(
            **{
                "text/": self.load_plain_text,
                "application/x-yaml": self.load_plain_text,
                "application/yaml": self.load_plain_text,
                "application/json": self.load_plain_text,
                "text/html": self.load_html,
                "multipart/related": self.load_html,
                "application/xhtml+xml": self.load_html,
            }
        )
        fallback_extractor = self.load_doc

        extractor = mime_extractors[file_mime_type]
        if extractor is None:
            extractor = fallback_extractor

        logger.debug(f"{self.file_path}: {file_mime_type}, {extractor.__name__}")

        docs = []
        try:
            docs = extractor()
        except Exception as e:
            logger.warning(
                f"Error loading {self.file_path}: {type(e).__name__}: {e.args[0]}. Skipped."
            )
            logger.debug(traceback.format_exc())
        finally:
            return docs

    async def alazy_load(
        self,
    ) -> AsyncIterator[Document]:
        loop = asyncio.get_event_loop()
        docs = await loop.run_in_executor(None, self.lazy_load)
        for doc in docs:
            yield doc

    def load_plain_text(self) -> List[Document]:
        """Load text from plaintext file."""
        content = ""
        with open(self.file_path, encoding=self.encoding, errors="ignore") as f:
            content = f.read()

        metadata = {"source": self.file_path}
        return [Document(page_content=content, metadata=metadata)]

    def load_doc(self) -> List[Document]:
        """Load text from document file."""
        converter = MarkItDown(enable_plugins=False)  # Set to True to enable plugins
        result = converter.convert(self.file_path)
        text = result.text_content

        metadata = {"source": self.file_path}
        return [Document(page_content=text, metadata=metadata)]

    def load_html(self) -> List[Document]:
        """Load text from HTML file."""
        text = ""
        config = trafilatura.settings.use_config()
        config.set("DEFAULT", "EXTRACTION_TIMEOUT", "0")

        content = ""
        with open(self.file_path, encoding=self.encoding, errors="ignore") as f:
            content = f.read()
        text = trafilatura.extract(
            content,
            favor_precision=True,
            config=config,
        )

        metadata = {"source": self.file_path}
        return [Document(page_content=text, metadata=metadata)]
