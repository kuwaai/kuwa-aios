import os
import asyncio
import logging
import argparse
from kuwa.client import KuwaClient


logger = logging.getLogger(__name__)


# Usage (in PowerShell):
# cd "C:\kuwa\GenAI OS\src\library\client"
# pip install . # If you encounter error, refer to the section below.
# cd ..\..\..\scripts
# $env:KUWA_API_KEY="<your API key>"
# python kuwa_api_sample.py
#
# If you encounter error when installing Kuwa Client, try specify the package version.
# $env:SETUPTOOLS_SCM_PRETEND_VERSION="v0.4.0"


async def main(base_url: str, api_key: str, bot: str):
    client = KuwaClient(
        base_url=base_url,
        model=bot,
        auth_token=api_key,
    )

    user_prompt = input("> ")
    message = [{"role": "user", "content": user_prompt}]

    generator = client.chat_complete(messages=message, streaming=True)

    async for chunk in generator:
        print(chunk, end="", flush=True)

    print()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Test the Kuwa API.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument(
        "--base-url",
        default=os.environ.get("KUWA_API_BASE_URL", "http://127.0.0.1/v1.0/"),
        help="The custom base URL for the Kuwa API.",
    )
    parser.add_argument(
        "--api-key",
        default=os.environ.get("KUWA_API_KEY"),
        help="The API token for authentication with Kuwa.",
    )
    parser.add_argument("--bot", default=".bot/.default", help="The bot name to call.")
    parser.add_argument(
        "--log", type=str, default="INFO", help="the log level. (INFO, DEBUG, ...)"
    )
    args = parser.parse_args()

    # Setup logger
    numeric_level = getattr(logging, args.log.upper(), None)
    if not isinstance(numeric_level, int):
        raise ValueError(f"Invalid log level: {args.log}")
    logging.basicConfig(level=numeric_level)

    asyncio.run(main(base_url=args.base_url, api_key=args.api_key, bot=args.bot))
