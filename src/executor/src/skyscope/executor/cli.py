import sys
import os
import argparse
import importlib

sys.path.append(os.path.join(os.path.dirname(__file__), "./example"))
sys.path.append(os.path.join(os.path.dirname(__file__), "../../../"))

EXECUTORS = [
    {
        "name": "debug",
        "description": "[Tool] Debugging executor. It will reflect the last input.",
        "class": "debug.DebugExecutor",
    },
    {
        "name": "dummy",
        "description": "[Tool] Dummy executor. It will reply fixed message regardless of the user prompt.",
        "class": "dummy.DummyExecutor",
    },
    {
        "name": "geminipro",
        "description": "[Cloud model] Google Gemini-Pro. Need API key.",
        "class": "geminipro.GeminiExecutor",
    },
    {
        "name": "chatgpt",
        "description": "[Cloud model] OpenAI ChatGPT. Need API key.",
        "class": "chatgpt.ChatGptExecutor",
    },
    {
        "name": "huggingface",
        "description": "[On-premises model] Download and run Huggingface model locally.",
        "class": "huggingface.HuggingfaceExecutor",
    },
    {
        "name": "llamacpp",
        "description": "[On-premises model] Run the GGUF model locally.",
        "class": "llamacpp.LlamaCppExecutor",
    },
    {
        "name": "ollama",
        "description": "[On-premises model] Run a proxy that connected to the Ollama API server.",
        "class": "ollama_proxy.OllamaExecutor",
    },
    {
        "name": "nim",
        "description": "[Cloud model] Nvidia NIM. Need API key.",
        "class": "nim.NimExecutor",
    },
]


def import_class(name: str):
    """
    Import class from specified module
    """

    module_name, class_name = name.rsplit(".", 1)
    return getattr(importlib.import_module(module_name), class_name)


class ListAction(argparse.Action):
    def __init__(self, option_strings, dest, **kwargs):
        return super().__init__(
            option_strings, dest, nargs=0, default=argparse.SUPPRESS, **kwargs
        )

    def __call__(self, parser, namespace, values, option_string, **kwargs):
        name_width = max([len(i["name"]) for i in EXECUTORS])
        print("Available model executors:\n")
        for executor in EXECUTORS:
            print(f"{executor['name']: <{name_width}}: {executor['description']}")
        print(
            f'\nUse "{os.path.basename(sys.argv[0])} [executor] --help" to get more information.\n'
        )
        parser.exit()


class ExecutorAction(argparse.Action):
    def __init__(self, option_strings, dest, **kwargs):
        return super().__init__(
            option_strings, dest, nargs=1, default=argparse.SUPPRESS, **kwargs
        )

    def __call__(self, parser, namespace, values, option_string, **kwargs):
        executor_name = values[0]
        idx = sys.argv.index(executor_name)
        sys.argv = [f"{sys.argv[0]} {executor_name}", *sys.argv[idx + 1 :]]

        executor_info = [i for i in EXECUTORS if i["name"] == executor_name]
        assert len(executor_info) == 1
        executor_info = executor_info[0]

        executor_class = import_class(executor_info["class"])
        executor = executor_class()
        executor.run()
        parser.exit()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--list", action=ListAction, help="List the available executors."
    )
    parser.add_argument(
        "executor",
        action=ExecutorAction,
        choices=[i["name"] for i in EXECUTORS],
        help=f'Executor to invoke. Use "{sys.argv[0]} [executor] --help" to get more information',
    )
    args, unknown_args = parser.parse_known_args()


if __name__ == "__main__":
    main()
