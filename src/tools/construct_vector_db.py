#!/usr/local/bin/python

import os
import sys
import asyncio
import argparse
import logging
import requests
import zipfile
import tempfile
import urllib
import fileinput
import shutil
from torch import cuda
from pathlib import Path

from skyscope.client import SkyscopeClient
from skyscope.rag.document_store_factory import DocumentStoreFactory, path2file_url

logger = logging.getLogger(__name__)


def check_gpu():
    if cuda.is_available():
        gpu_count = cuda.device_count()
        logger.info(f'{gpu_count} GPU is available and being used.')
        for i in range(gpu_count):
            logger.info(cuda.get_device_name(i))
    else:
        logger.warning('GPU is not available, will use CPU instead.')

def download_file(url):
    """Downloads a file from a given URL."""

    # Extract filename from URL
    filename = urllib.parse.urlparse(url).path.split('/')[-1]
    filename = urllib.parse.unquote(filename)
    filename, ext = os.path.splitext(filename)

    response = requests.get(url, stream=True)
    with tempfile.NamedTemporaryFile(delete=False, prefix=f"{filename}-", suffix=ext) as f:
        if response.status_code != 200:
            raise Exception(f"Error downloading file. Status code: {response.status_code}")

        for chunk in response.iter_content(chunk_size=1024):
            f.write(chunk)
        
    return f.name, f'{filename}{ext}'

def remove(path):
    """
    Remove a file or a directory.
    Args:
      - path: Path to file or directory. The path could either be relative or absolute.
    """
    if os.path.isfile(path) or os.path.islink(path):
        # remove the file
        os.remove(path)
    elif os.path.isdir(path):
        # remove dir and all contains
        shutil.rmtree(path)
    else:
        raise ValueError(f"file {path} is not a file or dir.")

def unzip_to_temp(zip_file_path):
    """Unzips a .zip archive file to a temporary directory.

    Args:
        zip_file_path: The path to the .zip archive file.

    Returns:
        The path to the temporary directory where the files were extracted.
    """

    zip_filename = Path(zip_file_path).stem + '-'

    # Create a temporary directory
    dst_dir = tempfile.mkdtemp(prefix=zip_filename)

    # Open the zip file
    with zipfile.ZipFile(zip_file_path, 'r') as zip_ref:
        # Extract all files to the temporary directory
        logger.debug(f"Unzipping {zip_file_path} to {dst_dir}")
        zip_ref.extractall(dst_dir)

    # Remove the zip file
    remove(zip_file_path)
    
    return dst_dir

async def construct_db(
    docs_path:str,
    output_path:str,
    db_name:str,
    chunk_size:int = 512,
    chunk_overlap:int = 128,
    embedding_model:str = 'intfloat/multilingual-e5-small',
    should_create_bot:bool = True,
    bot_template:str = None,
    generator_model:str = None
    ):
    """
    Construct vector database from local documents and save to the destination.
    """

    logger.info(f'Constructing vector store...')
    document_store_factory = DocumentStoreFactory()
    document_store_kwargs = dict(
        embedding_model = embedding_model,
        chunk_size = chunk_size,
        chunk_overlap = chunk_overlap
    )
    db, _ = await document_store_factory.construct_document_store(
        urls = [path2file_url(docs_path)],
        document_store_kwargs = document_store_kwargs
    )
    logger.info(f'Vector store constructed.')
    #with tempfile.TemporaryDirectory() as tmpdirname:
    #    db.save(tmpdirname)
    #    os.replace(tmpdirname, output_path)
    db.save(output_path)
    logger.info(f'Saved vector store to {output_path}.')
    if should_create_bot:
        await create_bot(db_name=db_name, db_path=output_path, template=bot_template, generator_model=generator_model)

async def create_bot(
    db_name,
    db_path,
    template:str = "PARAMETER retriever_database '{db_path}'\nPARAMETER generator_model '{generator_model}'",
    generator_model:str = "geminipro",
):

    client = SkyscopeClient(
        base_url = os.environ["SKYSCOPE_BASE_URL"],
        auth_token = os.environ["SKYSCOPE_API_KEY"]
    )
    bot_name = f"DB QA ({db_name})"
    modelfile = template.format(db_path=db_path, generator_model=generator_model)
    logger.debug(f"Template: {template}\nModelfile:{modelfile}")
    response = await client.create_bot(
        bot_name = bot_name,
        bot_description = "Created by \"Construct Vector DB\"",
        llm_access_code = ".tool/skyscope/rag",
        modelfile = modelfile,
    )
    logger.info(f"Bot \"{bot_name}\" created successfully.")

def parse_args():
    parser = argparse.ArgumentParser(description='Construct a FAISS vector database from local documents.')
    parser.add_argument("output_dir", help="The path where the final database will be stored. Under SKYSCOPE_ROOT", default="database", type=str)
    parser.add_argument('--visible_gpu', default=None, help='Specify the GPU IDs that this executor can use. Separate by comma.')
    parser.add_argument("--chunk-size", help="The chunk size to split the document.", type=int, default=512)
    parser.add_argument("--chunk-overlap", help="The chunk size to split the document.", type=int, default=128)
    parser.add_argument("--embedding-model", help="The embedding model to use", type=str, default="intfloat/multilingual-e5-small")
    parser.add_argument("--no-create-bot", help="Do not create corresponding bot", action="store_true")
    parser.add_argument("--bot-template", help="The template to create bot.", default="PARAMETER retriever_database '{db_path}'\nPARAMETER generator_model '{generator_model}'")
    parser.add_argument("--generator-model", help="The generator model to use", type=str, default="geminipro")
    parser.add_argument("--log", help="The log level. (INFO, DEBUG, ...)", type=str, default="INFO")
    args, unknown_args = parser.parse_known_args()
    return args,unknown_args

if __name__ == "__main__":
    try:
        args, _ = parse_args()
        sys.argv = sys.argv[:1]

        # Setup logger
        numeric_level = getattr(logging, args.log.upper(), None)
        if not isinstance(numeric_level, int):
            raise ValueError(f'Invalid log level: {args.log}')
        logging.basicConfig(level=numeric_level)

        if args.visible_gpu:
            os.environ["CUDA_VISIBLE_DEVICES"] = args.visible_gpu
        check_gpu()
        
        for doc_url in fileinput.input():
            doc_url = doc_url.strip()

            # Download the video
            downloaded_file, original_filename = download_file(doc_url)

            docs_path = downloaded_file
            if str(downloaded_file).endswith(".zip"):
                docs_path = unzip_to_temp(downloaded_file)
            
            db_name = Path(original_filename).stem
            db_path = os.path.abspath(os.path.join(os.environ["SKYSCOPE_ROOT"], f"./{args.output_dir}/{db_name}"))

            logger.debug(f"docs_path={docs_path}")
            logger.debug(f"db_path={db_path}")

            # Construct the vector database
            asyncio.run(
                construct_db(
                    docs_path=docs_path,
                    output_path=db_path,
                    db_name=db_name,
                    chunk_size=args.chunk_size,
                    chunk_overlap=args.chunk_overlap,
                    embedding_model=args.embedding_model,
                    should_create_bot=not args.no_create_bot,
                    bot_template=args.bot_template,
                    generator_model=args.generator_model
                )
            )

            # Cleanup
            remove(docs_path)

    except Exception as e:
        logger.exception(f"{type(e).__name__}: {e.args[0]}")
