#!/usr/local/bin/python

"""
## File Viewer Tool Specification

This tool functions as a "cat" command, printing file content or standard input
to the standard output. It handles various input types with specific behaviors:

**Input Handling:**

1. **URL Input:** - If the input line is a valid URL, the tool will attempt to
fetch the content of the referenced file. File Type Handling:
  - **Text:**  The raw file content is converted to UTF-8 encoding and printed
  to the standard output.
  - **Image:**  The markdown with base64 encoded data URL of the image is printed.
  - **Other Binary Formats:**  "Unrecognized binary format" is printed.

2. **Non-URL Input:** The tool will simply print the input line as it is to the
standard output.

**Example:**

- Input: `https://www.example.com/text.txt` (URL pointing to a text file) -
Output: The content of `text.txt` in UTF-8 encoding.

- Input: `https://www.example.com/image.jpg` (URL pointing to an image file) -
Output: The markdown with base64 encoded data URL of `image.jpg`.

- Input: `https://www.example.com/binary.bin` (URL pointing to a binary file) -
Output: "Unrecognized binary format"

- Input: `Hello, world!` (Plain text) - Output: `Hello, world!`
"""

import fileinput
import requests
import base64
import tempfile
import mimetypes
from magika import Magika
from urllib.parse import urlparse
from skyscope.rag.file_text_loader import FileTextLoader

magika = Magika()

def cat_tool(input_line):
    """
    Prints file content or stdin to stdout based on input type.
    
    Args:
        input_line: The input line to be processed.
    """

    if not is_url(input_line):
        print(input_line)
        return
    response = None
    try:
        response = requests.get(input_line)
        response.raise_for_status()
    except requests.exceptions.RequestException as e:
        print(f"Error fetching URL: {e}")
        return

    content = response.content
    magic_result = magika.identify_bytes(content)
    content_type = magic_result.output.mime_type.split(';', 1)[0]
    
    if 'image' in content_type:
        encoded_image = base64.b64encode(content).decode('utf-8')
        print(f"![](data:{content_type};base64,{encoded_image})")

    try:
        guessed_suffix = next(iter(mimetypes.guess_all_extensions(content_type)), None)
        with tempfile.NamedTemporaryFile(delete=False, suffix=guessed_suffix) as temp_file:
            temp_file.write(content)
            temp_file.flush()
            temp_file_path = temp_file.name
            loader = FileTextLoader(file_path=temp_file.name)
            docs = loader.load()
            if len(docs) > 0:
                print('\n'.join([i.page_content for i in docs]))
            else:
                print("Extracted text is empty.")
    except Exception as e:
        print(f"Error extracting text from file: {e}")
        return
        
def is_url(input_line):
    """
    Checks if the input line is a valid URL.
    
    Args:
        input_line: The input line to be checked.
    
    Returns:
        True if the input line is a valid URL, False otherwise.
    """
    try:
        result = urlparse(input_line)
        return all([result.scheme, result.netloc])
    except ValueError:
        return False

if __name__ == "__main__":

    for line in fileinput.input():
        line = line.strip()
        cat_tool(line)