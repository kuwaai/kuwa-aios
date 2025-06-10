import os
import sys
import logging
import pprint

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
import openai
from packaging.version import Version

import io
import functools
import mimetypes
import requests
import hashlib
from PIL import Image

from skyscope.executor import LLMExecutor, Modelfile
from skyscope.executor.llm_executor import extract_last_url
from skyscope.executor.util import (
    merge_config,
)

if Version(openai.__version__) < Version("1.2.3"):
    raise ValueError(
        f"Error: OpenAI version {openai.__version__}"
        " is less than the minimum version 1.2.3\n\n"
        ">>You should run 'pip install --upgrade openai')"
    )

logger = logging.getLogger(__name__)


class DalleExecutor(LLMExecutor):
    openai_base_url: str = "https://api.openai.com/v1"
    no_override_api_key: bool = False

    max_prompt_length: int = 1000
    generation_config: dict = {
        "model": "dall-e-2",
        "size": "256x256",
        "quality": "standard",
        "style": "vivid",
        "n": 1,
    }
    fixed_generation_config = {
        "response_format": "b64_json",
    }

    proc: bool = False

    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        model_group = parser.add_argument_group("Model Options")
        model_group.add_argument(
            "--api_key", default=None, help="The API key to access the service"
        )
        model_group.add_argument(
            "--no_override_api_key",
            default=False,
            action="store_true",
            help="Disable override the system API key with user API key.",
        )
        model_group.add_argument(
            "--base_url",
            default=self.openai_base_url,
            help="Alter the base URL to use third-party service.",
        )

        gen_group = parser.add_argument_group(
            "Generation Options", "Generation options for OpenAI API."
        )
        gen_group.add_argument(
            "--model",
            default=self.generation_config["model"],
            help="Model name. See https://platform.openai.com/docs/models/dall-e",
        )
        gen_group.add_argument(
            "--size",
            default=self.generation_config["size"],
            help="The size of the generated images. Must be one of 256x256, 512x512, or 1024x1024 for dall-e-2. Must be one of 1024x1024, 1792x1024, or 1024x1792 for dall-e-3 models",
        )
        gen_group.add_argument(
            "--quality",
            default=self.generation_config["quality"],
            help="The quality of the image that will be generated. hd creates images with finer details and greater consistency across the image. This param is only supported for dall-e-3.",
        )
        gen_group.add_argument(
            "--style",
            default=self.generation_config["style"],
            help="The style of the generated images. Must be one of vivid or natural. Vivid causes the model to lean towards generating hyper-real and dramatic images. Natural causes the model to produce more natural, less hyper-real looking images. This param is only supported for dall-e-3.",
        )
        gen_group.add_argument(
            "--n",
            default=self.generation_config["n"],
            type=int,
            help="The number of images to generate. Must be between 1 and 10. For dall-e-3, only n=1 is supported.",
        )

    def setup(self):
        self.generation_config["model"] = self.args.model
        self.generation_config["size"] = self.args.size
        self.generation_config["quality"] = self.args.quality
        self.generation_config["style"] = self.args.style
        self.generation_config["n"] = self.args.n
        self.openai_base_url = self.args.base_url
        self.api_key = self.args.api_key
        self.no_override_api_key = self.args.no_override_api_key
        if not (self.api_key or "").startswith("sk-") and not self.no_override_api_key:
            logger.warning(
                'By incorporating the "--no_override_api_key" argument, you can prevent overriding of the specified third-party API key by the user\'s OpenAI API key.'
            )

        logger.debug(
            f"Generation config:\n{pprint.pformat(self.generation_config, indent=2)}"
        )

        self.proc = False

    @functools.cache
    def get_supported_image_mime(self):
        def ext2mime(ext):
            return mimetypes.guess_type(f"a{ext}")[0]

        exts = Image.registered_extensions()
        exts = {ex for ex, f in exts.items() if f in Image.OPEN}
        mimes = {ext2mime(ex) for ex in exts} - {None}
        return mimes

    def image2png(self, img):
        if img is None:
            return None
        buffered = io.BytesIO()
        img.save(buffered, format="PNG")
        # return 'data:image/png;base64,'
        return buffered.getvalue()

    def fetch_image_as_png(self, url: str):
        image = None
        if (url is not None and url != "") and requests.head(
            url, allow_redirects=True
        ).headers["content-type"] in self.get_supported_image_mime():
            image = Image.open(requests.get(url, stream=True, allow_redirects=True).raw)
            image = image.convert("RGBA")
            logger.info("Image fetched.")

        result = self.image2png(image) if image is not None else None
        return result

    def get_mask_image(self, img):
        input_image = Image.open(io.BytesIO(img))
        width, height = input_image.size
        # Create a new image with an alpha channel (RGBA) and the same size as the input image
        mask_image = Image.new("RGBA", (width, height), (0, 0, 0, 0))

        return self.image2png(mask_image)

    async def image2image(self, client, prompt, url, generation_config):
        image = self.fetch_image_as_png(url)
        if image is None:
            logger.warning("Failed to fetch image")
            return None

        generation_config = {
            k: generation_config[k] for k in ["model", "n", "size", "response_format"]
        }
        images_response = await client.images.edit(
            prompt=prompt,
            image=image,
            mask=self.get_mask_image(image),
            **generation_config,
        )
        return [img.model_dump()["b64_json"] for img in images_response.data]

    async def text2image(self, client, prompt, generation_config):
        generation_config = {
            k: generation_config[k]
            for k in ["model", "n", "size", "response_format", "quality", "style"]
        }
        images_response = await client.images.generate(
            prompt=prompt, **generation_config
        )
        return [img.model_dump()["b64_json"] for img in images_response.data]

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        try:
            openai_token = self.api_key
            if not self.no_override_api_key:
                openai_token = (
                    modelfile.parameters["_"].get("openai_token") or self.api_key
                )

            if not openai_token or len(openai_token) == 0:
                yield "[Please enter your OpenAI API Token in the user settings on the website in order to use the model.]"
                return

            openai_token = openai_token.strip()

            url, history = extract_last_url(history)

            # Parse and process modelfile
            # override_system_prompt, messages = (
            #     modelfile.override_system_prompt,
            #     modelfile.messages,
            # )
            prompt = (
                modelfile.before_prompt
                + history[-1]["content"]
                + modelfile.after_prompt
            )
            generation_config = merge_config(
                self.generation_config, modelfile.parameters["model_"]
            )
            generation_config["response_format"] = "b64_json"
            generation_config["user"] = hashlib.sha1(
                modelfile.parameters["_"]["user_id"].encode("utf-8")
            ).hexdigest()

            if len(prompt) > self.max_prompt_length:
                yield f"[Prompt too long. Tne maximum length is {self.max_prompt_length}]"
                return

            if not openai_token or len(openai_token) == 0:
                yield "[Please enter your OpenAI API Token in the user settings on the website in order to use the model.]"
                return

            client = openai.AsyncOpenAI(
                api_key=openai_token, base_url=self.openai_base_url
            )

            generated_images = []
            if url is None:
                generated_images = await self.text2image(
                    client, prompt, generation_config
                )
            else:
                generated_images = await self.image2image(
                    client, prompt, url, generation_config
                )

            for image in generated_images:
                image = f"data:image/png;base64,{image}"
                yield "![{prompt}]({image})\n".format(prompt=prompt, image=image)

        except Exception as e:
            logger.exception("Error occurs when calling OpenAI API")
            if str(e).startswith("Incorrect API key provided:"):
                yield "[Invalid OpenAI API Token, please check if the OpenAI API Token is correct.]"
            else:
                yield str(e)
        finally:
            self.proc = False
            logger.debug("finished")

    async def abort(self):
        if self.proc:
            self.proc = False
            logger.debug("aborted")
            return "Aborted"
        return "No process to abort"


if __name__ == "__main__":
    executor = DalleExecutor()
    executor.run()
