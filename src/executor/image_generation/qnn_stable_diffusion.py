import os
import io
import sys
import logging
import base64
import torch
from enum import Enum

sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from skyscope.executor import LLMExecutor, Modelfile
from skyscope.executor.llm_executor import extract_last_url

import numpy as np
from diffusers import UNet2DConditionModel
from diffusers.models.embeddings import get_timestep_embedding
from huggingface_hub import snapshot_download
from tokenizers import Tokenizer
from diffusers import DPMSolverMultistepScheduler
import os
import subprocess
import glob
from PIL import Image
import tempfile

logger = logging.getLogger(__name__)


def image_to_data_url(img):
    buffered = io.BytesIO()
    img.save(buffered, format="JPEG")
    return "data:image/jpeg;base64," + base64.b64encode(buffered.getvalue()).decode(
        "utf-8"
    )


class Task(Enum):
    TEXT2IMG = 1
    IMG2IMG = 2
    INPAINTING = 3


class Generator:
    def __init__(self, gen):
        self.gen = gen

    def __iter__(self):
        self.value = yield from self.gen
        return self.value


class QnnStableDiffusionApp:
    def __init__(
        self,
        num_steps: int = 50,
        seed: int = np.int64(1.36477711e14),
        guidance_scale: float = 7.5,
        qnn_binaries_path: str = os.getcwd() + "\\qnn-binaries",
        models_context_path: str = os.getcwd() + "\\stable-diffusion-v1.5-quantize",
    ):
        self.num_steps = num_steps
        self.seed = seed
        self.guidance_scale = guidance_scale
        self.qnn_binaries_path = qnn_binaries_path
        self.models_context_path = models_context_path

        self.time_embeddings = None
        # self.time_embeddings = UNet2DConditionModel.from_pretrained(
        #     "runwayml/stable-diffusion-v1-5", subfolder="unet"
        # ).time_embedding

        # Define Tokenizer output max length (must be 77)
        tokenizer_max_length = 77

        # Initializing the Tokenizer
        self.tokenizer = Tokenizer.from_pretrained("openai/clip-vit-large-patch14")

        # Setting max length to tokenizer_max_length
        self.tokenizer.enable_truncation(tokenizer_max_length)
        self.tokenizer.enable_padding(pad_id=49407, length=tokenizer_max_length)

        # Initializing the Scheduler
        self.scheduler = DPMSolverMultistepScheduler(
            num_train_timesteps=1000,
            beta_start=0.00085,
            beta_end=0.012,
            beta_schedule="scaled_linear",
        )
        # Setting up user provided time steps for Scheduler
        self.scheduler.set_timesteps(self.num_steps)

    def generate_image(
        self,
        prompt: str,
    ):
        # Run Tokenizer
        uncond_tokens = self.run_tokenizer("")
        cond_tokens = self.run_tokenizer(prompt)

        # Run Text Encoder on Tokens
        uncond_text_embedding = self.run_text_encoder(uncond_tokens)
        user_text_embedding = self.run_text_encoder(cond_tokens)

        # Initialize the latent input with random initial latent
        random_init_latent = torch.randn(
            (1, 4, 64, 64), generator=torch.manual_seed(self.seed)
        ).numpy()
        latent_in = random_init_latent.transpose((0, 2, 3, 1)).copy()

        # Run the loop for user_step times
        for step in range(self.num_steps):
            print(f'Step {step} Running...')

            # Get timestep from step
            timestep = self.get_timestep(step)

            if self.time_embeddings is not None:
                time_input = self.get_time_embedding(timestep)
            else:
                time_input = np.array([[timestep]], np.float32)

            # Run U-net for const embeddings
            unconditional_noise_pred = self.run_unet(
                latent_in, time_input, uncond_text_embedding
            )

            # Run U-net for user text embeddings
            conditional_noise_pred = self.run_unet(
                latent_in, time_input, user_text_embedding
            )

            # Run Scheduler
            latent_in = self.run_scheduler(
                unconditional_noise_pred, conditional_noise_pred, latent_in, timestep
            )

            yield f"{step}/{self.num_steps}"

        # Run VAE
        output_image = self.run_vae(latent_in)

        return Image.fromarray(output_image, mode="RGB")

    def get_time_embedding(self, timestep):
        timestep = torch.tensor([timestep])
        t_emb = get_timestep_embedding(timestep, 320, True, 0)

        emb = self.time_embeddings(t_emb).detach().numpy()

        return emb

    def run_tokenizer(self, prompt):
        # Run Tokenizer encoding
        token_ids = self.tokenizer.encode(prompt).ids
        # Convert tokens list to np.array
        token_ids = np.array(token_ids, dtype=np.float32)

        return token_ids

    def run_scheduler(self, noise_pred_uncond, noise_pred_text, latent_in, timestep):
        # Convert all inputs from NHWC to NCHW
        noise_pred_uncond = np.transpose(noise_pred_uncond, (0, 3, 1, 2)).copy()
        noise_pred_text = np.transpose(noise_pred_text, (0, 3, 1, 2)).copy()
        latent_in = np.transpose(latent_in, (0, 3, 1, 2)).copy()

        # Convert all inputs to torch tensors
        noise_pred_uncond = torch.from_numpy(noise_pred_uncond)
        noise_pred_text = torch.from_numpy(noise_pred_text)
        latent_in = torch.from_numpy(latent_in)

        # Merge noise_pred_uncond and noise_pred_text based on user_text_guidance
        noise_pred = noise_pred_uncond + self.guidance_scale * (
            noise_pred_text - noise_pred_uncond
        )

        # Run Scheduler step
        latent_out = self.scheduler.step(
            noise_pred, timestep, latent_in
        ).prev_sample.numpy()

        # Convert latent_out from NCHW to NHWC
        latent_out = np.transpose(latent_out, (0, 2, 3, 1)).copy()

        return latent_out

    # Function to get timesteps
    def get_timestep(self, step):
        return np.int32(self.scheduler.timesteps.numpy()[step])

    # Define generic qnn-net-run block
    def run_qnn_net_run(self, model_context, input_data_list):
        # Define tmp directory path for intermediate artifacts
        tmp_dir = tempfile.TemporaryDirectory()
        tmp_dirpath = tmp_dir.name
        # tmp_dirpath = os.path.abspath('tmp')
        # os.makedirs(tmp_dirpath, exist_ok=True)

        # Dump each input data from input_data_list as raw file
        # and prepare input_list_filepath for qnn-net-run
        input_list_text = ""
        for index, input_data in enumerate(input_data_list):
            # Create and dump each input into raw file
            raw_file_path = f"{tmp_dirpath}\\input_{index}.raw"
            input_data.tofile(raw_file_path)
            # Keep appending raw_file_path into input_list_text for input_list_filepath file
            input_list_text += raw_file_path + " "

        # Create input_list_filepath and add prepared input_list_text into this file
        input_list_filepath = f"{tmp_dirpath}\\input_list.txt"
        with open(input_list_filepath, "w") as f:
            f.write(input_list_text)
        input_list_filepath = input_list_filepath.replace(" ", "\ ")

        # Execute qnn-net-run on shell
        cmd = f'"{self.qnn_binaries_path}\\qnn-net-run.exe" --retrieve_context "{model_context}" --backend "{self.qnn_binaries_path}\\QnnHtp.dll" \
        --input_list "{input_list_filepath}" --output_dir "{tmp_dirpath}"'  # + " --log_level verbose"
        try:
            print(cmd)
            log = subprocess.check_output(cmd, stderr=subprocess.STDOUT, shell=True)
            print(log.decode())
        except subprocess.CalledProcessError as e:
            print(e.output.decode())

        # Read the output data generated by qnn-net-run
        output_filenames = glob.glob(f"{tmp_dirpath}/Result_0/*.raw")
        if not output_filenames:
            raise RuntimeError("No output found in the output directory.")
        output_data = np.fromfile(output_filenames[0], dtype=np.float32)

        # Delete all intermediate artifacts
        # shutil.rmtree(tmp_dirpath)
        tmp_dir.cleanup()

        return output_data

    # qnn-net-run for text encoder
    def run_text_encoder(self, input_data):
        output_data = self.run_qnn_net_run(
            f"{self.models_context_path}\\TextEncoderQuantizable_w8a16.bin",
            # f"{self.models_context_path}\\stable_diffusion_v1_5_quantized-textencoder_quantized-snapdragon_x_elite.bin",
            [input_data],
        )
        # Output of Text encoder should be of shape (1, 77, 768)
        output_data = output_data.reshape((1, 77, 768))
        return output_data

    # qnn-net-run for U-Net
    def run_unet(self, input_data_1, input_data_2, input_data_3):
        output_data = self.run_qnn_net_run(
            f"{self.models_context_path}\\UnetQuantizable_w8a16.bin",
            # f"{self.models_context_path}\\stable_diffusion_v1_5_quantized-unet_quantized-snapdragon_x_elite.bin",
            [input_data_1, input_data_2, input_data_3],
        )
        # Output of UNet should be of shape (1, 64, 64, 4)
        output_data = output_data.reshape((1, 64, 64, 4))
        return output_data

    # qnn-net-run for VAE
    def run_vae(self, input_data):
        output_data = self.run_qnn_net_run(
            f"{self.models_context_path}\\VaeDecoderQuantizable_w8a16.bin",
            # f"{self.models_context_path}\\stable_diffusion_v1_5_quantized-vaedecoder_quantized-snapdragon_x_elite.bin",
            [input_data],
        )
        # Convert floating point output into 8 bits RGB image
        output_data = np.clip(output_data * 255.0, 0.0, 255.0).astype(np.uint8)
        # Output of VAE should be of shape (512, 512, 3)
        output_data = output_data.reshape((512, 512, 3))
        return output_data


class QnnStableDiffusionExecutor(LLMExecutor):
    model_name: str = "stable_diffusion_v1_5_quantized"
    qnn_binary_path: str = os.getcwd() + "\\qnn-binaries"
    model_path: str = "qualcomm/Stable-Diffusion-v1.5"

    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        """
        Override this method to add custom command-line arguments.
        """
        model_group = parser.add_argument_group("Model Options")
        model_group.add_argument(
            "--qnn_binaries",
            type=str,
            default=self.qnn_binary_path,
            help="Path to QNN binaries.",
        )
        model_group.add_argument(
            "--model",
            type=str,
            default=self.model_path,
            help="HF repository ID or path to model binaries.",
        )

        display_group = parser.add_argument_group("Display Options")
        display_group.add_argument(
            "--show_progress",
            action="store_true",
            help="Whether to show the progress of generation.",
        )

    def setup(self):
        if os.path.exists(self.args.model):
            self.model_path = self.args.model
        else:
            print(
                f"Model path {self.args.model} not found. Trying download it from HF Hub.",
                flush=True,
            )
            self.model_path = snapshot_download(repo_id=self.args.model)
        self.qnn_binary_path = self.args.qnn_binaries
        self.show_progress = self.args.show_progress
        self.stop = False

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        seed = modelfile.parameters["imgen_"].get("seed", np.int64(1.36477711e14))
        num_inference_steps = modelfile.parameters["imgen_"].get(
            "num_inference_steps", 20
        )
        guidance_scale = modelfile.parameters["imgen_"].get("guidance_scale", 7.5)
        self.stop = False

        img_url, history = extract_last_url(history)  # we omit img2img here
        prompt = next(i for i in reversed(history) if i["role"] == "user")["content"]
        logger.debug(f"Prompt: {prompt}")
        if not prompt:
            yield "Please enter prompt"
            return

        image_generator_app = QnnStableDiffusionApp(
            num_steps=num_inference_steps,
            seed=seed,
            guidance_scale=guidance_scale,
            qnn_binaries_path=self.qnn_binary_path,
            models_context_path=self.model_path,
        )
        generator = Generator(image_generator_app.generate_image(prompt=prompt))

        for progress in generator:
            if self.stop:
                break
            if self.show_progress:
                yield f"{progress} "

        yield "![{}]({})".format(prompt, image_to_data_url(generator.value))

        logger.info("Done")

    async def abort(self):
        self.stop = True
        logger.debug("aborted")
        return "Aborted"


if __name__ == "__main__":
    executor = QnnStableDiffusionExecutor()
    executor.run()
