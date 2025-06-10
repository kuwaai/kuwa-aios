## Skyscope Sentinel Gen AI OS
```An Innovative Generative AI Operating System from Skyscope Sentinel Intelligence```


## Key Features
* Multi-lingual turnkey solution for GenAI use, development and deployment on Windows, Linux and MacOS
* Concurrent multi-chat, quoting, full prompt-list import/export/share, and more for users
* Supporting multimodal models, popular RAG/agent tools, traditional applications, and local bot store  
* Flexible orchestration of prompts x RAGs x multi-modal models x tools x bots x hardware/GPUs
* Heterogeneous support from raspberry Pi, laptops, PCs, edge servers, and virtual hosts to cloud
* Open-sourced, allowing developers to contribute and customize the system according to their needs

![screenshot](./src/multi-chat/public/images/demo.gif)

## Architecture
> **Warning**: This a preliminary draft and may be subject to further changes.

[![screenshot](./src/multi-chat/public/images/architecture.svg)](https://skyscope.ai/os/Intro)

## Installation Guide
### Quick Installation
Download the script or the executable file, run it, and follow its steps to have your own Skyscope!
* **Windows**

  Download and run the pre-built Windows executable from [Skyscope's latest releases](https://github.com/skyscopeai/skyscope-aios/releases)

* **Linux/Docker**

  Download and run sudo [build.sh](./docker/build.sh) , or invoke the following command to automatically install Docker, CUDA, and Skyscope. You may need to reboot after installing CUDA. Before finishing installation, you will be asked to set your administration passwords for your Skyscope and database. After installation, it will invoke run.sh to start the system and you can log in with admin@localhost. Enjoy!
  ```
  bash -c "$(curl -fsSL https://raw.githubusercontent.com/skyscopeai/skyscope-aios/main/docker/build.sh)"
  ```
###  Step-by-step Installation
You can build your own customized Skyscope by following the step-by-step documents.
* [Portable Windows version](./windows/README.md)
* [Linux/Docker version](./docker/README.md)
### More Models and Applications
With executors, Skyscope can orchestrate diverse multimodal models, remote services, applications, databases, bots, etc. You can check [Executor's README](./src/executor/README.md) for further customization and configuration.

## Download

You can [download](https://github.com/skyscopeai/skyscope-aios/releases) the latest Skyscope GenAI OS version that supports Windows and Linux.

## Community

[Discord](https://discord.gg/4HxYAkvdu5) - Skyscope AI Discord community server

[Facebook](https://www.facebook.com/groups/g.skyscope.org) - Skyscope AI Community

[Facebook](https://www.facebook.com/groups/g.skyscope.tw) - Skyscope AI Taiwan community

[Google Group](https://groups.google.com/g/skyscope-dev) - skyscope-dev

## Announcement

[Facebook](https://www.facebook.com/skyscopeai) - Skyscope AI

[Google Group](https://groups.google.com/g/skyscope-announce) - skyscope-announce

## Support
=======
 
## Features Included
``` Multimodal and Multi Agentic Text, Image, Audio and Video capabilities ```
``` Concurrent multi-chat with import/export and sharing ability ```
``` Agentic utilities, RAG, and ability to run local LLM AI Models ```
``` Customization allowing users to set custom model prompts/ system prompts and other tweaks for truly open user control ```

## Installation Guide
 main

Run the script and allow our fully automated solution to perform installation. 

## Coming Soon

We will also be providing fully completed bootable .iso and .img variants of our unique AI OS offering - This is currently in development.


