from __future__ import annotations

import re
import json
import logging
from dataclasses import dataclass, field
from collections import Counter

logger = logging.getLogger(__name__)


def convert_value(value):
    precedence = [int, float]
    converted_v = None
    for target_type in precedence:
        try:
            converted_v = target_type(value)
            break
        except ValueError:
            pass
    if converted_v is None and value is not None:
        match value.lower():
            case "true":
                converted_v = True
            case "false":
                converted_v = False
            case "none":
                converted_v = None
            case _:
                converted_v = value
    return converted_v


def extract_text_from_quotes(text):
    """
    Extracts text from a string enclosed in single ('), double ("), or triple (''' \""") quotes,
    handling escaped quotes and nested quotes of different types.

    Args:
        text: The input string.

    Returns:
        The extracted text without the surrounding quotes, or None if no quoted text is found.
    """

    text = text.strip()
    match = re.search(
        r"""
        # Match single, double, or triple quotes 
        ^(\"\"\"|\'|\")
        # Capture the text inside the quotes (non-greedy)
        (.*?)
        # Match the same type of quote from the beginning
        \1$
    """,
        text,
        re.DOTALL | re.VERBOSE,
    )

    if match:
        return match.group(2)
    else:
        return text.strip()


class ParameterDict(dict):
    def __missing__(self, key):
        """
        Return a sub-dictionary which has common-prefix in key if not exact match.
        """
        prefix_dict = {k[len(key) :]: v for k, v in self.items() if k.startswith(key)}
        return prefix_dict


class ScriptSyntaxError(BaseException):
    message = ""

    def __init__(self, message):
        self.message = message


class Script:
    VERSION_MAGIC = "000"
    INPUT_BOT_SYMBOL = "I"
    PROCESS_BOT_SYMBOL = "P"
    OUTPUT_BOT_SYMBOL = "O"
    IDENTITY_BOT_SYMBOL = ";"
    CONDITIONAL_FORWARD_JUMP_SYMBOL = "["
    CONDITIONAL_BACKWARD_JUMP_SYMBOL = "]"
    VALID_SYMBOLS = {
        INPUT_BOT_SYMBOL,
        PROCESS_BOT_SYMBOL,
        OUTPUT_BOT_SYMBOL,
        IDENTITY_BOT_SYMBOL,
        CONDITIONAL_FORWARD_JUMP_SYMBOL,
        CONDITIONAL_BACKWARD_JUMP_SYMBOL,
    }
    DEFAULT_CONTENT = INPUT_BOT_SYMBOL + PROCESS_BOT_SYMBOL + OUTPUT_BOT_SYMBOL
    DEFAULT = f"000{DEFAULT_CONTENT}"

    @staticmethod
    def validate_syntax(script: str) -> bool:
        """
        Validate the syntax of the given script.
        """
        script = script.strip()
        try:
            if not isinstance(script, str):
                raise ScriptSyntaxError("Type of script is not string.")

            version_magic = script[: len(Script.VERSION_MAGIC)]
            if version_magic != Script.VERSION_MAGIC:
                raise ScriptSyntaxError(
                    f"Script version mismatch. Except {Script.VERSION_MAGIC}, got {version_magic}"
                )

            script = script[len(Script.VERSION_MAGIC) :]
            if len(set(script).difference(Script.VALID_SYMBOLS)) != 0:
                raise ScriptSyntaxError(
                    f"Got unexpected symbol in script. Valid symbols are: {Script.VALID_SYMBOLS}"
                )

            count = Counter(script)
            if count[Script.CONDITIONAL_FORWARD_JUMP_SYMBOL] != count[Script.CONDITIONAL_BACKWARD_JUMP_SYMBOL]:
                raise ScriptSyntaxError("Unmatched parentheses")

            return True
        except ScriptSyntaxError as e:
            logger.debug(f"Script syntax error: {e.message}")
            return False
        except Exception:
            logger.exception("Unknown error occur when parsing script.")
            return False

    @staticmethod
    def get_content(script: str):
        script = script.strip()
        if not Script.validate_syntax(script):
            logger.error("Error parsing script.")
            return None
        return script[len(Script.VERSION_MAGIC) :]


@dataclass
class Modelfile:
    override_system_prompt: str = None
    messages: list[dict] = field(default_factory=list)
    template: str = None
    before_prompt: str = None
    after_prompt: str = None
    process_bot: str = None
    input_bot: str = None
    input_prefix: str = ""
    input_suffix: str = ""
    output_bot: str = None
    output_prefix: str = ""
    output_suffix: str = ""
    script: str = Script.DEFAULT_CONTENT
    parameters: ParameterDict = field(default_factory=ParameterDict)

    @staticmethod
    def append_command(name, args, modelfile: Modelfile):
        single_arg_cmd = (
            "from",
            "process-bot",
            "system",
            "template",
            "before-prompt",
            "after-prompt",
            "input-bot",
            "input-prefix",
            "input-suffix",
            "output-bot",
            "output-prefix",
            "output-suffix",
            "script",
        )
        if name in single_arg_cmd:
            args = extract_text_from_quotes(args)

        match name:
            case "template":
                modelfile.template = args
            case "system":
                modelfile.override_system_prompt += args
            case "before-prompt":
                modelfile.before_prompt += args
            case "after-prompt":
                modelfile.after_prompt += args
            case "output-prefix":
                modelfile.output_prefix += args
            case "output-suffix":
                modelfile.output_suffix += args
            case "input-prefix":
                modelfile.input_prefix += args
            case "input-suffix":
                modelfile.input_suffix += args

            case "message":
                role, content = [
                    extract_text_from_quotes(x) for x in args.split(" ", 1)
                ]
                if role in ["user", "assistant"]:
                    modelfile.messages += [{"content": content, "role": role}]
                elif role == "system":
                    modelfile.override_system_prompt += content
                else:
                    logger.debug(f"{role} doesn't existed!!")

            case "parameter" | "skyscopeparam":
                key, value = [extract_text_from_quotes(x) for x in args.split(" ", 1)]
                modelfile.parameters[key] = convert_value(value)

            case "input-bot":
                modelfile.input_bot = args
            case "output-bot":
                modelfile.output_bot = args
            case "from" | "process-bot":
                modelfile.process_bot = args

            case "script":
                script_content = Script.get_content(args)
                modelfile.script = (
                    script_content
                    if script_content is not None
                    else Script.DEFAULT_CONTENT
                )

            case _:
                raise ValueError(f'Unknown command "{name}"')

        return modelfile

    @classmethod
    def from_json(cls, raw_modelfile: str):
        raw_modelfile = json.loads(raw_modelfile)
        if not raw_modelfile:
            raw_modelfile = []
        parsed_modelfile = cls(
            override_system_prompt="",
            before_prompt="",
            after_prompt="",
            messages=[],
            template="",
            parameters=ParameterDict(),
        )

        for command in raw_modelfile:
            try:
                name = command["name"]
                args = command["args"]
                # Filter out comments
                comment_prefix = "#"
                if comment_prefix in name:
                    args = ""
                args = args.split(comment_prefix)[0]

                parsed_modelfile = Modelfile.append_command(
                    name, args, parsed_modelfile
                )
            except Exception as e:
                logger.exception(f"Error in modelfile `{command}` with error: `{e}`")

        return parsed_modelfile

    def __init__(self, **kwargs):
        for key, value in kwargs.items():
            setattr(self, key, value)
