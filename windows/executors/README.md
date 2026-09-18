## Windows Portable Model Deployment Tutorial
Currently, for simplifying model deployment in Windows, a simple model management system is prepared in the Windows portable version. This management system is only for simple use and testing. It is not recommended to be used in Production scenarios. If you want to use it in Production, please refer to the [tutorial](../../src/executor/README.md).

This model deployment tutorial assumes that you have executed `windows/launcher.bat` and the system can log in without a problem. If the above is not your case, please go back to the steps of [this tutorial](../README.md).

## Introduction
Each executor lives in its own folder under `windows/executors`. You can rename folders freely.

Executors are managed with YAML templates. A simple executor can be configured entirely in YAML; a complex one references a JavaScript startup module:

- `_run.yaml` — the committed default startup config for an executor (the template). It either declares the executor inline (an `executor:` block) or points at a `run.js` file.
- `run.yaml` — the per-machine, user-editable config. It is gitignored. If it does not exist, the launcher automatically copies `_run.yaml` to `run.yaml` on startup.
- `run.js` — the (optional) committed startup logic for the executor. The launcher runs it in its own worker thread (one thread per executor) instead of the old batch scripts. It configures the model and launches the executor process(es). It is only needed when the startup logic is more than a model config plus launch.
- `windows/_config.yaml` — the committed default list of executor folders to run (the template).
- `windows/config.yaml` — your per-machine selection. It is gitignored. If it does not exist, the launcher automatically copies `_config.yaml` to `config.yaml` on startup.

To choose which executors run, edit `config.yaml` and keep only the folder names you want. It is a plain YAML array of folder names:

```yaml
# windows/config.yaml
- chatgpt
- ollama
```

### Simple executors (declarative, no `run.js`)

If an executor only needs to configure a model and launch the executor process, you can declare everything in `run.yaml` with an `executor:` block — no `run.js` required. The field names mirror the docker compose configuration (`docker/compose/<executor>.yaml`):

```yaml
# windows/executors/<executor>/run.yaml
version: 1
access_code: '.model:openai/gpt'

executor:
  type: chatgpt          # kuwa-executor <type>
  name: ChatGPT          # model display name
  order: 401100          # model ordering
  image: chatgpt.png     # model image (relative to this folder)
  create_bot: true       # create the default bot (set false to skip)
  count: 1               # number of executor processes to launch
  command: ["--temperature", "0.2"]   # extra CLI args, like docker `command:`
```

For a Python source executor (under `src/executor/<source>/main.py`), use `source` instead of `type`:

```yaml
executor:
  source: pipe           # runs src/executor/pipe/main.py
  name: Pipe
  order: 999999
  image: pipe.png
  count: 5
  command: ["--log", "debug"]
```

The launcher automatically runs `model:config` (using `access_code`, `name`, `order`, `image`, `create_bot`) and then launches the executor with `--access_code <access_code>` followed by the `command:` arguments.

### Complex executors (`run.js`)

When the startup needs more than a model config plus launch, point `run.yaml` at a `run.js` module instead of using an `executor:` block:

```yaml
# windows/executors/<executor>/run.yaml
version: 1
access_code: '.model:openai/gpt'
run: run.js
```

The actual startup logic lives in `run.js`, which exports an `async function run(api)`. The `api` object provides helpers such as `modelConfig(...)`, `startExecutor(...)`, `startPython(...)`, `importBot(...)`, `run(...)`, `sh(...)`, and `spawnBg(...)`. For example:

```js
// windows/executors/chatgpt/run.js
module.exports = async function run(api) {
  await api.modelConfig('.model:openai/gpt', 'ChatGPT', { image: 'chatgpt.png', order: 401100 });
  api.startExecutor(['chatgpt', '--access_code', '.model:openai/gpt']);
};
```

To customize an executor, edit its `run.js` (model parameters, API keys, model paths, etc.). The launcher executes each `run.js` in a dedicated worker thread it manages. To reset `run.yaml` back to the default, delete it and the launcher recreates it from `_run.yaml`. After changing configs, restart to take effect.

You can also place images in the folder to automatically put them into the model when the model is first created (if the model has already been created on the website, you need to manually put the images in).

By default, these models will only be available to users with Manage Tab permissions.

## Quick Model Setup Tutorial

Most built-in executors are now declarative: edit the `command:` list (and other fields) in the executor's `run.yaml`, then restart. Refer to the [executor tutorial](../../src/executor/README.md) for kuwa-executor command parameters.

### ChatGPT
1. Enter the `chatgpt` folder.
2. Open `run.yaml` and add your OpenAI API token / extra parameters to the `command` list (e.g. `["--api_key", "<token>"]`). Leave it empty to use a per-bot token.

### Gemini
1. Enter the `geminipro` folder.
2. Open `run.yaml` and add your Google API token / extra parameters to the `command` list.

### LLaMA.cpp
1. Enter the `llamacpp` folder.
2. Put the `.gguf` file in the folder.
3. Open `run.yaml` and set the `--model_path` argument in `command` to the `.gguf` filename (and any extra parameters).

### Huggingface
1. Enter the `huggingface` folder.
2. Put the model and tokenizer in the folder.
3. Open `run.yaml` and set the `--model_path` argument in `command` to the local path or a HuggingFace repo id.

### Custom
- This is a reserved custom model. Users can rewrite a version of the executor (inheriting kuwa LLMWorker). Open `run.js` and point the `spawnBg('python', [...])` call to your `.py` file.

## Advanced Usage
You can execute multiple models in multiple folders using the same `access_code`. This allows you to handle multiple requests simultaneously. You can also duplicate folders to create more model executions. These models do not have to be on the same host; you can distribute them across multiple hosts. Just set the Kernel endpoint to the kernel. For detailed instructions, please see [here](../../src/executor/README.md).

If you are using other TGI frameworks like ollama or vLLM, you can also quickly concatenate using ChatGPT's worker.