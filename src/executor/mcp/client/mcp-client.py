import os
import sys
import asyncio
import logging
import json
import re
import shlex
from typing import Optional, Any
from contextlib import AsyncExitStack
from textwrap import dedent
from functools import lru_cache

from async_lru import alru_cache
from mcp import ClientSession, StdioServerParameters
from mcp.types import TextContent, CallToolResult
from mcp.client.stdio import stdio_client
from mcp.client.streamable_http import streamablehttp_client

sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from skyscope.executor import LLMExecutor, Modelfile
from skyscope.executor.message import ExitCodeChunk

logger = logging.getLogger(__name__)


class Server:
    """Manages MCP server connections and tool execution."""

    def __init__(self, name: str) -> None:
        self.name: str = name
        self.session: ClientSession | None = None
        self._cleanup_lock: asyncio.Lock = asyncio.Lock()
        self.exit_stack: AsyncExitStack = AsyncExitStack()

    async def init_stdio(self, command: str, cmd_args: str) -> None:
        self.command: str = command
        self.cmd_args: str = shlex.split(cmd_args)
        self.stdio_context: Any | None = None
        self.url = None
        try:
            server_params = StdioServerParameters(
                command=self.command, args=self.cmd_args, env=None
            )
            stdio_transport = await self.exit_stack.enter_async_context(
                stdio_client(server_params)
            )
            read_stream, write_stream = stdio_transport
            await self._init_session(read_stream, write_stream)
        except Exception as e:
            logger.exception(f"Error initializing STDIO server {self.name}")
            await self.cleanup()
            raise

    async def init_sse(self, url: str) -> None:
        self.url: str = url
        try:
            sse_transport= await self.exit_stack.enter_async_context(
                streamablehttp_client(self.url)
            )
            read_stream, write_stream, _ = sse_transport
            await self._init_session(read_stream, write_stream)
        except Exception as e:
            logger.exception(f"Error initializing SSE server {self.name}")
            await self.cleanup()
            raise

    async def _init_session(self, read_stream, write_stream) -> None:
        """Initialize the server connection."""
        session = await self.exit_stack.enter_async_context(
            ClientSession(read_stream, write_stream)
        )
        await session.initialize()
        self.session = session

    @alru_cache
    async def list_tools(self) -> list[Any]:
        """List available tools from the server.

        Returns:
            A list of available tools.

        Raises:
            RuntimeError: If the server is not initialized.
        """
        if not self.session:
            raise RuntimeError(f"Server {self.name} not initialized")

        tools_response = await self.session.list_tools()
        tools = []

        for item in tools_response:
            if isinstance(item, tuple) and item[0] == "tools":
                tools.extend(
                    Tool(tool.name, tool.description, tool.inputSchema)
                    for tool in item[1]
                )

        return tools

    async def execute_tool(
        self,
        tool_name: str,
        arguments: dict[str, Any],
        retries: int = 2,
        delay: float = 1.0,
    ) -> Any:
        """Execute a tool with retry mechanism.

        Args:
            tool_name: Name of the tool to execute.
            arguments: Tool arguments.
            retries: Number of retry attempts.
            delay: Delay between retries in seconds.

        Returns:
            Tool execution result.

        Raises:
            RuntimeError: If server is not initialized.
            Exception: If tool execution fails after all retries.
        """
        if not self.session:
            raise RuntimeError(f"Server {self.name} not initialized")

        attempt = 0
        while attempt < retries:
            try:
                logger.info(f"Executing {tool_name}...")
                result = await self.session.call_tool(tool_name, arguments)

                return result

            except Exception as e:
                attempt += 1
                logger.warning(
                    f"Error executing tool: {e}. Attempt {attempt} of {retries}."
                )
                if attempt < retries:
                    logger.info(f"Retrying in {delay} seconds...")
                    await asyncio.sleep(delay)
                else:
                    logger.error("Max retries reached. Failing.")
                    raise RuntimeError("Max retries reached. Failed to executor tool.")

    async def cleanup(self) -> None:
        """Clean up server resources."""
        async with self._cleanup_lock:
            try:
                await self.exit_stack.aclose()
                self.session = None
                self.stdio_context = None
            except Exception:
                logger.exception(f"Error during cleanup of server {self.name}")


class Tool:
    """Represents a tool with its properties and formatting."""

    def __init__(
        self, name: str, description: str, input_schema: dict[str, Any]
    ) -> None:
        self.name: str = name
        self.description: str = description
        self.input_schema: dict[str, Any] = input_schema

    def format_for_llm(self) -> str:
        """Format tool information for LLM.

        Returns:
            A formatted string describing the tool.
        """
        args_desc = []
        if "properties" in self.input_schema:
            for param_name, param_info in self.input_schema["properties"].items():
                arg_desc = (
                    f"- {param_name}: {param_info.get('description', 'No description')}"
                )
                if param_name in self.input_schema.get("required", []):
                    arg_desc += " (required)"
                args_desc.append(arg_desc)

        return dedent("""\
                      Tool: {name}
                      Description: {description}
                      Arguments:
                      {args}""").format(
            name=self.name, description=self.description, args="\n".join(args_desc)
        )


def RawJSONDecoder(index):
    class _RawJSONDecoder(json.JSONDecoder):
        end = None

        def decode(self, s, *_):
            data, self.__class__.end = self.raw_decode(s, index)
            return data

    return _RawJSONDecoder


def extract_json(s, index=0):
    """
    Extract json object from string with mixed content.
    Ref: https://stackoverflow.com/a/55525704
    """
    while (index := s.find("{", index)) != -1:
        try:
            yield json.loads(s, cls=(decoder := RawJSONDecoder(index)))
            index = decoder.end
        except json.JSONDecodeError:
            index += 1


class McpClientExecutor(LLMExecutor):
    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        """
        Override this method to add custom command-line arguments.
        """
        parser.add_argument(
            "--mcp_server_cmd",
            default="",
            help="Command of the MCP server.",
        )
        parser.add_argument(
            "--mcp_server_args",
            default="",
            help="Command arguments of the MCP server.",
        )
        parser.add_argument(
            "--mcp_server_url",
            default=None,
            help="URL of the MCP server.",
        )

    def setup(self):
        self.stop = False

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        server_cmd = modelfile.parameters["mcp_"].get("cmd", self.args.mcp_server_cmd)
        server_args = modelfile.parameters["mcp_"].get(
            "args", self.args.mcp_server_args
        )
        server_url = modelfile.parameters["mcp_"].get(
            "url", self.args.mcp_server_url
        )
        server_name = modelfile.parameters["mcp_"].get("server_name", "default_server")
        server = Server(name=server_name)
        is_bypassed = False
        try:
            try:
                if server_url is not None:
                    await server.init_sse(url=server_url)
                else:
                    await server.init_stdio(command=server_cmd, cmd_args=server_args)
            except Exception:
                logger.exception("Failed to initialize MCP server.")
                raise
            user_query = history[-1]["content"].strip()
            list_cmd = "/list"
            if user_query.startswith(list_cmd):
                user_query = user_query[len(list_cmd) :].strip()
                async for c in self.list_tool(server, user_query):
                    yield c
                return

            tool_call = self.parse_tool_call(user_query)
            logger.debug(f"Parsed tool_call: {tool_call}")
            if tool_call is None:
                yield user_query
                is_bypassed = True
                return

            async for c in self.exec_tool(server, tool_call):
                yield c

        except Exception:
            raise
        finally:
            # Ues exit code to direct Agent workflow
            exit_code = (
                ExitCodeChunk.COMPLETE if is_bypassed else ExitCodeChunk.INCOMPLETE
            )
            logger.debug(f"Exit code: {exit_code}")
            yield ExitCodeChunk(exit_code=exit_code)
            await server.cleanup()
            logger.debug("finished")

    async def list_tool(self, server, user_query):
        tools = await server.list_tools()
        for tool in tools:
            yield tool.format_for_llm() + "\n\n"
        if user_query != "":
            yield user_query

    async def exec_tool(self, server, tool_call):
        tools = await server.list_tools()
        if not any(tool.name == tool_call["tool"] for tool in tools):
            raise Exception(
                f'No tool named {tool_call["tool"]} found in server. Use "/list" to list available tools.'
            )
        logger.info(f"Executing tool: {tool_call['tool']}")
        logger.info(f"With arguments: {tool_call['arguments']}")
        try:
            result = await server.execute_tool(
                tool_call["tool"], tool_call["arguments"]
            )

            if isinstance(result, dict) and "progress" in result:
                progress = result["progress"]
                total = result["total"]
                percentage = (progress / total) * 100
                logger.info(f"Progress: {progress}/{total} ({percentage:.1f}%)")
            logger.debug(type(result))
            if isinstance(result, CallToolResult):
                result = "\n".join(
                    [c.text for c in result.content if isinstance(c, TextContent)]
                )

            logger.info(f"Tool execution result: {result}")
            yield f"Tool execution result: {result}"
        except Exception as e:
            error_msg = f"Error executing tool: {str(e)}"
            logger.exception(error_msg)
            yield error_msg

    def parse_tool_call(self, query: str):
        tool_call = None
        try:
            json_candidates = list(extract_json(query))
            if len(json_candidates) == 0:
                raise RuntimeError("There's not JSON string in user's query.")
            parsed_json = json_candidates[-1]
            tool_call = {
                "tool": parsed_json.get("tool", parsed_json.get("name")),
                "arguments": parsed_json.get("arguments", parsed_json.get("args")),
            }
            if type(tool_call.get("arguments")) is str:
                tool_call["arguments"] = json.loads(tool_call["arguments"])
        except Exception:
            pass
            # """
            # Parses a command string like 'get_weather --location="Paris, France"'
            # into a dictionary.
            # """

            # match = re.match(r"(\w+)(.*)", query)

            # if not match:
            #     return None

            # tool = match.group(1)
            # arguments_string = match.group(2).strip()

            # # Parse arguments.  We'll assume arguments are in the format --key="value"
            # arguments = {}
            # for arg_match in re.findall(r"--(\w+)=\"([^\"]+)\"", arguments_string):
            #     key = arg_match[0]
            #     value = arg_match[1]
            #     arguments[key] = value

            # tool_call = {"tool": tool, "arguments": arguments}
        finally:
            return tool_call

    async def abort(self):
        self.stop = True
        logger.debug("aborted")
        return "Aborted"


if __name__ == "__main__":
    executor = McpClientExecutor()
    executor.run()
