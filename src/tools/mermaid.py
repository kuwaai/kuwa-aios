import os
import sys
import argparse
import subprocess
import tempfile
import base64
import platform
import errno
import logging
from pathlib import Path
from skyscope.client import FileOperations

logger = logging.getLogger(__name__)


def silentremove(filename):
    try:
        os.remove(filename)
    except OSError as e:  # this would be "except OSError, e:" before Python 2.6
        if e.errno != errno.ENOENT:  # errno.ENOENT = no such file or directory
            raise  # re-raise exception if a different error occurred


def upload_to_web(file_path, api_url, api_token, original_filename=None):
    """Uploads the processed video to the specified API endpoint."""

    file_client = FileOperations(base_url=api_url, auth_token=api_token)

    if original_filename is not None:
        original_filepath = file_path
        file_path = (Path(file_path).parent / original_filename).absolute()
        os.rename(original_filepath, file_path)

    try:
        response = file_client.upload_file(file_path=file_path)
    except Exception:
        logger.exception("Error occurs while uploading files.")

    if original_filename is not None:
        os.rename(file_path, original_filepath)

    return response["result"]


def mermaid_to_data_url(mermaid_script, argv):
    """Converts Mermaid script to a data URL PNG image.

    Args:
        mermaid_script: The Mermaid script as a string.

    Returns:
        A data URL string representing the PNG image, or None if an error occurs.
    """
    img_url = None
    try:
        # Create a temporary file to store the Mermaid script
        with tempfile.NamedTemporaryFile(
            mode="w", suffix=".mmd", delete=False
        ) as temp_file:
            temp_file.write(mermaid_script)
            input_filename = temp_file.name

        # Create a temporary file for the output SVG
        output_filename = input_filename[:-4] + ".svg"

        # Call mmdc to convert the Mermaid script to PNG
        exec = "mmdc.cmd" if platform.system() == "Windows" else "mmdc"
        cmd = [exec, "-i", input_filename, "-o", output_filename, "-e", "svg"] + argv
        logger.debug(f"`cmd: {cmd}`")
        subprocess.run(
            cmd,
            check=True,
            capture_output=True,
            text=True,
        )

        img_url = upload_to_web(
            file_path=output_filename,
            api_url=os.environ["SKYSCOPE_BASE_URL"],
            api_token=os.environ["SKYSCOPE_API_KEY"],
        )

    except FileNotFoundError:
        logger.warning(
            "The `mmdc` command was not found. Please ensure that `mmdc` is installed and accessible in your system's PATH."
        )
    except subprocess.CalledProcessError as e:
        logger.error(f"Error during `mmdc` execution: {e.stderr}")
    except Exception:
        logger.exception("An unexpected error occurred.")
    finally:
        # Clean up temporary files
        silentremove(input_filename)
        silentremove(output_filename)
        return img_url


def get_mermaid_ink_url(mermaid_script, base_url):
    """
    Convert mermaid graph to image using online service.
    """
    graph_bytes = mermaid_script.encode("utf8")
    base64_bytes = base64.urlsafe_b64encode(graph_bytes)
    base64_string = base64_bytes.decode("ascii")
    return base_url + base64_string


def parse_args():
    parser = argparse.ArgumentParser()
    parser.add_argument("--debug", action="store_true")
    parser.add_argument(
        "--remote_base_url",
        help="Use a remote service, rather than a local program, to generate the diagram.",
    )
    args, unknown_args = parser.parse_known_args()
    return args, unknown_args


def main():
    try:
        args, mmdc_args = parse_args()  # Get ffmpeg arguments from command line
        log_format = "[%(levelname)s] %(message)s"
        logging.basicConfig(
            level=logging.INFO if not args.debug else logging.DEBUG, format=log_format
        )
        if args.debug:
            sys.tracebacklimit = -1
        mermaid_script = sys.stdin.read()
        mermaid_script = mermaid_script.strip()

        fallback_remote_url = "https://mermaid.ink/svg/"
        if args.remote_base_url is not None:
            img_url = get_mermaid_ink_url(mermaid_script, base_url=args.remote_base_url)
        else:
            img_url = mermaid_to_data_url(mermaid_script, argv=mmdc_args)

        if img_url is None:
            logger.warning(
                "An online service (mermaid.ink) will be used to generate the diagram."
            )
            img_url = get_mermaid_ink_url(mermaid_script, base_url=fallback_remote_url)
        print(f"![Generated diagram]({img_url})")
    except Exception as e:
        print(f"{type(e).__name__}: {e.args[0]}")


if __name__ == "__main__":
    main()
