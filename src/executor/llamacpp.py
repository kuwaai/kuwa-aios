import re
import os
import sys
import logging
import time
import pprint
import contextlib
from typing import Optional, List

sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from huggingface_hub import hf_hub_download
from llama_cpp import Llama
import llama_cpp.llama_chat_format as llama_chat_format

from skyscope.executor import LLMExecutor, Modelfile
from skyscope.executor.llm_executor import rectify_chat_history
from skyscope.executor.util import (
    expose_function_parameter,
    read_config,
    merge_config,
    DescriptionParser,
)

logger = logging.getLogger(__name__)


class LlamaHelper:
    """
    Helper functions of llama-cpp-python
    """

    @staticmethod
    def get_special_token(model: Llama):
        """
        Get EOS and BOS token.
        Reference: https://github.com/abetlen/llama-cpp-python/blob/aa9f1ae011fbc22893750209af500fee3167f21c/llama_cpp/llama.py#L403
        """
        eos_token_id = int(
            model.metadata.get("tokenizer.ggml.eos_token_id", model.token_eos())
        )
        bos_token_id = int(
            model.metadata.get("tokenizer.ggml.bos_token_id", model.token_bos())
        )
        eos_token = model._model.token_get_text(eos_token_id)
        bos_token = model._model.token_get_text(bos_token_id)

        return bos_token, eos_token

    @staticmethod
    def get_chat_handler(model: Llama):
        """
        Get the chat handler to format the chat history.
        Reference: https://github.com/abetlen/llama-cpp-python/blob/5a595f035a094574a9bcf153e6696369f63fb585/llama_cpp/llama.py#L1728
        """

        chat_handler = (
            model.chat_handler
            or model._chat_handlers.get(model.chat_format)
            or llama_chat_format.get_chat_completion_handler(model.chat_format)
        )
        return chat_handler

    @staticmethod
    def create_chat_handler(model: Llama, template: str):
        """
        Create a chat handler from the template.
        Reference: https://github.com/abetlen/llama-cpp-python/blob/5a595f035a094574a9bcf153e6696369f63fb585/llama_cpp/llama.py#L429
        """

        bos_token, eos_token = LlamaHelper.get_special_token(model)

        chat_handler = llama_chat_format.Jinja2ChatFormatter(
            template=template, eos_token=eos_token, bos_token=bos_token
        ).to_chat_handler()

        return chat_handler

    @staticmethod
    def deduplicate_bos_eos(model: Llama, prompt: str):
        """
        Remove the duplicated BOS and EOS token from the prompt since the
        tokenizer will add them again.
        """

        bos_token, eos_token = LlamaHelper.get_special_token(model)
        prompt = prompt.lstrip(bos_token)
        prompt = prompt.rstrip(eos_token)
        return prompt


class ReflectiveLlama(Llama):
    """
    A fake Llama class the reflect the prompt.
    It can be used in inspecting the formatted prompt.
    """

    def __init__(self, *args, **kwargs):
        self.verbose = False
        self._stack = contextlib.ExitStack()

    def tokenize(
        self, text: bytes, add_bos: bool = True, special: bool = False
    ) -> List[int]:
        return text.decode("UTF-8")

    def create_completion(self, prompt, *args, **kwargs):
        t = int(time.time())
        return {
            "id": f"cmpl-reflect-{t}",
            "object": "text_completion",
            "created": t,
            "model": "reflective-llama",
            "choices": [
                {"text": prompt, "index": 0, "logprobs": None, "finish_reason": "stop"}
            ],
            "usage": {"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0},
        }


class LlamaCppDescParser(DescriptionParser):
    """
    Extract parameter description from Llama.create_completion.
    Ref: https://github.com/abetlen/llama-cpp-python/blob/f96de6d92087243f6430449c7a082f8a9071185a/llama_cpp/llama.py#L1423
    """

    def __call__(self, doc: str, name: str) -> str:
        match = re.search(rf"{name}:\s+(.*)\n", doc)
        if match:
            description = match.group(1)
        else:
            description = None
        return description


class LlamaCppExecutor(LLMExecutor):
    model_path: Optional[str] = None
    limit: int = 1024 * 3
    context_window: int = 4096
    stop_words: list = []
    system_prompt: str = None
    no_system_prompt: bool = False
    generation_config: dict = {"max_tokens": None}

    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        model_group = parser.add_argument_group("Model Options")
        model_group.add_argument(
            "--model_path",
            default=self.model_path,
            help='The "model path" parameter accepts either a local file path or a combination of a Hugging Face repository ID and filename in the format `hf://<hf-repo-id>?<filename>`.',
        )
        model_group.add_argument(
            "--visible_gpu",
            default=None,
            help="Specify the GPU IDs that this executor can use. Separate by comma.",
        )
        model_group.add_argument(
            "--ngl",
            type=int,
            default=0,
            help="Number of layers to offload to GPU. If -1, all layers are offloaded",
        )

        model_group.add_argument(
            "--limit",
            type=int,
            default=self.limit,
            help="The limit of the input tokens",
        )
        model_group.add_argument(
            "--system_prompt",
            default=self.system_prompt,
            help="The system prompt that is prepend to the chat history.",
        )
        model_group.add_argument(
            "--no_system_prompt",
            default=False,
            action="store_true",
            help="Disable the system prompt if the model doesn't support it.",
        )
        model_group.add_argument(
            "--context_window",
            type=int,
            default=self.context_window,
            help="The context window of the model",
        )
        model_group.add_argument(
            "--stop",
            default=[],
            nargs="*",
            help="Additional end-of-string keywords to stop generation.",
        )
        model_group.add_argument(
            "--override_chat_template",
            default=None,
            help="Override the default chat template provided by the model. See https://huggingface.co/docs/transformers/main/en/chat_templating",
        )

        # Generation Options
        gen_group = parser.add_argument_group(
            "Generation Options",
            "Generation options for llama.cpp. See https://llama-cpp-python.readthedocs.io/en/latest/api-reference/#llama_cpp.Llama.create_completion",
        )
        gen_group.add_argument(
            "-c",
            "--generation_config",
            default=None,
            help="The generation configuration in YAML or JSON format. This can be overridden by other command-line arguments.",
        )
        self.generation_config = expose_function_parameter(
            function=Llama.create_completion,
            parser=gen_group,
            defaults=self.generation_config,
            desc_parser=LlamaCppDescParser(),
        )

    def parse_model_path(self, model_path):
        if os.path.isfile(self.model_path):
            return self.model_path
        regex = r"hf://([^?]+)\?(.*)"
        match = re.match(regex, model_path)
        if not match:
            raise Exception(f"Invalid model_path format: {model_path}")

        repo_id = match.group(1)
        filename = match.group(2)
        model_path = hf_hub_download(repo_id=repo_id, filename=filename)
        logger.debug(f"Downloaded model from HF. Path: {model_path}")

        return model_path

    def setup(self):
        if self.args.visible_gpu:
            os.environ["CUDA_VISIBLE_DEVICES"] = self.args.visible_gpu

        self.model_path = self.args.model_path
        if not self.model_path:
            raise Exception("You need to configure a .gguf model path!")
        self.model_path = self.parse_model_path(self.model_path)

        self.limit = self.args.limit
        self.no_system_prompt = self.args.no_system_prompt
        self.system_prompt = "" if self.no_system_prompt else self.args.system_prompt
        self.context_window = self.args.context_window
        self.model = Llama(
            model_path=self.model_path,
            n_gpu_layers=self.args.ngl,
            n_ctx=self.context_window,
        )

        # Setup chat handler
        _, eos_token = LlamaHelper.get_special_token(self.model)
        self.stop_words = list(set([eos_token] + self.args.stop))
        if self.args.override_chat_template:
            self.model.chat_handler = LlamaHelper.create_chat_handler(
                self.model, self.args.override_chat_template
            )
        else:
            self.model.chat_handler = LlamaHelper.get_chat_handler(self.model)

        # Setup generation config
        file_gconf = (
            read_config(self.args.generation_config)
            if self.args.generation_config
            else {}
        )
        arg_gconf = {
            k: getattr(self.args, k)
            for k, v in self.generation_config.items()
            if f"--{k}" in sys.argv
        }
        self.generation_config = merge_config(
            base=self.generation_config, top=file_gconf
        )
        self.generation_config = merge_config(
            base=self.generation_config, top=arg_gconf
        )

        logger.debug(
            f"Generation config:\n{pprint.pformat(self.generation_config, indent=2)}"
        )
        logger.debug(f"Stop words: {self.stop_words}")

        self.serving_generator = None

    def synthesis_prompt(self, history: list, system_prompt: str, template: str):
        """
        Synthesis the prompt from chat history.
        """
        history = history.copy()
        if not self.no_system_prompt and system_prompt:
            history.insert(0, {"role": "system", "content": system_prompt})

        chat_handler = (
            self.model.chat_handler
            if not template
            else LlamaHelper.create_chat_handler(self.model, template)
        )

        prompt = None
        try:
            prompt = chat_handler(llama=ReflectiveLlama(), messages=history)["choices"][
                0
            ]["message"]["content"]
        except Exception as e:
            logger.exception(f"Error in template `{template}` with error: `{e}`")

        return prompt

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        # Apply modelfile
        system_prompt = modelfile.override_system_prompt or self.system_prompt
        prepended_messages = rectify_chat_history(modelfile.messages)
        if len(history) > 0 and history[-1]["role"] == "user":
            history[-1]["content"] = (
                "{before_prompt}{original_prompt}{after_prompt}".format(
                    before_prompt=modelfile.before_prompt,
                    original_prompt=history[-1]["content"],
                    after_prompt=modelfile.after_prompt,
                )
            )

        try:
            # Trim the history to fit into the context window
            prompt = ""
            while True:
                prompt = self.synthesis_prompt(
                    rectify_chat_history(prepended_messages + history),
                    system_prompt,
                    modelfile.template,
                )
                prompt_length = len(
                    self.model.tokenize(
                        text=prompt.encode("UTF-8", "ignore"),
                        add_bos=False,
                        special=False,
                    )
                )
                logging.debug(f"Prompt ({prompt_length} tokens): {prompt}")
                if prompt_length <= self.limit:
                    break

                history = rectify_chat_history(history[1:])
                if len(history) == 0:
                    logging.debug("Aborted since the input message exceeds the limit.")
                    yield "[Sorry, The input message is too long!]"
                    return

            output_generator = self.model.create_completion(
                LlamaHelper.deduplicate_bos_eos(self.model, prompt),
                stop=self.stop_words,
                echo=False,
                stream=True,
                **merge_config(self.generation_config, modelfile.parameters["llm_"]),
            )
            self.serving_generator = output_generator

            for i in output_generator:
                chunk = i["choices"][0]["text"]
                if self.in_debug():
                    print(end=chunk, flush=True)
                yield chunk

        except Exception as e:
            logger.error("Error occurs while processing request.")
            raise e
        finally:
            logger.debug("finished")

    async def abort(self):
        if not self.serving_generator:
            return "There's not running generation request to abort."
        self.serving_generator.close()
        self.serving_generator = None
        logger.debug("aborted")
        return "Aborted"


if __name__ == "__main__":
    executor = LlamaCppExecutor()
    executor.run()
