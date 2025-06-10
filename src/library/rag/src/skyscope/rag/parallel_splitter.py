import os
import asyncio
import itertools
from concurrent.futures import ProcessPoolExecutor
from typing import Callable, Iterable
from langchain.text_splitter import RecursiveCharacterTextSplitter
#from langchain.text_splitter import SentenceTransformersTokenTextSplitter
from langchain.docstore.document import Document


async def async_pool_map(fn: Callable, data: Iterable, max_workers=None) -> Iterable:
    """
    Emulate the parallel map operation asynchronously.
    Equivalent to:
    thread_pool = multiprocessing.Pool(max_workers)
    return thread_pool.map(fn, data)

    Parameters:
    fn: Function apply to each element.
    data: The sequence of element to operate.
    max_workers: The maximum number of workers.
    """

    loop = asyncio.get_event_loop()
    result = []
    with ProcessPoolExecutor(max_workers=max_workers) as executor:
        futures = [loop.run_in_executor(executor, fn, x) for x in data]
        result = await asyncio.gather(*futures)
    return result


class SplitDocumentJob(object):
    """
    Encapsulate the context of document splitting job.
    """

    def __init__(self, chunk_size=128, chunk_overlap=16):
        self.chunk_size = chunk_size
        self.chunk_overlap = chunk_overlap

    def __call__(self, doc):
        # splitter = SentenceTransformersTokenTextSplitter(chunk_overlap=self.chunk_overlap)
        splitter = RecursiveCharacterTextSplitter(
            # Set a really small chunk size, just to show.
            chunk_size=self.chunk_size,
            chunk_overlap=self.chunk_overlap,
            length_function=len,
            add_start_index=True,
        )
        return splitter.split_documents([doc])


class ParallelSplitter:
    """
    Split the documents.
    Since the tokenizer only uses CPU, we speedup the process with multi processing.
    """

    def __init__(self, chunk_size: int = 128, chunk_overlap: int = 16):
        self.chunk_overlap = chunk_overlap
        self.chunk_size = chunk_size

    async def split(self, docs: [Document]):
        # Disable original tokenizer since they may cause deadlock
        # Ref: https://stackoverflow.com/a/67254879
        os.environ["TOKENIZERS_PARALLELISM"] = "false"

        chunked_docs = []
        split_job = SplitDocumentJob(self.chunk_size, self.chunk_overlap)
        chunked_docs = await async_pool_map(split_job, docs, max_workers=os.cpu_count())

        # chunked_docs = map(split_job, docs)
        chunks = list(itertools.chain(*chunked_docs))  # Flatten

        return chunks
