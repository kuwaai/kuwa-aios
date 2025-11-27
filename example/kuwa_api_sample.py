import os
import asyncio
import logging
import argparse
from typing import Iterable
from operator import itemgetter
from kuwa.client import KuwaClient, BotOperations


logger = logging.getLogger(__name__)


def table_format(headers: Iterable, data: Iterable):
    # Calculate column widths
    widths = [len(h) for h in headers]
    for row in data:
        for i, val in enumerate(row):
            widths[i] = max(widths[i], len(str(val)))

    # Add padding
    widths = [w + 2 for w in widths]

    # Print Header
    header_row = "".join(str(h).ljust(w) for h, w in zip(headers, widths))
    result = []
    result.append(header_row)
    result.append("-" * len(header_row))

    # Print Rows
    for row in data:
        result.append("".join(str(val).ljust(w) for val, w in zip(row, widths)))
    return "\n".join(result)


def print_bot_list(client: KuwaClient):
    bot_list = BotOperations.from_kuwa_client(client).list_bots()
    if bot_list.get("status") != "success":
        print("Failed to get bot list.")
        return

    # Extract relevant fields
    rows = []
    for item in bot_list["result"]:
        rows.append(
            [int(item.get("id", "")), item.get("name", ""), item.get("access_code", "")]
        )
    rows = sorted(rows, key=itemgetter(0))
    headers = ["ID", "Name", "Base Model"]

    print(table_format(headers=headers, data=rows))


async def main(base_url: str, api_key: str):
    client = KuwaClient(
        base_url=base_url,
        auth_token=api_key,
    )

    print(f"Bots @ {base_url}")
    print_bot_list(client=client)
    print()

    bot_name = input("Name of bot to call (leave blank to use the default bot): ")
    bot_code = f".bot/{bot_name}" if bot_name else ".bot/.default"
    print(f'Using bot "{bot_code}"\n')

    user_prompt = input("Prompt > ")
    message = [{"role": "user", "content": user_prompt}]

    generator = client.chat_complete(messages=message, streaming=True, model=bot_code)

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
        default=os.environ.get("KUWA_API_BASE_URL", "http://127.0.0.1/"),
        help="The custom base URL for the Kuwa API.",
    )
    parser.add_argument(
        "--api-key",
        default=os.environ.get("KUWA_API_KEY"),
        help="The API token for authentication with Kuwa.",
    )
    parser.add_argument(
        "--log", type=str, default="INFO", help="the log level. (INFO, DEBUG, ...)"
    )
    args = parser.parse_args()

    # Setup logger
    numeric_level = getattr(logging, args.log.upper(), None)
    if not isinstance(numeric_level, int):
        raise ValueError(f"Invalid log level: {args.log}")
    logging.basicConfig(level=numeric_level)

    asyncio.run(main(base_url=args.base_url, api_key=args.api_key))
