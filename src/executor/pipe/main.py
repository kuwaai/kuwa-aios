import os
import re
import sys
import asyncio
import logging
import shlex
from urllib.parse import urljoin
from importlib.metadata import version
from skyscope.executor import LLMExecutor, Modelfile

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from src.subprocess_helper import SubProcessHelper, StreamName

logger = logging.getLogger(__name__)


def extract_code_from_markdown(markdown_text):
    """
    Extracts code from markdown code blocks using the provided regular expression.

    Args:
        markdown_text: The markdown text to extract code from.

    Returns:
        A list of dictionaries, where each dictionary represents a code block
        and contains the following keys:
        - 'language': The language of the code block (if specified), otherwise None.
        - 'code': The code within the code block.
    """

    regex = r"```(?P<language>[^`\r\n]*)[\r\n]+(?P<code>.+?)```"
    matches = re.findall(regex, markdown_text, re.DOTALL)

    code_blocks = []
    for match in matches:
        code_block = {
            "language": match[0].strip() if match[1].strip() else None,
            "code": match[1].strip(),
        }
        code_blocks.append(code_block)

    return code_blocks


def extract_arguments(user_input):
    """
    Extracts user-defined arguments from a user prompt.

    Args:
        user_input: The user prompt string.

    Returns:
        A tuple containing:
        - The extracted arguments as a string.
        - The remaining user prompt after argument extraction.
    """

    # Find the first line of the user prompt that starts with '/'
    match = re.search(r"^/arg(\s.*)$", user_input, re.MULTILINE)

    if match:
        # Extract the arguments from the first line
        arguments = match.group(1).strip()
        # Remove the arguments line from the user prompt
        user_prompt = user_input.replace(match.group(0), "", 1).strip()
        return arguments, user_prompt
    else:
        return "", user_input


def is_exe(fpath):
    return os.path.isfile(fpath) and os.access(fpath, os.X_OK)


class PipeExecutor(LLMExecutor):
    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        """
        Add custom command-line arguments.
        """
        skyscope_root_path = os.environ.get(
            "SKYSCOPE_ROOT", os.path.join(os.path.expanduser("~"), "skyscope")
        )
        parser.add_argument(
            "--path",
            default=os.path.join(skyscope_root_path, "bin"),
            help="The path to find executables.",
        )
        parser.add_argument("--program", default="cat", help="The program to run.")
        parser.add_argument("--argv", default="", help="Arguments.")
        parser.add_argument(
            "--encoding",
            default="utf-8",
            help="The encoding of the standard I/O streams. Set to None indicating I/O raw bytes.",
        )
        parser.add_argument(
            "--api_base_url",
            default="http://127.0.0.1/",
            help="The API base URL of Skyscope multi-chat WebUI. This value will pass to the subprocess.",
        )
        parser.add_argument(
            "--hide_stderr",
            action="store_true",
            help="Hide the stderr content in the executor response.",
        )
        parser.add_argument(
            "--extract_last_codeblock",
            action="store_true",
            help="Make sure the program only gets the code from inside the last code block.",
        )

    def setup(self):
        logger.info(f"Path to find executables: {self.args.path}")

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        # Read configurations from modelfile.
        pipe_config = modelfile.parameters["pipe_"]
        program = pipe_config.get("program", self.args.program)
        argv = pipe_config.get("argv", self.args.argv)
        encoding = pipe_config.get("encoding", self.args.encoding)
        encoding = None if encoding is None or encoding.lower() == "none" else encoding
        hide_stderr = pipe_config.get("hide_stderr", self.args.hide_stderr)
        extract_last_codeblock = pipe_config.get(
            "extract_last_codeblock", self.args.extract_last_codeblock
        )

        # Initialize the context and helper of the subprocess
        last_user_prompt = history[-1]["content"]
        last_user_prompt = (
            modelfile.before_prompt + last_user_prompt + modelfile.after_prompt
        )
        user_argv, last_user_prompt = extract_arguments(last_user_prompt)
        sub_proc_input = last_user_prompt
        if extract_last_codeblock:
            codeblocks = extract_code_from_markdown(last_user_prompt)
            logger.debug(codeblocks)
            sub_proc_input = (
                codeblocks[-1]["code"] + "\n"
                if len(codeblocks) > 0
                else last_user_prompt
            )
        argv = argv.replace("{{user-args}}", user_argv)
        argv = shlex.split(argv)

        # Check whether the program is under the specified path
        path = os.path.abspath(self.args.path)
        program = os.path.abspath(f"{self.args.path}/{program}")
        logger.info(f"Program: {program}")
        if not program.startswith(path):
            yield "Access outside the root directory is forbidden."
            return

        # Run a subprocess with stdin from the request.
        program = os.path.realpath(program)
        # Checking permission will cause exception on windows
        # if not is_exe(program):
        #    yield "The program is not executable; please check its file permissions."
        #    return

        cmd = [program] + argv
        env = os.environ.copy()
        env["SKYSCOPE_BASE_URL"] = (
            modelfile.parameters["_"]["skyscope_api_base_urls"][0]
            if self.args.api_base_url is None
            else self.args.api_base_url
        )
        env["SKYSCOPE_KERNEL_BASE_URL"] = urljoin(
            self.kernel_url, f"{self.executor_iface_version}/"
        )
        env["SKYSCOPE_API_KEY"] = modelfile.parameters["_"]["user_token"]
        env["SKYSCOPE_VERSION"] = version("skyscope-executor")
        env["SKYSCOPE_LANGUAGE"] = modelfile.parameters["_"]["lang"]
        logger.info(f"Cmd: {cmd}")
        logger.debug(f"Env: {env}")
        generator = self.run_cmd(cmd, env, sub_proc_input, encoding)
        async for stream_name, chunk in generator:
            match stream_name:
                case StreamName.STDOUT:
                    yield chunk
                case StreamName.STDERR:
                    if not hide_stderr:
                        yield chunk
                case _:
                    pass

    async def run_cmd(
        self,
        cmd: list[str],
        env=os.environ.copy(),
        sub_proc_input="",
        encoding="utf-8",
        **kwargs,
    ):
        output_queue = asyncio.Queue()
        helper = SubProcessHelper(encoding=encoding)

        self.sub_process = await helper.create_subprocess(
            cmd, sub_proc_input, env=env, **kwargs
        )
        logger.debug(f"Created sub-process with PID: {self.sub_process.pid}")
        if sub_proc_input:
            logger.debug(f"Wrote {sub_proc_input} to stdin.")

        # Read the stdout and stderr stream from the queue.
        producer = asyncio.create_task(
            helper.stream_subprocess(self.sub_process, output_queue), name="producer"
        )
        consumer = None
        while not producer.done() or not output_queue.empty():
            consumer = (
                asyncio.create_task(output_queue.get(), name="consumer")
                if consumer is None
                else consumer
            )
            # Run the producer and consumer concurrently. Block until any coroutine is done.
            await asyncio.wait(
                [producer, consumer], return_when=asyncio.FIRST_COMPLETED
            )
            if not consumer.done():
                continue

            stream_name, chunk = consumer.result()
            consumer = None
            logger.debug(f"Received chunk: ({stream_name}, {chunk})")
            if chunk is None:
                continue
            match stream_name:
                case StreamName.STDERR:
                    logger.info(f"STDERR ({self.sub_process.pid}): {chunk}")
                case _:
                    pass
            yield (stream_name, chunk)

        await SubProcessHelper.terminate_subprocess(self.sub_process)
        if self.sub_process is not None:
            logger.debug(
                f"Sub-process {self.sub_process.pid} exited with return code {self.sub_process.returncode}"
            )
            self.sub_process = None

    async def abort(self):
        if self.sub_process is None:
            return "No job to abort."
        await SubProcessHelper.terminate_subprocess(self.sub_process)
        if self.sub_process is not None:
            logger.debug(
                f"Sub-process {self.sub_process.pid} exited with return code {self.sub_process.returncode}"
            )
            self.sub_process = None
        logger.debug("aborted")
        return "Aborted"


if __name__ == "__main__":
    executor = PipeExecutor()
    executor.run()
