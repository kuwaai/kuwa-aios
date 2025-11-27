# Kuwa Example
---

## Kuwa API Client Example

### Step0. (optional) Create Virtual Environment
```
python -m venv .venv
# or `uv venv`

# Activate virtual environment
# Linux:
.venv/bin/activate

# Windows:
.venv\Scripts\activate
```

### Step1. Install Kuwa client library 

Install from PyPI
```
pip install -U kuwa-client

# or use uv for faster installation
# uv pip install -U kuwa-client
```

Build from source
```
cd "C:\kuwa\GenAI OS\src\library\client"
uv pip install . # If you encounter error, refer to the section below.
# If you encounter error when building Kuwa Client from source, try specify the package version.
# Bash/Zsh: SETUPTOOLS_SCM_PRETEND_VERSION="v0.4.1"
# Powershell: $env:SETUPTOOLS_SCM_PRETEND_VERSION="v0.4.1"
# cmd.exe: set "SETUPTOOLS_SCM_PRETEND_VERSION=v0.4.1"
```

### Step2. Run example script
```
python kuwa_api_sample.py --api-key "<Your local Kuwa API key>"
```

### Example Output
```
Bots @ http://127.0.0.1/
ID   Name                                 Base Model
--------------------------------------------------------------------------------
16   Calculator                           .tool/kuwa/pipe
19   Token Counter                        .tool/kuwa/pipe
22   Iconv                                .tool/kuwa/pipe
23   Python                               .tool/kuwa/pipe
24   Create RAG                           .tool/kuwa/uploader
25   File Viewer                          .tool/kuwa/pipe
26   Customize Kuwa                       .tool/kuwa/uploader
27   Media Converter                      .tool/kuwa/pipe
28   Upload Tool                          .tool/kuwa/uploader
59   Llama 4 Scout (Groq API)             .model:groq/
72   Diagram Generator                    .tool/kuwa/agent
73   Construct RAG                        .tool/kuwa/pipe
74   |System Info|                        .tool/kuwa/pipe
75   Mermaid                              .tool/kuwa/pipe
96   N8N                                  .tool/kuwa/weblet
97   Agent                                .tool/kuwa/agent
98   ChatGPT                              .model:openai/gpt
100  DALL-E                               .model:openai/dall-e
103  Gemma3-1B                            .model/google/gemma-3-1b-it
106  NIM API                              .model:nim/
107  Painter                              painter
109  Pipe                                 .tool/kuwa/pipe
136  Uploader                             .tool/kuwa/uploader
201  CopyCat                              .tool/kuwa/copycat
209  Gemma3 1B Tool Use                   .model/google/gemma-3-1b-it
210  MCP Client                           .tool.mcp
212  test                                 .model:qnn
216  MCP Tool Use Agent                   .tool/kuwa/agent
218  MCP Client                           .tool.mcp
222  Gemini                               .model:google/gemini

Name of bot to call (leave blank to use the default bot): Gemini
Using bot ".bot/Gemini"

Prompt > hello
INFO:httpx:HTTP Request: POST http://127.0.0.1/v1.0/chat/completions "HTTP/1.1 200 OK"
Hello there! How can I help you today?
```