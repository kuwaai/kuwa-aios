import logging
import asyncio
import json
import zipfile
import tempfile
import hashlib
from pathlib import Path
from langchain.docstore.document import Document
from langchain_community.vectorstores import FAISS
#from langchain_community.retrievers import BM25Retriever
from langchain.schema.vectorstore import VectorStoreRetriever
#from langchain.retrievers.ensemble import EnsembleRetriever

from .parallel_splitter import ParallelSplitter
from .embedding_model_manager import get_embedding_model_manager

logger = logging.getLogger(__name__)


class DocumentStore:
    config_filename = "config.json"

    def __init__(
        self,
        embedding_model="intfloat/multilingual-e5-small",
        chunk_size=512,
        chunk_overlap=128,
    ):
        """
        Initialize the document store.
        embedding_model: The sentence-transformers pre-trained model
          - 'paraphrase-multilingual-mpnet-base-v2' // Size: ~1.11GB
          - 'paraphrase-multilingual-MiniLM-L12-v2' // Size: ~471MB
          - 'infgrad/stella-base-zh' // Chinese embedding model // Size: ~210MB
        """

        # self.corase_k = 2

        self.splitter = ParallelSplitter(
            chunk_size=chunk_size, chunk_overlap=chunk_overlap
        )
        self.embedding_model = embedding_model
        self.vector_store: FAISS = None
        self.fine_retriever: VectorStoreRetriever = None
        self.embeddings = None
        # self.coarse_retriever:BM25Retriever = None
        # self.ensemble_retriever:EnsembleRetriever = None

    def __del__(self):
        get_embedding_model_manager().release_model(caller_id=id(self))

    def load_embedding_model(self):
        self.embeddings = get_embedding_model_manager().acquire_model(
            caller_id=id(self), model_name=self.embedding_model
        )

    async def from_documents(self, docs: [Document]):
        loop = asyncio.get_running_loop()

        # Chunking
        logger.info("Chunking...")
        chunks = await self.splitter.split(docs)
        logger.info("Got {} chunks.".format(len(chunks)))

        # Embedding
        await loop.run_in_executor(None, self.load_embedding_model)
        logger.info("Calculating embeddings...")
        self.vector_store = await loop.run_in_executor(
            None, FAISS.from_documents, chunks, self.embeddings
        )
        logger.info("Embedding calculated.")

        # self.coarse_retriever = BM25Retriever.from_texts(chunks)
        # self.coarse_retriever.k = self.coarse_k
        # self.ensemble_retriever =ensemble_retriever = EnsembleRetriever(
        #   retrievers=[self.coarse_retriever, self.fine_retriever],
        #   weights=[0.5, 0.5]
        # )

    def init_retriever(self, retriever_param={"k": 6, "fetch_k": 12}):
        self.fine_retriever = self.vector_store.as_retriever(
            search_type="mmr",
            search_kwargs=retriever_param,
            # search_kwargs=self.mmr_param
        )

    def save(self, path: str):
        """
        Save the document store to local filesystem.
        """

        self.vector_store.save_local(path)

        config = {"embedding_name": self.embedding_model}
        with open(Path(path) / DocumentStore.config_filename, "w") as f:
            json.dump(config, f)

    @classmethod
    def load(cls, path: str):
        """
        Load constructed document store from local filesystem.
        """

        if not Path(path).exists():
            raise RuntimeError("The specified database doesn't exist.")

        if path.endswith(".zip"):
            # A temporary directory to store extracted database
            target_dir_name = hashlib.sha1(path.encode()).hexdigest()
            target_dir = Path(tempfile.gettempdir()) / "skyscope-dbqa" / target_dir_name

            # Don't extract the archive if it's already extracted.
            if (
                not target_dir.exists()
                or target_dir.lstat().st_mtime < Path(path).lstat().st_mtime
            ):
                logger.info("Extracting archived document store...")
                target_dir.mkdir(parents=True, exist_ok=True)
                # Open the zip file
                with zipfile.ZipFile(path, "r") as zip_ref:
                    # Extract all files to the temporary directory
                    zip_ref.extractall(target_dir)
                logger.info(f"Extracted document store to {target_dir}")

            config_path = list(Path(target_dir).rglob(DocumentStore.config_filename))
            if len(config_path) == 0:
                raise RuntimeError(
                    "Could not find the configuration file of the database."
                )
            path = config_path[-1].parents[0]

        logger.info(f"Loading document store from {path}...")
        config = {}
        with open(Path(path) / DocumentStore.config_filename, "r") as f:
            config = json.load(f)

        embedding_model_name = config["embedding_name"]
        store = cls(embedding_model=embedding_model_name)
        store.load_embedding_model()
        store.vector_store = FAISS.load_local(
            path, store.embeddings, allow_dangerous_deserialization=True
        )
        store.init_retriever()
        logger.info("Document store loaded.")
        return store

    async def retrieve(self, question: str) -> [Document]:
        loop = asyncio.get_running_loop()
        related_docs = await loop.run_in_executor(
            None, self.fine_retriever.get_relevant_documents, question
        )
        return related_docs
