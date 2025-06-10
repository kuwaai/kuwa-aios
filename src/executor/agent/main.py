import os
import sys
import logging
import asyncio
import i18n
import pydantic
from typing import List, Dict
from httpx import HTTPStatusError
from enum import Enum

sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from skyscope.executor import LLMExecutor, Modelfile
from skyscope.executor.modelfile import Script
from skyscope.client import SkyscopeClient
from skyscope.client.base import StopAsyncGenerator

logger = logging.getLogger(__name__)

class AgentState(Enum):
    IDLE = 0
    RUNNING = 1
    ABORTING = 2


class BotNode(pydantic.BaseModel):
    bot_name: str
    prompt_prefix: str
    prompt_suffix: str
    append_history: bool

class IdentityBotNode(pydantic.BaseModel):
    pass

class FlowControlNode(pydantic.BaseModel):
    next_index_on_zero: int
    next_index_on_nonzero: int

def parse_flow(
    modelfile: Modelfile,
    append_history: bool,
    default_bot=".bot/copycat",
):
    if modelfile.input_bot is None:
        logger.info('No "INPUT-BOT" instruction found in the Botfile.')
    if modelfile.process_bot is None:
        logger.info('No "PROCESS-BOT" or "FROM" instruction found in the Botfile.')
    if modelfile.output_bot is None:
        logger.info('No "OUTPUT-BOT" instruction found in the Botfile.')

    bot_list = {
        Script.INPUT_BOT_SYMBOL: modelfile.input_bot,
        Script.PROCESS_BOT_SYMBOL: modelfile.process_bot,
        Script.OUTPUT_BOT_SYMBOL: modelfile.output_bot
    }
    prefix_list = {
        Script.INPUT_BOT_SYMBOL: modelfile.input_prefix,
        Script.PROCESS_BOT_SYMBOL: modelfile.before_prompt,
        Script.OUTPUT_BOT_SYMBOL: modelfile.output_prefix,
    }
    suffix_list = {
        Script.INPUT_BOT_SYMBOL: modelfile.input_suffix,
        Script.PROCESS_BOT_SYMBOL: modelfile.after_prompt,
        Script.OUTPUT_BOT_SYMBOL: modelfile.output_suffix,
    }
    script = modelfile.script
    
    # Match the parentheses
    matched_parentheses_index = {}
    forward_jumps = []
    for index, command_symbol in enumerate(script):
        if command_symbol == Script.CONDITIONAL_FORWARD_JUMP_SYMBOL:
            forward_jumps.append(index)
        if command_symbol == Script.CONDITIONAL_BACKWARD_JUMP_SYMBOL:
            matched_forward_jump = forward_jumps.pop()
            if matched_forward_jump == index+1:
                raise RuntimeError("Infinity loop detected.")
            matched_parentheses_index[index] = matched_forward_jump
            matched_parentheses_index[matched_forward_jump] = index
    
    flow = []
    for index, command_symbol in enumerate(script):
        if command_symbol in (Script.INPUT_BOT_SYMBOL, Script.PROCESS_BOT_SYMBOL, Script.OUTPUT_BOT_SYMBOL):
            if bot_list[command_symbol] is None:
                continue
            flow.append(
                BotNode(
                    bot_name=str(bot_list[command_symbol]),
                    prompt_prefix=str(prefix_list[command_symbol] or ""),
                    prompt_suffix=str(suffix_list[command_symbol] or ""),
                    append_history=append_history,
                )
            )
        if command_symbol == Script.IDENTITY_BOT_SYMBOL:
            flow.append(IdentityBotNode)
        
        if command_symbol == Script.CONDITIONAL_FORWARD_JUMP_SYMBOL:
            flow.append(FlowControlNode(
                next_index_on_zero=matched_parentheses_index[index]+1,
                next_index_on_nonzero=index+1,
            ))
            
        if command_symbol == Script.CONDITIONAL_BACKWARD_JUMP_SYMBOL:
            flow.append(FlowControlNode(
                next_index_on_zero=index+1,
                next_index_on_nonzero=matched_parentheses_index[index]+1,
            ))

    if len(flow) == 0:
        flow.append(
            BotNode(
                bot_name=str(default_bot),
                prompt_prefix="",
                prompt_suffix="",
            )
        )
    logger.debug(f"Parsed flow: {flow}")
    return flow

class AgentRunner:
    api_base_url: str = ""
    kernel_url: str = ""
    api_key: str = ""
    state: AgentState = AgentState.IDLE

    def __init__(self, api_base_url, kernel_url, api_key):
        self.api_base_url = api_base_url
        self.kernel_url = kernel_url
        self.api_key = api_key
        self.state = AgentState.IDLE

    async def _invoke_bot(self, bot_name, history):
        client = SkyscopeClient(
            base_url=self.api_base_url,
            kernel_base_url=self.kernel_url,
            model=bot_name,
            auth_token=self.api_key,
        )

        try:
            generator = client.chat_complete_with_exit_code(messages=history)
            async for chunk in generator:
                if self.state == AgentState.ABORTING:
                    await client.abort()
                    self.state = AgentState.IDLE
                    return
                yield chunk
        except StopAsyncGenerator as e:
            logger.debug(f"Exit code: {e.value}")
            raise
        except HTTPStatusError as e:
            if e.response.status_code == 404:
                yield i18n.t("agent.bot_not_found") + bot_name
                return
            else:
                raise

    async def run_flow(
        self, history: List[Dict], flow: List[BotNode], show_step_log: bool = False, max_steps = 10
    ):
        self.state = AgentState.RUNNING

        memory = history.copy()
        response = ""
        exit_code = 0
        index = 0
        step_count = 0
        while index < len(flow):
            node = flow[index]
            index += 1
            if isinstance(node, IdentityBotNode):
                exit_code = 0
                continue
            if isinstance(node, FlowControlNode):
                if exit_code == 0:
                    index = node.next_index_on_zero
                else:
                    index = node.next_index_on_nonzero
                continue

            # Normal BotNode
            assert(isinstance(node, BotNode))

            step_count += 1
            if step_count > max_steps:
                yield "Max step limit exceeded. Abort the workflow."
                break

            if show_step_log:
                yield f"---[Step {step_count}]---\n\n"

            prompt = node.prompt_prefix + memory[-1]["content"] + node.prompt_suffix
            memory[-1]["content"] = prompt
            generator = self._invoke_bot(
                bot_name=node.bot_name,
                history=memory,
            )
            response = ""
            try:
                async for chunk in generator:
                    response += chunk
                    if show_step_log:
                        yield chunk
            except StopAsyncGenerator as e:
                exit_code = e.value
            
            if self.state != AgentState.RUNNING:
                return

            response_record = {"role": "user", "content": response}
            if node.append_history:
                # Invert user and assistant
                memory = [
                    dict(
                        i,
                        role=dict(user="assistant", assistant="user").get(
                            i["role"], i["role"]
                        ),
                    )
                    for i in memory
                ]
                memory.append(response_record)
            else:
                memory = [response_record]
            
            if show_step_log:
                yield "\n"

        if not show_step_log:
            yield response

    async def abort(self):
        self.state = AgentState.ABORTING

        while self.state == AgentState.IDLE:
            asyncio.sleep(0.5)


class AgentExecutor(LLMExecutor):
    runner: AgentRunner | None = None

    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        """
        Override this method to add custom command-line arguments.
        """
        parser.add_argument(
            "--api_base_url",
            default="http://127.0.0.1/",
            help="The API base URL of Skyscope multi-chat WebUI",
        )
        parser.add_argument(
            "--api_key",
            default=None,
            help="The API authentication token of Skyscope multi-chat WebUI",
        )

    def setup(self):
        i18n.load_path.append("lang/")
        self.state = AgentState.IDLE

    def setup_i18n(self, lang):
        i18n.config.set("error_on_missing_translation", True)
        i18n.config.set("fallback", "en")
        i18n.config.set("locale", lang)

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        self.setup_i18n(modelfile.parameters["_lang"])
        api_key = modelfile.parameters.get("_user_token", self.args.api_key)
        if api_key is None:
            yield i18n.t("agent.no_skyscope_api_key")
        api_base_url = modelfile.parameters.get(
            "_skyscope_api_base_urls", [self.args.api_base_url]
        )[0]
        show_step_log = modelfile.parameters["agent_"].get("show_step_log", False)
        append_history = modelfile.parameters["agent_"].get("append_history", False)

        flow = parse_flow(
            modelfile=modelfile,
            append_history=append_history,
        )
        if len(flow) == 0:
            yield i18n.t("agent.no_input_bot")
            return

        self.runner = AgentRunner(
            api_base_url=api_base_url, kernel_url=self.kernel_url, api_key=api_key
        )
        try:
            generator = self.runner.run_flow(
                history=history,
                flow=flow,
                show_step_log=show_step_log,
            )
            async for chunk in generator:
                yield chunk
        finally:
            self.runner = None

    async def abort(self):
        if self.runner is not None:
            await self.runner.abort()
            self.runner = None
        logger.debug("aborted")
        return "Aborted"


if __name__ == "__main__":
    executor = AgentExecutor()
    executor.run()
