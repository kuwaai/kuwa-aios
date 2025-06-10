import os
import re
import sys
import json
import asyncio
import logging
import requests
import tempfile
import mimetypes
import torch
import concurrent.futures
import functools
import multiprocessing as mp
from whisper_s2t.backends.ctranslate2.model import BEST_ASR_CONFIG
from whisper_s2t.utils import format_timestamp

from skyscope.executor import LLMExecutor, Modelfile
from skyscope.executor.llm_executor import extract_last_url
from skyscope.executor.util import merge_config

sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from src.diarizer import PyannoteSpeakerDiarizer, Diary
from src.transcriber import WhisperS2tTranscriber

logger = logging.getLogger(__name__)


class SpeechRecognitionExecutor(LLMExecutor):
    transcribe_param: dict = {}
    param_prefix: str = "whisper_"
    default_model_name: str = "medium"
    default_model_backend: str = "CTranslate2"
    default_device: str = None
    default_compute_type: str = "int8"
    language: str = "en"
    batch_size: int = 24
    diar_thold_sec: float = 1.0
    enable_timestamp: bool = False
    enable_diarization: bool = False
    output_json: bool = False

    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        model_group = parser.add_argument_group("Model Options")
        model_group.add_argument(
            "--model", default=self.default_model_name, help="The model name."
        )
        model_group.add_argument(
            "--visible_gpu",
            default=None,
            help="Specify the GPU IDs that this executor can use. Separate by comma.",
        )
        model_group.add_argument(
            "--device",
            default=self.default_device,
            help="The device to load model. By default the system will automatically detect the capable device.",
        )
        model_group.add_argument(
            "--compute_type",
            default=self.default_compute_type,
            help="The compute data type of the model.",
        )
        model_group.add_argument(
            "--backend", default=self.default_model_backend, help="The model backend."
        )
        model_group.add_argument(
            "--use_onnx",
            action="store_true",
            help="Use ONNX as the backend instead of whisperS2T.",
        )
        model_group.add_argument(
            "--encoder_path",
            default="build/whisper_base/WhisperEncoderInf.onnx",
            help="The path to encoder. Only valid in ONNX backend.",
        )
        model_group.add_argument(
            "--decoder_path",
            default="build/whisper_base/WhisperDecoderInf.onnx",
            help="The path to decoder. Only valid in ONNX backend.",
        )
        model_group.add_argument(
            "--language", default=self.language, help="The language to transcribe."
        )
        model_group.add_argument(
            "--batch_size", default=self.batch_size, type=int, help="The batch size"
        )
        model_group.add_argument(
            "--enable_timestamp",
            action="store_true",
            help="Enable displaying the timestamp.",
        )
        model_group.add_argument(
            "--enable_diarization",
            action="store_true",
            help="Enable speaker diarization annotation.",
        )
        model_group.add_argument(
            "--diar_thold_sec",
            default=self.diar_thold_sec,
            type=float,
            help="The threshold of diarization in seconds.",
        )
        transcribe_group = parser.add_argument_group("Transcribe Options")
        for param, value in BEST_ASR_CONFIG.items():
            if type(value) not in (str, int, float, bool, type(None)):
                continue
            transcribe_group.add_argument(f"--{param}", default=value, type=type(value))

    def setup(self):
        if self.args.visible_gpu:
            os.environ["CUDA_VISIBLE_DEVICES"] = self.args.visible_gpu
            torch.cuda.device_count.cache_clear()

        os.environ["KMP_DUPLICATE_LIB_OK"] = "TRUE"
        self.default_model_name = self.args.model
        self.default_model_backend = self.args.backend
        self.language = self.args.language
        self.batch_size = self.args.batch_size
        self.diar_thold_sec = self.args.diar_thold_sec

        transcribe_param_arg = {
            k: getattr(self.args, k)
            for k in BEST_ASR_CONFIG.keys()
            if f"--{k}" in sys.argv
        }
        self.transcribe_param = merge_config(
            self.transcribe_param, transcribe_param_arg
        )
        self.transcribe_param["device"] = self.args.device
        self.transcribe_param["compute_type"] = self.args.compute_type
        self.enable_timestamp = self.args.enable_timestamp
        self.enable_diarization = self.args.enable_diarization

        self.stop = False

        # Initialize the pipelines
        if self.args.use_onnx:
            from src.onnx_backend.onnx_transcriber import OnnxTranscriber

            self.transcriber = OnnxTranscriber(
                encoder_path=self.args.encoder_path,
                decoder_path=self.args.decoder_path,
            )
        else:
            self.transcriber = WhisperS2tTranscriber()
        if not self.enable_diarization:
            self.diarizer = PyannoteSpeakerDiarizer()

    def download(self, url):
        # Create a temporary file to store the downloaded content
        filepath = None
        response = requests.get(url)
        content_type = response.headers["Content-Type"]
        logger.debug(f"Content-Type: {content_type}")
        extension = mimetypes.guess_extension(content_type.split(";", 1)[0])
        with tempfile.NamedTemporaryFile(delete=False, suffix=extension) as f:
            f.write(response.content)
            filepath = f.name
        return filepath

    def read_param_from_history(
        self, history: [dict], param: str, type=None, default=None
    ):
        def get_value(v):
            return type(v) if type is not None and v is not None else v

        result = []
        target = f"/{param}"
        for record in history:
            if record["role"] != "user":
                continue
            if record["content"].startswith(target):
                value = record["content"][len(target) :]
                result.append(get_value(value.strip()))
        return result if len(result) > 0 else [default]

    def split_args(self, string):
        if string is None:
            return []
        args = []
        current_arg = ""
        in_quotes = False
        for char in string:
            if char == " " and not in_quotes:
                if current_arg:
                    args.append(current_arg)
                    current_arg = ""
            elif char == '"':
                in_quotes = not in_quotes
            else:
                current_arg += char
        if current_arg:
            args.append(current_arg)
        return args

    async def transcribe(
        self,
        filepath,
        model_name: str,
        model_backend: str,
        lang: str,
        prompt: str = None,
        param={},
    ):
        logger.debug(f"Language code: {lang}")
        logger.debug(f"Model name (backend): {model_name} ({model_backend})")
        logger.debug(f"Prompt: {prompt}")
        logger.debug(f"Transcribe param: {param}")

        model_params = merge_config(BEST_ASR_CONFIG, param)

        # Execute in current process to avoid load the model again.
        loop = asyncio.get_event_loop()
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            job = functools.partial(
                self.transcriber.transcribe,
                model_name=model_name,
                model_backend=model_backend,
                model_params=model_params,
                audio_files=[filepath],
                lang_codes=[lang],
                tasks=["transcribe"],
                initial_prompts=[prompt],
                batch_size=self.batch_size,
            )
            result = await loop.run_in_executor(pool, job)

        return result[0]

    async def speaker_diarization(
        self, filepath: str, num_speakers: int, diar_thold_sec: float, device: str
    ):
        self.diarizer = (
            self.diarizer if hasattr(self, "diarizer") else PyannoteSpeakerDiarizer()
        )
        logger.debug(f"num_speakers={num_speakers}")
        logger.debug(f"diar_thold_sec={diar_thold_sec}")
        logger.debug(f"device={device}")

        loop = asyncio.get_event_loop()
        mp_context = mp.get_context(
            "spawn"
        )  # Torch with CUDA needs a "spawn" context to work
        with concurrent.futures.ProcessPoolExecutor(
            max_workers=1, mp_context=mp_context
        ) as pool:
            job = functools.partial(
                self.diarizer.diarization,
                src_audio_file=filepath,
                num_speakers=num_speakers,
                duration_thld_sec=diar_thold_sec,
                device=device,
            )
            result = await loop.run_in_executor(pool, job)

        return result

    def _format_output(self, segment: dict, output_timestamp: bool = False):
        start_sec = segment["start_time"]
        end_sec = segment["end_time"]
        text = segment["text"].strip()
        if text == "":
            return ""
        speaker = (
            "{}: ".format(", ".join(segment["speaker"])) if "speaker" in segment else ""
        )

        result = "{}{}\n".format(speaker, text)
        if output_timestamp:
            result = "[{} ---> {}] {}".format(
                format_timestamp(start_sec, always_include_hours=True),
                format_timestamp(end_sec, always_include_hours=True),
                result,
            )
        return result

    def _replace(self, message, history: [dict]) -> str:
        if message is None:
            return None
        replace_list = self.read_param_from_history(
            history=history, param="replace", type=str
        )
        replace_list = map(lambda x: self.split_args(x), replace_list)
        replace_list = filter(lambda x: len(x) == 2, replace_list)
        for target, replace in replace_list:
            message = re.sub(target, replace, message, flags=re.MULTILINE)

        return message

    def check_replace(self, history: [dict]) -> str:
        if len(history) < 2:
            return None
        if history[-1]["role"] != "user" or not history[-1]["content"].startswith(
            "/replace"
        ):
            return None
        assistant_output = list(filter(lambda x: x["role"] == "assistant", history))
        if len(assistant_output) == 0:
            return None

        return self._replace(message=assistant_output[-1]["content"], history=history)

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        src_file = None
        gc_paths = []
        try:
            self.stop = False
            url, history = extract_last_url(history)
            if url is None:
                raise ValueError("An URL to a audio file is expected.")

            if (replace_output := self.check_replace(history)) is not None:
                logger.debug(replace_output)
                yield replace_output
                return

            src_file = self.download(url)
            gc_paths.append(src_file)

            transcribe_param = modelfile.parameters[self.param_prefix]
            transcribe_param = merge_config(self.transcribe_param, transcribe_param)
            logger.debug(f"{transcribe_param}")

            # Extract parameters
            user_prompts = [
                i
                for i in history
                if i["role"] == "user" and not i["content"].startswith("/")
            ]
            prompt = "{system}{before}{user}{after}".format(
                system=modelfile.override_system_prompt,
                before=modelfile.before_prompt,
                user="" if len(user_prompts) == 0 else user_prompts[-1]["content"],
                after=modelfile.after_prompt,
            )
            model_name = transcribe_param.pop("model", self.default_model_name).strip()
            model_backend = transcribe_param.pop(
                "backend", self.default_model_backend
            ).strip()
            enable_timestamp = transcribe_param.pop(
                "enable_timestamp", self.enable_timestamp
            )
            enable_diarization = transcribe_param.pop(
                "enable_diarization", self.enable_diarization
            )
            lang = transcribe_param.pop("language", self.language).strip()
            num_speakers = self.read_param_from_history(
                history=history, param="speakers", type=int
            )[-1]
            diar_thold_sec = transcribe_param.pop("diar_thold_sec", self.diar_thold_sec)
            output_json = transcribe_param.pop("output_json", self.output_json)

            transcribe_param["word_timestamps"] = enable_diarization
            transcribe_job = self.transcribe(
                filepath=src_file,
                model_name=model_name,
                model_backend=model_backend,
                prompt=prompt,
                lang=lang,
                param=transcribe_param,
            )
            if not enable_diarization:
                result = await transcribe_job
            else:
                device = transcribe_param.get("device", None)
                diarization_job = self.speaker_diarization(
                    filepath=src_file,
                    num_speakers=num_speakers,
                    diar_thold_sec=diar_thold_sec,
                    device=device,
                )
                result, diary = await asyncio.gather(transcribe_job, diarization_job)
                result = [
                    {
                        "start_time": word["start"],
                        "end_time": word["end"],
                        "text": ("" if lang == "zh" else " ") + word["word"],
                    }
                    for segment in result
                    for word in segment["word_timestamps"]
                ]
                result = diary.annotate_transcript(result)

            logger.debug(f"Final Result: {result}")
            output = ""
            if output_json:
                filtered_result = [
                    {k: v for k, v in r.items() if k in Diary.segment_template.keys()}
                    for r in result
                ]
                output = json.dumps(filtered_result, ensure_ascii=False)
            else:
                output = "".join(
                    [self._format_output(i, enable_timestamp) for i in result]
                )
                output = self._replace(message=output, history=history)

            yield output

        except Exception as e:
            logger.exception("Error occurs during generation.")
            yield str(e)
        finally:
            self.stop = False
            for path_to_delete in gc_paths:
                if os.path.isfile(path_to_delete):
                    os.remove(path_to_delete)
                elif os.path.isdir(path_to_delete):
                    os.rmdir(path_to_delete)
            logger.debug("finished")

    async def abort(self):
        self.stop = True
        logger.debug("aborted")
        return "Aborted"


if __name__ == "__main__":
    executor = SpeechRecognitionExecutor()
    executor.run()
