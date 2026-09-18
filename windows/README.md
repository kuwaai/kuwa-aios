## Portable Installation Guide for Windows

We provide a portable version for Windows x64, which uses SQLite as the database by default. After unzipping, the required packages will take up about 1.5GB. Please pay attention to your data usage.

Please follow the steps below to install:

### Prerequisites
- Make sure you have already installed [VC_redist.exe](https://learn.microsoft.com/en-US/cpp/windows/latest-supported-vc-redist?view=msvc-170)
- If you want to load models on GPU, please install [CUDA](https://developer.nvidia.com/cuda-toolkit) first.

### Quick Installation
- Gemini and ChatGPT are enabled by default. If you need to run the gguf model, please drop it in the path: executors\llamacpp.
- The process will create an admin account. If you need a new one, please refer to the first item in [FAQs](#faq).
```bat
git clone https://github.com/kuwaai/kuwa-aios.git
cd kuwa-aios/windows
"launcher.bat"
```
- Enter the `stop` command to close the system. Directly closing the window may fail to release memory usage. If you accidentally closed the window, please refer to the third item in [FAQs](#faq).
- Run `launcher.bat` for subsequent startup. Use `repair.bat build` after an update or when the project path is moved.

### Detailed Installation Steps

1. **Download from Release, or execute the following command in git bash to clone the project and switch to the windows folder in the project:**
   ```bat
   git clone https://github.com/kuwaai/kuwa-aios.git
   cd kuwa-aios/windows
   ```

2. **Start the launcher and download the related packages:**
   ```bat
   .\launcher.bat
   ```

3. **Start the application:**
   - Run `launcher.bat` to start the application. Note: If you have any of the following services running (nginx, php, php-cgi, python, redis-server), the launcher manages them. Please also make sure that ports 80, 9000, and 6379 are not being used.
   ```bat
   .\launcher.bat
   ```
   - At this point, you should be asked to create an administrator account (you will need to enter a name, email address, and password). If it does not pop up or you enter it incorrectly or fail to create it, please see [here](#faq).

4. **Check the application status:**
   - If successful, your browser will automatically open to `127.0.0.1`. If you see the web interface, the installation should be successful.

5. **How to close the program:**
   - Please try not to force close the .bat file (including using the red cross to close it directly). Currently, due to the .bat file, it cannot automatically close all open programs to release resources in these situations.

   - **Therefore, please develop the habit of entering `stop` when executing `launcher.bat` to close the program.**

6. **Set up models:**
   - By default, ChatGPT and Gemini are preset when the program is just started. Both models are connected to the API, so you need to apply for the corresponding API Key. If you want to start your own model or connect to other APIs, you need to set up executors. However, since this part is extensive, please refer to the tutorial guide [here](./executors/README.md).

## FAQ

1. **Q: I was not asked to create an administrator account, the administrator account creation failed, or I entered it incorrectly...**
   
   A: Please open `launcher.bat` with the `seed` argument to open the administrator account creation interface.

2. **Q: After moving the entire project, I got a bunch of errors when executing launcher.bat, and the webpage was 404/500 and could not be accessed.**

   A: Since some parts of the project must use absolute paths, if the path to the project directory has changed, run `repair.bat build` to rebuild the installation and launcher paths.

3. **Q: I accidentally closed the launcher window by directly clicking the red cross; the background program was not closed, and the memory resources are still occupied. What should I do?**

   A: Open `launcher.bat`, enter `stop`, and press Enter to terminate all related programs.

Please feel free to contact us if you encounter any problems during the installation.
