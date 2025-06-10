#!/usr/local/bin/python

import argparse
import logging
import requests
import sys
import os
import tempfile
import urllib
import fileinput
import random
import traceback
from PIL import Image
from pathlib import Path
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

def random_transform(image_path, index):
    """Randomly flips, pans, and rotates the image."""
    image = Image.open(image_path)
    width, height = image.size
    
    # Random flip
    if random.random() > 0.5:
        image = image.transpose(Image.FLIP_LEFT_RIGHT)
    if random.random() > 0.5:
        image = image.transpose(Image.FLIP_TOP_BOTTOM)
    
    # Random rotate
    rotation = random.randint(0, 359)
    image = image.rotate(rotation, expand=1)
    
    # Random pan
    # pan_x = random.randint(-int(width/4), int(width/4))
    # pan_y = random.randint(-int(height/4), int(height/4))
    # image = image.crop((pan_x, pan_y, width + pan_x, height + pan_y))
    
    output_file_path = get_output_file_path(image_path, args=[str(index)])
    image.save(output_file_path)

    return output_file_path

def get_output_file_path(input_path, args):
    """
    Output file will be the same as the input file but with a new suffix.
    """
    input_filename, input_ext = os.path.splitext(input_path)
    output_filename = input_filename+'-'+'_'.join(args).replace('-', '').replace(':', '_')
    output_path = f"{output_filename}{input_ext}"
    return output_path

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
    parser.add_argument('-n', type=int, default=5)
    args, unknown_args = parser.parse_known_args()
    return args, unknown_args

if __name__ == "__main__":
    try:
        args, _ = parse_args() # Get ffmpeg arguments from command line
        sys.argv = sys.argv[:1]
        logging.basicConfig(level=logging.INFO if not args.debug else logging.DEBUG)
        if not args.debug:
            sys.tracebacklimit = -1

        for image_url in fileinput.input():
            image_url = image_url.strip()

            # Fetch the image
            downloaded_file, original_filename = download_file(image_url)

            logger.debug(f"Downloaded {downloaded_file}")

            # Generate n transformed images and upload them
            for i in range(args.n):

                # Random transform the image
                output_file = random_transform(downloaded_file, index=i)

                # Upload to the API
                uploaded_filename = get_output_file_path(
                    input_path=original_filename,
                    args=[str(i)]
                )
                result_url = upload_to_web(
                    file_path=output_file,
                    api_url=os.environ['SKYSCOPE_BASE_URL'],
                    api_token=os.environ["SKYSCOPE_API_KEY"],
                    original_filename=output_file
                )

                # Print the result URL
                print(f"![{result_url}]({result_url})")
            
                # Cleanup
                os.unlink(output_file)

            # Cleanup
            os.unlink(downloaded_file)

    except Exception as e:
        print(traceback.format_exc())