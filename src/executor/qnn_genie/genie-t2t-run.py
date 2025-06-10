import os
import sys
import logging
import subprocess
import tempfile
from pathlib import Path
from enum import Enum
from transformers import AutoTokenizer
from functools import lru_cache
from huggingface_hub import snapshot_download

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
sys.path.append(os.path.dirname(os.path.abspath(Path(__file__).parent)))

from pipe.main import PipeExecutor
from skyscope.executor import LLMExecutor, Modelfile

logger = logging.getLogger(__name__)


class OutputState(Enum):
    PROMPT_PROCESSING = 0
    TOKEN_GENERATION = 1
    POST_GENERATION = 2


class QnnGenieCliExecutor(LLMExecutor):
    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        parser.add_argument(
            "--tokenizer",
            type=str,
            default="thuniverse-ai/Llama-v3.2-3B-Chat-GENIE",
            help="HF repository ID of the tokenizer.",
        )
        parser.add_argument(
            "--model",
            type=str,
            default="thuniverse-ai/Llama-v3.2-3B-Chat-GENIE",
            help="HF repository ID of the model or the path to model.",
        )
        parser.add_argument(
            "--qnn_binaries",
            type=str,
            default=Path.cwd() / "qnn-binaries",
            help="Path to QNN genie binaries.",
        )
        pass

    def setup(self):
        self.model_id = self.args.model
        self.tokenizer_id = self.args.tokenizer
        self.stop = False
        self.tokenizer = self.get_tokenizer(self.tokenizer_id)
        self.pipe = None

    @lru_cache
    def get_tokenizer(self, hf_hub_model_id):
        tokenizer = AutoTokenizer.from_pretrained(hf_hub_model_id)
        return tokenizer

    async def run_genie_t2t(
        self,
        prompt: str,
        model_path_or_id: str,
        print_debug: bool = False,
        qnn_binary_path: str = Path.cwd() / "qnn-binaries",
    ):
        # prompt = prompt.replace('\n', '\\n')
        # qai_hub_model_id = model_id.replace("-", "_") + "_quantized"
        if os.path.exists(model_path_or_id):
            model_dir = model_path_or_id
        else:
            print(
                f"Model path {model_path_or_id} not found. Trying download it from HF Hub.",
                flush=True,
            )
            model_dir = snapshot_download(repo_id=model_path_or_id)
        prompt_fd, prompt_file_path = tempfile.mkstemp()
        with os.fdopen(prompt_fd, "w+", encoding="utf-8") as f:
            f.write(prompt)
            f.seek(0)
            logger.debug(f"[prompt] {f.read()}")

        qnn_binary_path = Path(qnn_binary_path).resolve()
        genie_t2t_run_paths = list(qnn_binary_path.glob("genie-t2t-run*"))
        genie_config_path = "genie_config.json"
        if len(genie_t2t_run_paths) == 0:
            raise RuntimeError(
                f'Could not find "genie_t2t_run" executable in directory "{qnn_binary_path}"'
            )
        cmd = [
            str(genie_t2t_run_paths[0]),
            "-c",
            str(genie_config_path),
            "--prompt_file",
            str(prompt_file_path),
        ]

        try:
            self.pipe = PipeExecutor()

            generator = self.pipe.run_cmd(cmd, cwd=model_dir, shell=False)
            begin_keyword = "[BEGIN]:"  # [TODO] Remove llama header
            end_keyword = "[END]"
            output_buffer = ""
            output_state = OutputState.PROMPT_PROCESSING
            async for stream_name, chunk in generator:
                if self.in_debug():
                    print(chunk, end="", flush=True)

                if print_debug:
                    yield chunk
                    continue

                output_buffer += chunk

                if (
                    output_state == OutputState.PROMPT_PROCESSING
                    and begin_keyword in output_buffer
                ):
                    output_state = OutputState.TOKEN_GENERATION
                    output_buffer = output_buffer[
                        output_buffer.index(begin_keyword) + len(begin_keyword) :
                    ].lstrip()

                if output_state == OutputState.TOKEN_GENERATION:
                    if len(output_buffer) < len(end_keyword):
                        continue
                    if output_buffer.endswith(end_keyword):
                        output_state = OutputState.POST_GENERATION
                        output_buffer = output_buffer.replace(end_keyword, "")
                        yield output_buffer
                    else:
                        output_length = len(output_buffer) - len(end_keyword)
                        output_chunk = output_buffer[:output_length]
                        output_buffer = output_buffer[output_length:]
                        yield output_chunk

        except subprocess.CalledProcessError as e:
            logger.exception(e.output.decode())
        finally:
            os.remove(prompt_file_path)

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        model_id = modelfile.parameters["llm_"].get("model", self.model_id)
        tokenizer_id = modelfile.parameters["llm_"].get("tokenizer", self.tokenizer_id)
        print_debug = modelfile.parameters["llm_"].get("debug", False)

        # Apply modelfile
        msg = modelfile.messages + history
        if modelfile.override_system_prompt is not None:
            msg = [
                {"content": modelfile.override_system_prompt, "role": "system"}
            ] + msg

        msg[-1]["content"] = (
            modelfile.before_prompt + msg[-1]["content"] + modelfile.after_prompt
        )

        self.tokenizer = self.get_tokenizer(tokenizer_id)
        prompt = self.tokenizer.apply_chat_template(
            msg, tokenize=False, add_generation_prompt=True
        )

        response_generator = self.run_genie_t2t(
            prompt=prompt,
            model_path_or_id=model_id,
            print_debug=print_debug,
            qnn_binary_path=self.args.qnn_binaries,
        )

        self.stop = False
        async for reply in response_generator:
            if self.stop:
                await response_generator.aclose()
            yield reply
        logger.info("Done")

    async def abort(self):
        self.stop = True
        if self.pipe:
            await self.pipe.abort()
        logger.debug("aborted")
        return "Aborted"


if __name__ == "__main__":
    executor = QnnGenieCliExecutor()
    executor.run()
