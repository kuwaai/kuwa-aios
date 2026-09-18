# This module defines the Modelfile and Script classes for configuring bot behavior.
# It provides functionality to parse configuration scripts, handle command extraction, 
# validate script syntax, and manage bot parameters.

from __future__ import annotations

import re
import json
import logging
from dataclasses import dataclass, field
from collections import Counter

# logger: Logger instance for this module.
logger = logging.getLogger(__name__)


def convert_value(value):
    """
    Converts a string value to its appropriate Python type (int, float, bool, or None).

    Input Parameters:
        value (str): The string value to convert.

    Return Value:
        The converted value in the determined type.
    """
    # precedence: List of numeric types to try for conversion.
    precedence = [int, float]
    # converted_v: Variable to hold the result of conversion.
    converted_v = None
    
    # Try numeric conversion first
    for target_type in precedence:
        try:
            converted_v = target_type(value)
            break
        except ValueError:
            pass
    
    # Handle boolean and None literals if numeric conversion failed
    if converted_v is None and value is not None:
        match value.lower():
            case "true":
                converted_v = True
            case "false":
                converted_v = False
            case "none":
                converted_v = None
            case _:
                # Keep as string if no other type matches
                converted_v = value
    return converted_v


def extract_text_from_quotes(text):
    """
    Extracts text from a string enclosed in various types of quotes.

    Purpose:
        Clean input strings by removing surrounding quotes (single, double, or triple).

    Input Parameters:
        text (str): The potentially quoted input string.

    Return Value:
        str: The cleaned string content.
    """
    text = text.strip()
    # match: Regex search for content within matching quotes.
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


def discard_comments(text_string):
    """
    Removes comments (starting with '#') from a string while respecting quotes.

    Purpose:
        Strip comments from configuration lines without breaking hash symbols inside strings.

    Input Parameters:
        text_string (str): The raw configuration line.

    Return Value:
        str: The line with comments removed.
    """
    # result: List to accumulate characters of the cleaned string.
    result = []
    # in_double_quotes: Flag tracking if the parser is currently inside double quotes.
    in_double_quotes = False
    # in_single_quotes: Flag tracking if the parser is currently inside single quotes.
    in_single_quotes = False

    i = 0
    # Iterate through the string character by character
    while i < len(text_string):
        char = text_string[i]

        # Toggle quote state flags
        if char == '"' and not in_single_quotes:
            in_double_quotes = not in_double_quotes
            result.append(char)
        elif char == "'" and not in_double_quotes:
            in_single_quotes = not in_single_quotes
            result.append(char)
        elif char == "#" and not in_double_quotes and not in_single_quotes:
            # Found a comment outside of quotes, ignore the rest of the string
            break
        else:
            result.append(char)
        i += 1

    return "".join(result).strip()


class ParameterDict(dict):
    """
    A dictionary subclass that supports prefix-based key lookup.
    """
    def __missing__(self, key):
        """
        Retrieves a sub-dictionary of keys that start with the missing key as a prefix.

        Missing config groups such as ``llm.`` / ``llm_`` / ``memory.`` are
        treated as empty configuration blocks instead of aborting execution.
        This matches the normal config semantics: a bot without a specific
        sub-group simply contributes no values to that merged block.
        """
        prefix_dict = {k[len(key) :]: v for k, v in self.items() if k.startswith(key)}
        if prefix_dict:
            return prefix_dict
        if key.endswith((".", "_")):
            return {}
        raise KeyError(key)

    def get(self, key, default=None):
        """Support prefix-based lookup for config parameters like retriever.enable."""
        try:
            return self[key]
        except KeyError:
            return default


class ScriptSyntaxError(BaseException):
    """
    Custom exception for errors found in script syntax.
    """
    # message: Descriptive error message.
    message = ""

    def __init__(self, message):
        self.message = message


class Script:
    """
    Utility class for handling and validating bot execution scripts.
    """
    # VERSION_MAGIC: The expected prefix for script versioning.
    VERSION_MAGIC = "000"
    # INPUT_BOT_SYMBOL: Symbol representing the input stage.
    INPUT_BOT_SYMBOL = "I"
    # PROCESS_BOT_SYMBOL: Symbol representing the processing stage.
    PROCESS_BOT_SYMBOL = "P"
    # OUTPUT_BOT_SYMBOL: Symbol representing the output stage.
    OUTPUT_BOT_SYMBOL = "O"
    # IDENTITY_BOT_SYMBOL: Symbol for the identity operation.
    IDENTITY_BOT_SYMBOL = ";"
    # CONDITIONAL_FORWARD_JUMP_SYMBOL: Symbol for starting a conditional block.
    CONDITIONAL_FORWARD_JUMP_SYMBOL = "["
    # CONDITIONAL_BACKWARD_JUMP_SYMBOL: Symbol for ending a conditional block.
    CONDITIONAL_BACKWARD_JUMP_SYMBOL = "]"
    
    # VALID_SYMBOLS: Set of all characters allowed in a script.
    VALID_SYMBOLS = {
        INPUT_BOT_SYMBOL,
        PROCESS_BOT_SYMBOL,
        OUTPUT_BOT_SYMBOL,
        IDENTITY_BOT_SYMBOL,
        CONDITIONAL_FORWARD_JUMP_SYMBOL,
        CONDITIONAL_BACKWARD_JUMP_SYMBOL,
    }
    
    # DEFAULT_CONTENT: The standard script sequence.
    DEFAULT_CONTENT = INPUT_BOT_SYMBOL + PROCESS_BOT_SYMBOL + OUTPUT_BOT_SYMBOL
    # DEFAULT: The full default script with version magic.
    DEFAULT = f"000{DEFAULT_CONTENT}"

    @staticmethod
    def validate_syntax(script: str) -> bool:
        """
        Validates the syntax and structure of a script.

        Input Parameters:
            script (str): The script string to validate.

        Return Value:
            bool: True if syntax is valid, False otherwise.
        """
        script = script.strip()
        try:
            if not isinstance(script, str):
                raise ScriptSyntaxError("Type of script is not string.")

            # Validate version magic
            version_magic = script[: len(Script.VERSION_MAGIC)]
            if version_magic != Script.VERSION_MAGIC:
                raise ScriptSyntaxError(
                    f"Script version mismatch. Except {Script.VERSION_MAGIC}, got {version_magic}"
                )

            # Validate characters
            content = script[len(Script.VERSION_MAGIC) :]
            if len(set(content).difference(Script.VALID_SYMBOLS)) != 0:
                raise ScriptSyntaxError(
                    f"Got unexpected symbol in script. Valid symbols are: {Script.VALID_SYMBOLS}"
                )

            # Validate bracket matching
            count = Counter(content)
            if (
                count[Script.CONDITIONAL_FORWARD_JUMP_SYMBOL]
                != count[Script.CONDITIONAL_BACKWARD_JUMP_SYMBOL]
            ):
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
        """
        Extracts the content from a validated script (removes version magic).
        """
        script = script.strip()
        if not Script.validate_syntax(script):
            logger.error("Error parsing script.")
            return None
        return script[len(Script.VERSION_MAGIC) :]


@dataclass
class Modelfile:
    """
    Represents the parsed configuration for a bot.
    Includes prompts, message history, templates, and execution scripts.
    """
    # override_system_prompt: Optional custom system message.
    override_system_prompt: str = ""
    # messages: Pre-defined conversation history.
    messages: list[dict] = field(default_factory=list)
    # template: UI or formatting template.
    template: str = ""
    # before_prompt: Content to inject before user input.
    before_prompt: str = ""
    # after_prompt: Content to inject after user input.
    after_prompt: str = ""
    # process_bot: ID of the primary processing bot.
    process_bot: str = None
    # input_bot: ID of the bot used for input pre-processing.
    input_bot: str = None
    # input_prefix: String to prepend to user input.
    input_prefix: str = ""
    # input_suffix: String to append to user input.
    input_suffix: str = ""
    # output_bot: ID of the bot used for output post-processing.
    output_bot: str = None
    # output_prefix: String to prepend to bot output.
    output_prefix: str = ""
    # output_suffix: String to append to bot output.
    output_suffix: str = ""
    # script: Execution flow script.
    script: str = Script.DEFAULT_CONTENT
    # parameters: Custom key-value parameters for bot configuration.
    parameters: ParameterDict = field(default_factory=ParameterDict)

    @staticmethod
    def append_command(name, args, modelfile: Modelfile):
        """
        Applies a command from the modelfile to the current Modelfile object.

        Input Parameters:
            name (str): The command name (e.g., 'system', 'parameter').
            args (str): The command arguments.
            modelfile (Modelfile): The object to update.

        Return Value:
            Modelfile: The updated object.

        Calling Relations:
            Called by from_json.
        """
        # single_arg_cmd: Commands that expect a single quoted string argument.
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

        # Apply specific command logic
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
                # Handle multi-argument 'message' command
                role, content = [
                    extract_text_from_quotes(x) for x in args.split(" ", 1)
                ]
                if role in ["user", "assistant"]:
                    modelfile.messages += [{"content": content, "role": role}]
                elif role == "system":
                    modelfile.override_system_prompt += content
                else:
                    logger.debug(f"Unsupported role: {role}")

            case "parameter" | "kuwaparam":
                # Handle key-value parameters
                key, value = [extract_text_from_quotes(x) for x in args.split(" ", 1)]
                modelfile.parameters[key] = convert_value(value)

            case "input-bot":
                modelfile.input_bot = args
            case "output-bot":
                modelfile.output_bot = args
            case "from" | "process-bot":
                modelfile.process_bot = args

            case "script":
                # Parse and validate the script content
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
        """
        Parses a JSON representation of a modelfile into a Modelfile object.

        Input Parameters:
            raw_modelfile (str): JSON string containing command list.

        Return Value:
            Modelfile: The populated object.

        Calling Relations:
            Called by LLMExecutor.serve.
        """
        # Load raw commands
        # Some callers forward an empty string instead of omitting the field
        # (e.g. a botfile override that resolved to ""), which would otherwise
        # blow up json.loads with "Expecting value: line 1 column 1 (char 0)".
        commands = json.loads(raw_modelfile) if raw_modelfile else []
        if not commands:
            commands = []
        
        # Initialize an empty Modelfile
        parsed_modelfile = cls(
            override_system_prompt="",
            before_prompt="",
            after_prompt="",
            messages=[],
            template="",
            parameters=ParameterDict(),
        )

        # Process each command in sequence
        for command in commands:
            try:
                name = command["name"]
                args = command["args"]
                
                # Check for comment-only lines
                comment_prefix = "#"
                if comment_prefix in name:
                    name = discard_comments(name)
                    args = ""
                else:
                    # Strip comments from arguments
                    args = discard_comments(args)

                # Update the modelfile object
                parsed_modelfile = Modelfile.append_command(
                    name, args, parsed_modelfile
                )
            except Exception as e:
                logger.exception(f"Error in modelfile command `{command}`: `{e}`")

        return parsed_modelfile

    def __init__(self, **kwargs):
        """
        Standard constructor supporting keyword arguments for field initialization.
        """
        for key, value in kwargs.items():
            setattr(self, key, value)
