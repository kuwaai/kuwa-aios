# Frequently Asked Questions

## System Features

1-1. Why is it called Skyscope?
A: Skyscope is taken from [the Siraya language of the public hall](https://en.wikipedia.org/wiki/Kong-k%C3%A0i). According to historical records, the public hall had the function of a "meeting place." We hope to create a meeting place for communication between humans and AI models, hence the name Skyscope. Netizens have given it a homophonic name "Cool! / Cool Frog," and in Taiwanese Hokkien, it sounds like "rely on me."

1-2. What are the features of Skyscope GenAI OS?
A: Freedom and diversity are the main features of Skyscope: it supports multiple languages, users can chat with different selected models or Bot applications simultaneously, can easily quote messages or specify answers, switch between single-turn or coherent Q&A as needed; the system can run on desktops, laptops, servers, or cloud containers, supporting Windows and Linux; models or applications can be deployed in a distributed manner on-premises or in the cloud, or integrated with external commercial models through APIs; Skyscope supports flexible group permission management, as well as various account creation methods, including invitation codes, authentication API integration, LDAP integration, etc., which can be directly used to provide commercial services.

1-3. Can Skyscope apply for an account like ChatGPT?
A: Skyscope currently does not directly provide user account registration or application hosting services. Skyscope GenAI OS opens the entire platform system for everyone to use to build their own testing, development, or service deployment platform. Skyscope has been applied in the [TAIDE Exhibition Platform](https://chat.td.nchc.org.tw/), [National University of Kaohsiung Generative AI Service Platform](https://chat.nuk.edu.tw/), and other specific application platforms to provide services.

1-4. Can Skyscope GenAI OS operate on-premises or only in the cloud?
A: It can do both, as the entire system is open source, users can set up the entire system on-premises or in a private cloud. For detailed installation instructions, please refer to [Skyscope GenAI OS's GitHub](https://github.com/skyscopeai/skyscope-aios?#installation-guide).

1-5. Which type of users does Skyscope GenAI OS system building lean towards?
A: Currently, the system building part leans more towards developers. If you encounter technical issues during the installation process, feel free to contact us for further assistance!

1-6. Does Skyscope GenAI OS have a demonstration system now?
A: The Skyscope system has been used in the [TAIDE Exhibition Platform](https://chat.td.nchc.org.tw/), [National University of Kaohsiung Generative AI Service Platform](https://chat.nuk.edu.tw/), and other specific application platforms to provide services. If you adopt Skyscope GenAI OS, please let us know.

1-7. Can this system be used without a GPU?
A: Yes, for the model part, you can connect to the cloud (such as ChatGPT API; Gemini API) or run the model on a GPU (such as NVIDIA CUDA) or CPU (such as LLaMA.cpp) locally.

1-8. Does Skyscope GenAI OS only support on-premises models?
A: In addition to on-premises models, it can also integrate cloud models such as OpenAI GPT3.5/4, Google Gemini, etc.

1-9. Is this TAIDE model open source?
A: No, the Skyscope system was developed with the support of the Taiwan's Trusted AI Dialogue Engine (TAIDE) project, but it does not currently include the TAIDE model itself. In the future, when the TAIDE model is made public, it can be directly integrated into the Skyscope system.

1-10. What applications does Skyscope GenAI OS support?
A: Currently, it supports simple large language model Q&A and RAG. RAG currently supports four types: Search QA, Web QA, Doc QA, and DB QA explained in detail as follows:
- Search QA: Q&A using Google Search and web crawling
- Doc QA: Q&A based on uploading a single document
- Web QA: Q&A for a single webpage
- DBQA: Q&A based on a pre-established knowledge base

As an open-source system, developers can refer to the source code and develop RAG and applications that better suit their needs.

1-11. What does OS stand for in the name Skyscope GenAI OS? Will it affect my existing OS?
A: Since this system manages and allocates underlying model resources to the upper-level GenAI application, similar to a traditional OS or Distributed OS, it is named GenAI OS. GenAI OS is a Web-based system composed of multiple modules with different functions. All modules can run on a single machine, or each module can run on a different OS or hardware. It will not replace your existing OS.

## Installation and Configuration

2-1. How to install the Skyscope system?
A: Please refer to the [installation instructions in the README.md file](https://github.com/skyscopeai/skyscope-aios/tree/main?tab=readme-ov-file#installation-guide). Feel free to message us if you encounter any problems!

- [Linux instructions](https://github.com/skyscopeai/skyscope-aios?#installation-guide)
- [Windows instructions](https://github.com/skyscopeai/skyscope-aios/blob/main/windows/README.md)

2-2. How to integrate ChatGPT or Gemini?  
A: Please refer to the instructions in [this document](https://github.com/skyscopeai/skyscope-aios/tree/main/executor). Feel free to contact us if you encounter any problems!

2-3. How to integrate on-premise models?  
A: Please refer to [this tutorial](https://github.com/skyscopeai/skyscope-aios/tree/main/executor). Feel free to message us if you encounter any problems!

2-4. I have trained my own model, how can I integrate it with Skyscope GenAI OS?
A: Please refer to the tutorial for setting up the model, load your model, and prepare the input and output functions to set it up on the system. [Documentation](https://github.com/skyscopeai/skyscope-aios/tree/main/executor)
2-5. Can I integrate other inference engines like TGI or vLLM?  
A: Any inference engine that implements the OpenAI API can be integrated. Other types of APIs are planned for future versions, and we welcome anyone interested to assist in implementation.

2-6. How to add new models?  
A: Please refer to [this tutorial](https://github.com/skyscopeai/skyscope-aios/tree/main/executor). Feel free to message us if you encounter any problems!

## Usage
3-1. How to deal with being stuck at "Message processing... please wait..."?  
A: To resolve this issue, please log in to the website management interface using the administrator account and click "Reset Redis Cache." This problem typically occurs when the Model worker process is forcefully closed or unexpectedly exits, which is a known issue that we are working to fix.

3-2. How to use WebQA/DocQA/SearchQA?  
A: Paste a link to Web QA to ask questions about a single webpage content.
Upload a file to Doc QA to ask questions about that file.
Just ask your question directly to Search QA to get answers.

## Community
4-1. How can I get involved?  
A: Welcome to join our community!
Feel free to set up your own and play around. If you feel there is room for improvement, you can directly submit a Pull Request to help us improve!

4-2. What communities does Skyscope have?
A: Please refer to the "[Community](./community.md)" page on our official website.