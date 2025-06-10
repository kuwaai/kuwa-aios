#!/usr/local/bin/python

import argparse
import logging
import requests
import sys
import os
import tempfile
import urllib
import fileinput
from pathlib import Path
from subprocess import Popen, PIPE, STDOUT
from skyscope.client import FileOperations

logger = logging.getLogger(__name__)

def download_file(url):
    """Downloads a file from a given URL."""

    # Extract filename from URL
    filename = urllib.parse.urlparse(url).path.split('/')[-1]
    filename = urllib.parse.unquote(filename)
    filename, ext = os.path.splitext(filename)

    response = requests.get(url, stream=True)
    with tempfile.NamedTemporaryFile(delete=False, prefix=filename, suffix=ext) as f:
        if response.status_code != 200:
            raise Exception(f"Error downloading file. Status code: {response.status_code}")

        for chunk in response.iter_content(chunk_size=1024):
            f.write(chunk)
        
    return f.name, f'{filename}{ext}'

def get_output_file_path(input_path, args):
    """
    Output file will be the same as the input file but with a new suffix.
    """
    input_filename, input_ext = os.path.splitext(input_path)
    output_filename = input_filename+'-'+'_'.join(args).replace('-', '').replace(':', '_')
    output_path = f"{output_filename}{input_ext}"
    return output_path

def process_media(input_file, ffmpeg_args):
    """
    Processes the media file using ffmpeg with the provided arguments.
    """
    def log_subprocess_output(pipe):
        for line in iter(pipe.readline, b''): # b'\n'-separated lines
            logger.debug(line)

    output_file = get_output_file_path(input_path=input_file, args=ffmpeg_args)
    
    # Assuming you have ffmpeg installed and in your system PATH
    command = ["ffmpeg", *ffmpeg_args, "-y", "-i", input_file, output_file]
    logger.debug(command)
    process = Popen(command, stdout=PIPE, stderr=STDOUT)
    with process.stdout:
        log_subprocess_output(process.stdout)
    exitcode = process.wait() # 0 means success

    return output_file

def upload_to_web(file_path, api_url, api_token, original_filename=None):
    """Uploads the processed video to the specified API endpoint."""

    file_client = FileOperations(base_url=api_url, auth_token=api_token)

    if original_filename is not None:
        original_filepath = file_path
        file_path = (Path(file_path).parent / original_filename).absolute()
        os.rename(original_filepath, file_path)
    
    try:
        response = file_client.upload_file(file_path=file_path)
    except:
        logger.exception("Error occurs while uploading files.")
    
    if original_filename is not None:
        os.rename(file_path, original_filepath)

    return response['result']

def parse_args():
    parser = argparse.ArgumentParser()
    parser.add_argument('--debug', action='store_true')
    args, unknown_args = parser.parse_known_args()
    return args,unknown_args

if __name__ == "__main__":
    try:
        args, ffmpeg_args = parse_args() # Get ffmpeg arguments from command line
        sys.argv = sys.argv[:1]
        logging.basicConfig(level=logging.INFO if not args.debug else logging.DEBUG)
        if args.debug:
            sys.tracebacklimit = -1

        for video_url in fileinput.input():
            video_url = video_url.strip()

            # Download the video
            downloaded_file, original_filename = download_file(video_url)

            # Process the video with ffmpeg
            output_file = process_media(downloaded_file, ffmpeg_args)

            # Upload to the API
            uploaded_filename = get_output_file_path(
                input_path=original_filename,
                args=ffmpeg_args
            )
            result_url = upload_to_web(
                file_path=output_file,
                api_url=os.environ['SKYSCOPE_BASE_URL'],
                api_token=os.environ["SKYSCOPE_API_KEY"],
                original_filename=uploaded_filename
            )

            # Print the result URL
            print(result_url)

            # Cleanup
            os.unlink(downloaded_file)
            os.unlink(output_file)

    except Exception as e:
        print(f"{type(e).__name__}: {e.args[0]}")