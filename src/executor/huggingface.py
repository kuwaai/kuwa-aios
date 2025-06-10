import os
import sys
import torch
import logging
import pprint
import argparse
import requests
import queue
import json
from typing import Optional
from threading import Thread

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from transformers import (
    AutoTokenizer,
    AutoModelForCausalLM,
    AutoProcessor,
    AutoModelForVision2Seq,
    PaliGemmaForConditionalGeneration,
    LlavaForConditionalGeneration,
    LlavaNextForConditionalGeneration,
    Gemma3ForConditionalGeneration,
    PretrainedConfig,
    GenerationConfig,
    TextIteratorStreamer,
    StoppingCriteria,
    StoppingCriteriaList,
)

from skyscope.executor import LLMExecutor, Modelfile
from skyscope.executor.llm_executor import rectify_chat_history, extract_user_attachment
from skyscope.executor.multi_modality import get_supported_image_mime, fetch_image
from skyscope.executor.util import (
    read_config,
    merge_config,
)
from skyscope.executor.message import LogChunk, LogLevel
from transformers.utils import is_vision_available

if is_vision_available():
    from PIL import Image

logger = logging.getLogger(__name__)

VLM_TYPE_MAPPING = {
    "llava": LlavaForConditionalGeneration,
    "llava_next": LlavaNextForConditionalGeneration,
    "paligemma": PaliGemmaForConditionalGeneration,
    "phi3_v": AutoModelForCausalLM,
    "granite": AutoModelForVision2Seq,
    "gemma3": Gemma3ForConditionalGeneration,
}

VLM_TOKENIZER_MAPPING = {}  # Placeholder

VLM_PROCESSOR_MAPPING = {}  # Placeholder

VLM_SKIP_FETCH_IMAGE = ["gemma3"]

# VLM_IMAGE_TOKEN = {
#     "llava": "<image>",
#     "llava_next": "<image>",
#     "paligemma": "<image>",
#     "phi3_v": "<|image_1|>",
#     "granite": "<image>",
#     "gemma3": "<start_of_image>",
# }

TORCH_DTYPES = {
    "auto": "auto",  # Use the configuration from config.json of model
    "fp32": torch.float32,
    "float32": torch.float32,
    "fp16": torch.float16,
    "float16": torch.float16,
    "bf16": torch.bfloat16,
    "bfloat16": torch.bfloat16,
}


class CustomStoppingCriteria(StoppingCriteria):
    def __init__(self):
        self.proc = None

    def __call__(self, input_ids, score, **kwargs) -> bool:
        return not self.proc


class KwargsParser(argparse.Action):
    """Parser action class to parse kwargs of form key=value"""

    def __call__(self, parser, namespace, values, option_string=None):
        setattr(namespace, self.dest, dict())
        for val in values:
            if "=" not in val:
                raise ValueError(
                    (
                        "Argument parsing error, kwargs are expected in"
                        " the form of key=value."
                    )
                )
            kwarg_k, kwarg_v = val.split("=")
            try:
                converted_v = int(kwarg_v)
            except ValueError:
                try:
                    converted_v = float(kwarg_v)
                except ValueError:
                    converted_v = kwarg_v
            getattr(namespace, self.dest)[kwarg_k] = converted_v


def to_multi_modal_history(history: list[dict]) -> list[dict]:
    """
    Converts a chat history with text content into a multi-modal history,
    identifying image URLs and structuring the content accordingly.

    This function iterates through each message in the input chat history.
    For each message, it parses the text content to find URLs. If a URL
    is detected, it attempts to determine if it's a supported image type
    by checking its Content-Type header.

    If a URL is identified as a supported image, it's converted into an
    image content object with "type": "image" and "url": <the_url>.
    Text segments are kept as text content objects with "type": "text"
    and "text": <the_text>.

    If fetching the Content-Type for a URL fails or the content type is
    not in the supported image MIME types (obtained from
    `get_supported_image_mime()`), the URL is treated as plain text.

    Args:
        history (list[dict]): A list of chat messages, where each message is a
            dictionary with "role" (e.g., "user", "system") and "content"
            (a string potentially containing URLs) keys.

    Returns:
        list[dict]: A new list of chat messages, where the "content" of each
            message is now a list of content objects. Each content object is a
            dictionary with "type" and content-specific keys like "text" or "url".
            For text, it's {"type": "text", "text": "..."}.
            For images, it's {"type": "image", "url": "..."}.

    Example:
        Input:
        [
            {"role": "system", "content": "You are a helpful assistant."},
            {"role": "user", "content": "https://localhost/candy.JPG\nWhat animal is on the candy?"}
        ]

        Output:
        [
            {
                "role": "system",
                "content": [{"type": "text", "text": "You are a helpful assistant."}]
            },
            {
                "role": "user",
                "content": [
                    {"type": "image", "url": "https://localhost/candy.JPG"},
                    {"type": "text", "text": "What animal is on the candy?"}
                ]
            }
        ]

    Note:
        - URLs are identified using a simple regular expression.
        - Error handling for URL fetching is included; if fetching fails, the URL
        is treated as text.
    """
    multi_modal_history = []
    history = extract_user_attachment(
        history, allowed_mime_type=get_supported_image_mime()
    )
    for item in history:
        role = item["role"]
        content = item["content"]

        if not isinstance(content, str):
            # Already in multi-modal format, or other unexpected format
            multi_modal_history.append(item)
            continue

        content_list = []
        content_list.append({"type": "text", "text": content})
        for attachment in item.get("attachments", []):
            content_list.append(
                {"type": "image_url", "image_url": {"url": attachment["url"]}},
            )
        multi_modal_history.append({"role": role, "content": content_list})

    return multi_modal_history


class HuggingfaceExecutor(LLMExecutor):
    model_path: Optional[str] = None
    limit: int = 1024 * 3
    stop_words: list = []
    system_prompt: str = None
    no_system_prompt: bool = False
    timeout: float = 60.0
    generation_config: dict = {
        "max_new_tokens": 4096,
        "do_sample": False,
        "repetition_penalty": 1.0,
    }

    # Internal variable
    buffer_length: int = 1  # The length of the sliding window buffer

    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        model_group = parser.add_argument_group("Model Options")
        model_group.add_argument(
            "--model_path",
            default=self.model_path,
            help="Model path. It can be the path to local model or the model name on HuggingFace Hub",
        )
        model_group.add_argument(
            "--visible_gpu",
            default=None,
            help="Specify the GPU IDs that this executor can use. Separate by comma.",
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
            "--limit", type=int, default=self.limit, help="The limit of the user prompt"
        )
        model_group.add_argument(
            "--override_chat_template",
            default=None,
            help="Override the default chat template provided by the model. Reference: https://huggingface.co/docs/transformers/main/en/chat_templating",
        )
        model_group.add_argument(
            "--stop",
            default=[],
            nargs="*",
            help="Additional end-of-string keywords to stop generation.",
        )
        model_group.add_argument(
            "--timeout",
            type=float,
            default=self.timeout,
            help="The generation timeout in seconds.",
        )
        model_group.add_argument(
            "--load_8bits",
            action="store_true",
            default=False,
            help="Load the model in 8bit.",
        )
        parser.add_argument(
            "--torch_dtype",
            type=str,
            choices=TORCH_DTYPES.keys(),
            default="auto",
            help="Data type for PyTorch tensors. 'auto' selects based on config.json of model. 'fp16' uses half-precision, 'bf16' uses brain float 16.",
        )
        model_group.add_argument(
            "--trust_remote_code",
            action="store_true",
            default=False,
            help="Trust the remote code when loading model.",
        )
        model_group.add_argument(
            "--device_map",
            type=str,
            default="auto",
            help="Override the device_map of HF Accelerate.",
        )
        model_group.add_argument(
            "--tokenizer", type=str, default=None, help="Override the tokenizer."
        )
        model_group.add_argument(
            "--processor", type=str, default=None, help="Override the processor."
        )

        # Generation Options
        gen_group = parser.add_argument_group(
            "Generation Options",
            "GenerationConfig for Transformers. See https://huggingface.co/docs/transformers/en/main_classes/text_generation#transformers.GenerationConfig",
        )
        gen_group.add_argument(
            "-c",
            "--generation_config",
            default=None,
            help="The generation configuration in YAML or JSON format. This can be overridden by other command-line arguments.",
        )
        gen_group.add_argument(
            "--generation_kwargs",
            default={},
            type=str,
            nargs="*",
            action=KwargsParser,
            help="Additional kwargs passed to the HF generate function.",
        )

    def setup(self):
        if self.args.visible_gpu:
            os.environ["CUDA_VISIBLE_DEVICES"] = self.args.visible_gpu

        self.model_path = self.args.model_path
        self.tokenizer_name = (
            self.args.tokenizer if self.args.tokenizer is not None else self.model_path
        )
        self.processor_name = (
            self.args.processor if self.args.processor is not None else self.model_path
        )
        if not self.model_path:
            raise Exception("You need to configure a local or huggingface model path!")

        self.limit = self.args.limit
        torch_dtype = TORCH_DTYPES[self.args.torch_dtype]
        trust_remote_code = self.args.trust_remote_code
        try:
            device_map = json.loads(self.args.device_map)
        except json.decoder.JSONDecodeError:
            device_map = self.args.device_map
        model_dtype = {"torch_dtype": torch_dtype}
        if self.args.load_8bits:
            model_dtype["load_in_8bit"] = True

        model_config = PretrainedConfig.from_pretrained(self.model_path)
        self.model_type = model_config.model_type
        self.multi_modal = bool(self.model_type in VLM_TYPE_MAPPING)
        tokenizer_class = VLM_TOKENIZER_MAPPING.get(self.model_type, AutoTokenizer)
        processor_class = VLM_PROCESSOR_MAPPING.get(self.model_type, AutoProcessor)
        model_class = VLM_TYPE_MAPPING.get(self.model_type, AutoModelForCausalLM)

        self.tokenizer = tokenizer_class.from_pretrained(
            self.tokenizer_name,
            trust_remote_code=trust_remote_code,
        )
        self.processor = None
        try:
            processor = processor_class.from_pretrained(
                self.processor_name,
                trust_remote_code=trust_remote_code,
            )
            if type(processor) is not type(self.tokenizer):
                self.processor = processor
        except Exception as e:
            logging.warning(
                f"Could not load the processor {self.processor_name}: {str(e)}"
            )
        self.model = model_class.from_pretrained(
            self.model_path,
            device_map=device_map,
            trust_remote_code=trust_remote_code,
            **model_dtype,
        )
        logger.debug(
            f"Model type: {self.model_type}\n"
            + f"Model class: {type(self.model)}\n"
            + f"Tokenizer class: {type(self.tokenizer)}\n"
            + f"Processor class: {type(self.processor)}"
        )
        logger.debug(f"Device map: {self.model.hf_device_map}")

        self.system_prompt = self.args.system_prompt
        self.no_system_prompt = self.args.no_system_prompt
        self.timeout = self.args.timeout
        self.stop_words = [
            i
            for i in set(
                [self.tokenizer.eos_token, self.tokenizer.bos_token] + self.args.stop
            )
            if i is not None
        ]
        self.buffer_length = max([len(k) for k in self.stop_words] or [1])
        self.tokenizer.chat_template = (
            self.args.override_chat_template
            or self.tokenizer.chat_template
            or self.tokenizer.default_chat_template
        )
        self.CSC = CustomStoppingCriteria()

        # Setup generation config
        self.generation_config["pad_token_id"] = (
            self.tokenizer.pad_token_id
            if self.tokenizer.pad_token_id is not None
            else self.tokenizer.eos_token_id
        )
        default_gconf = GenerationConfig().to_dict()
        file_gconf = (
            read_config(self.args.generation_config)
            if self.args.generation_config
            else {}
        )
        self.generation_config = merge_config(
            base=default_gconf, top=self.generation_config
        )
        self.generation_config = merge_config(
            base=self.generation_config, top=file_gconf
        )
        self.generation_config = merge_config(
            base=self.generation_config, top=self.args.generation_kwargs
        )

        logger.debug(f"Stop words: {self.stop_words}")
        logger.debug(f"Buffer length: {self.buffer_length}")
        logger.debug(f"Chat template: {self.tokenizer.chat_template}")
        logger.debug(
            f"Generation config:\n{pprint.pformat(self.generation_config, indent=2)}"
        )

    def synthesis_prompt(self, history: list, system_prompt: str, template: str = None):
        """
        Synthesis the prompt from chat history.
        """
        history = history.copy()
        if not self.no_system_prompt and system_prompt:
            history.insert(0, {"role": "system", "content": system_prompt})

        chat_template = template or self.tokenizer.chat_template
        apply_chat_template = (
            self.processor.apply_chat_template
            if self.multi_modal and self.processor is not None
            else self.tokenizer.apply_chat_template
        )
        model_input = None
        try:
            model_input = apply_chat_template(
                history,
                chat_template=chat_template,
                tokenize=True,
                add_generation_prompt=True,
                return_dict=True,
                return_tensors="pt",
            )
        except Exception as e:
            logger.exception(
                f"Error in template `{self.tokenizer.chat_template}` with error: `{e}`"
            )

        return model_input

    def fetch_and_process_image(self, history: list[dict], prompt: str = ""):
        if self.processor is None:
            return None

        parts = [
            p
            for r in history
            for p in (r["content"] if type(r["content"]) is list else [])
        ]
        images = []
        for part in parts:
            if part.get("type") != "image":
                continue
            try:
                images.append(fetch_image(part.get("url")))
            except Exception as e:
                logger.warning(f"Error fetching image: {str(e)}")
        logger.info("Image fetched. Processing...")
        result = self.processor(text=prompt, images=images, return_tensors="pt")
        logger.info("Image processed.")
        return result

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
        if self.multi_modal:
            history = to_multi_modal_history(history)
            prepended_messages = to_multi_modal_history(prepended_messages)
            logger.debug(
                f"Parsed multi-modal history and prepended_messages: {history}; {prepended_messages}"
            )

        # Trim the history to fit into the context window
        prompt_embedding = []
        while True:
            model_inputs = self.synthesis_prompt(
                rectify_chat_history(prepended_messages + history),
                system_prompt,
                modelfile.template,
            )
            prompt_embedding = model_inputs["input_ids"]
            logger.debug(f"Length of prompt: {prompt_embedding.shape[1]}")
            if prompt_embedding.shape[1] <= self.limit:
                break

            history = rectify_chat_history(history[1:])
            if len(history) == 0:
                logging.debug("Aborted since the input message exceeds the limit.")
                yield "[Sorry, The input message is too long!]"
                return
        prompt = self.tokenizer.decode(prompt_embedding[0])
        logging.debug(f"Prompt: {prompt}")
        model_inputs = model_inputs.to(self.model.device)
        if (
            self.multi_modal
            and self.processor is not None
            and self.model_type not in VLM_SKIP_FETCH_IMAGE
        ):
            model_inputs = self.fetch_and_process_image(
                history=history, prompt=prompt
            ).to(self.model.device)
        streamer = TextIteratorStreamer(
            self.tokenizer, skip_prompt=True, timeout=self.timeout
        )
        thread = Thread(
            target=self.model.generate,
            kwargs=dict(
                **model_inputs,
                streamer=streamer,
                generation_config=GenerationConfig(
                    **merge_config(self.generation_config, modelfile.parameters["llm_"])
                ),
                stopping_criteria=StoppingCriteriaList([self.CSC]),
            ),
            daemon=True,
        )

        try:
            thread.start()
            self.CSC.proc = thread

            buffer = ""
            for chunk in streamer:
                buffer += chunk
                for word in self.stop_words:
                    if word not in buffer:
                        continue
                    logger.debug(f"{word} founded!")
                    buffer = buffer.split(word)[0]
                    self.CSC.proc = None
                    break

                if not self.CSC.proc:
                    break

                if len(buffer) > self.buffer_length:
                    output_length = len(buffer) - self.buffer_length
                    if self.in_debug():
                        print(end=buffer[:output_length], flush=True)
                    yield buffer[:output_length]
                    buffer = buffer[output_length:]

            if len(buffer) > 0:
                for word in self.stop_words:
                    buffer = buffer.replace(word, "")
                if self.in_debug():
                    print(end=buffer, flush=True)
                yield buffer  # Flush buffer

        except queue.Empty:
            message = 'The model produced no output. Increasing the executor\'s "--timeout" value may resolve this.\nIf the problem persists, a GPU out-of-memory or a model-specific issue is likely.'
            logger.exception(message)
            yield LogChunk(message, level=LogLevel.ERROR)
            raise

        finally:
            self.CSC.proc = None
            torch.cuda.empty_cache()
            logger.debug("finished")

    async def abort(self):
        if not self.CSC.proc:
            return "No process to abort"

        thread = self.CSC.proc
        self.CSC.proc = None
        logger.debug("aborting...")
        thread.join()
        logger.debug("aborted")
        torch.cuda.empty_cache()
        return "Aborted"


if __name__ == "__main__":
    executor = HuggingfaceExecutor()
    executor.run()
