import re
import os
import sys
import logging
import json
import requests
import oschmod

sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from kuwa.executor import LLMExecutor, Modelfile
from kuwa.executor.llm_executor import extract_last_url
from kuwa.executor.modelfile import extract_text_from_quotes
from kuwa.client import KuwaClient

logger = logging.getLogger(__name__)


class UploaderExecutor(LLMExecutor):
    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        """
        Override this method to add custom command-line arguments.
        """
        kuwa_root_path = os.environ.get(
            "KUWA_ROOT", os.path.join(os.path.expanduser("~"), "kuwa")
        )
        parser.add_argument(
            "--root",
            default=kuwa_root_path,
            help="The root path to store the file.",
            type=str,
        )
        parser.add_argument(
            "--api_base_url",
            help="The API base URL of Kuwa multi-chat WebUI. This value will pass to the subprocess.",
        )

    def setup(self):
        logger.info(f"Root path: {self.args.root}")

    def download_file(self, url, save_path):
        """
        Downloads a file from a given URL to a specified path.

        Args:
            url (str): The URL of the file to download.
            save_path (str): The path to save the downloaded file.
        """

        # Send a GET request to the URL
        response = requests.get(url, stream=True, timeout=5)

        # Raise an exception if the request was unsuccessful
        response.raise_for_status()

        # Get the file name from the URL or use a default name
        file_name = url.split("/")[-1] or "uploaded_file"

        # Create the complete save path including the file name
        complete_path = os.path.join(save_path, file_name)

        # Open the file in write binary mode and write the content in chunks
        with open(complete_path, "wb") as file:
            for chunk in response.iter_content(chunk_size=8192):
                file.write(chunk)
        return file_name, complete_path

    def parse_botfile(self, botfile_content):
        """
        Parses a botfile's content (as a multi-line string) and extracts the bot name and base model.

        Args:
            botfile_content: The botfile content as a multi-line string.

        Returns:
            A tuple containing the bot name and base model as strings.
        """
        result = {"metadata": {}, "modelfile": ""}
        metadata_prefix = "KUWABOT"

        for line in botfile_content.splitlines():
            if not line.startswith(metadata_prefix):
                continue
            matches = re.match(
                rf"{metadata_prefix}\s+(?P<key>\S+)\s+(?P<value>.*)", line.strip()
            )
            result["metadata"][matches.group("key")] = extract_text_from_quotes(
                matches.group("value")
            )

        result["modelfile"] = re.sub(
            rf"^{metadata_prefix}.*\n?", "", botfile_content, flags=re.MULTILINE
        )

        logger.debug(json.dumps(result, indent=2))

        return result

    def escape_botfile(self, str):
        return str.replace("\\", "\\\\")

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        dst_dir = modelfile.parameters["uploader_"].get("dst_dir", "/database")
        bot_template = modelfile.parameters["uploader_"].get("bot_template")
        succeed_message = modelfile.parameters["uploader_"].get(
            "succeed_message", "File uploaded successfully."
        )
        chmod = modelfile.parameters["uploader_"].get("chmod", None)

        url, history = extract_last_url(history)
        dst_path = os.path.abspath(os.path.join(self.args.root, "./" + dst_dir))

        # Check whether the destination path is under the root path
        if not dst_path.startswith(os.path.abspath(self.args.root)):
            logger.debug(
                f"Root path:{os.path.abspath(self.args.root)}\nDst path: {dst_path}"
            )
            yield "Access outside the root directory is forbidden."
            return

        file_name, file_path = self.download_file(url, dst_path)
        if chmod is not None:
            oschmod.set_mode(file_path, chmod)
        yield f"{succeed_message}\n"

        if bot_template is None:
            logger.debug("No bot template specified. Skipped creating bot.")
            return

        botfile = bot_template.replace(
            "{{file_name}}", self.escape_botfile(file_name)
        ).replace("{{file_path}}", self.escape_botfile(file_path))
        botfile = bytes(botfile, "utf-8").decode("unicode_escape")
        botfile = self.parse_botfile(botfile)
        if (
            botfile["metadata"].get("name") is None
            or botfile["metadata"].get("base") is None
        ):
            yield "Missed name or base in botfile. Failed to create bot.\n"
            return

        kuwa_api_token = modelfile.parameters["_"]["user_token"]
        if self.args.api_base_url is None:
            try:
                kuwa_api_base_url = modelfile.parameters["_"]["kuwa_api_base_urls"][0]
            except IndexError:
                kuwa_api_base_url = None
        else:
            kuwa_api_base_url = self.args.api_base_url
        client = KuwaClient(base_url=kuwa_api_base_url, auth_token=kuwa_api_token)
        response = await client.create_bot(
            bot_name=botfile["metadata"].get("name"),
            bot_description=botfile["metadata"].get("description"),
            llm_access_code=botfile["metadata"].get("base"),
            modelfile=botfile["modelfile"],
        )
        if response.get('status', '') != 'success':
            yield f'Bot "{botfile["metadata"].get("name")}" created successfully.'
        else:
            yield f'Failed to create bot "{botfile["metadata"].get("name")}".\n{response}'

    async def abort(self):
        logger.debug("aborted")
        return "Aborted"


if __name__ == "__main__":
    executor = UploaderExecutor()
    executor.run()
