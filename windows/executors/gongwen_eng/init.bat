@echo off
setlocal EnableDelayedExpansion
echo Now in: "%cd%"

REM Remove variables
set EXECUTOR_TYPE=
set EXECUTOR_NAME=
set EXECUTOR_ACCESS_CODE=
set api_key=
set model_path=
set worker_path=
set arguments=
set command=
set image_path=
set target_access_code=

REM Extract the folder name from the input
for %%e in ("%cd%.") do set "current_folder=%%~nxe"

REM Define an array to store the model types and their names
set "names[1]=ChatGPT"
set "names[2]=Gemini Pro"
set "names[3]=GGUF Model"
set "names[4]=HuggingFace Model"
set "names[5]=Ollama"
set "names[6]=Custom Module"

REM Define an array to store the model types and their names
set "models[1]=chatgpt"
set "models[2]=geminipro"
set "models[3]=llamacpp"
set "models[4]=huggingface"
set "models[5]=ollama"
set "models[6]=custom"

REM TAIDE init
if "taide"=="!current_folder!" (
	echo Init TAIDE
	echo EXECUTOR_TYPE=llamacpp
	echo EXECUTOR_NAME=TAIDE
	echo EXECUTOR_ACCESS_CODE=taide
	
	set "EXECUTOR_TYPE=llamacpp"
	set "EXECUTOR_NAME=TAIDE"
	set "EXECUTOR_ACCESS_CODE=taide"
	goto skip_selection
) else if "llama3_1"=="!current_folder!" (
	echo Init LLaMA3.1 8B Instruct
	echo EXECUTOR_TYPE=llamacpp
	echo EXECUTOR_NAME=LLaMA3.1 8B Instruct
	echo EXECUTOR_ACCESS_CODE=llama3.1-8b-instruct
	
	set "EXECUTOR_TYPE=llamacpp"
	set "EXECUTOR_NAME=LLaMA3.1 8B Instruct"
	set "EXECUTOR_ACCESS_CODE=llama3.1-8b-instruct"
	goto skip_selection
) else if "gemma2"=="!current_folder!" (
	echo Init Gemma2 2B Instruct
	echo EXECUTOR_TYPE=llamacpp
	echo EXECUTOR_NAME=Gemma2 2B Instruct
	echo EXECUTOR_ACCESS_CODE=gemma2-2b-instruct
	
	set "EXECUTOR_TYPE=llamacpp"
	set "EXECUTOR_NAME=Gemma2 2B Instruct"
	set "EXECUTOR_ACCESS_CODE=gemma2-2b-instruct"
	goto skip_selection
) else if "docQA & webQA"=="!current_folder!" (
	echo Init docQA and webQA
	echo EXECUTOR_TYPE=custom
	echo "EXECUTOR_NAME=docQA & webQA"
	echo "EXECUTOR_ACCESS_CODE=docQA & webQA"
	
	set "EXECUTOR_TYPE=custom"
	set "EXECUTOR_NAME=docQA & webQA"
	if "%1" == "quick" (
		goto continue
	)
	goto skip_selection
) else if "dbQA" == "!current_folder!" (
	echo Init dbQA
	echo EXECUTOR_TYPE=custom
	echo EXECUTOR_NAME=dbQA
	echo EXECUTOR_ACCESS_CODE=db_qa
	
	set "EXECUTOR_TYPE=custom"
	set "EXECUTOR_NAME=dbQA"
	set "EXECUTOR_ACCESS_CODE=db_qa"
	set "worker_path=docqa.py"
	set "working_dir=..\..\..\src\executor\docqa\"
	for /d %%i in (*) do (
		echo "Folder detected, using founded folder."
		for %%F in ("%%~pi.") do (
			for %%G in ("%%~pi\..") do (
				for %%H in ("%%~pi\..\..") do (
					echo db_path=..\..\..\%%~nxH\%%~nxG\%%~nxF\%%~nxi
					set "db_path=../../../%%~nxH/%%~nxG/%%~nxF/%%~nxi"
					goto skip_db_path
				)
			)
		)
	)

	REM not quick
	if "%1" == "quick" (
		exit /b 0
	)

	:input_db_path
	set /p "db_path=Enter the database path:"
	if "!db_path!"=="" (
		echo Database path cannot be blank. Please try again.
		goto input_db_path
	)
	:skip_db_path
	goto skip_selection
) else if "SearchQA" == "!current_folder!" (
	echo Init SearchQA
	echo EXECUTOR_TYPE=custom
	echo EXECUTOR_NAME=SearchQA
	echo EXECUTOR_ACCESS_CODE=search_qa
	
	set "EXECUTOR_TYPE=custom"
	set "EXECUTOR_NAME=SearchQA"
	set "EXECUTOR_ACCESS_CODE=search_qa"
	set "worker_path=searchqa.py"
	set "working_dir=..\..\..\src\executor\docqa\"

	REM not quick
	if "%1" == "quick" (
		exit /b 0
	)

	:input_google_api_key
	set /p "google_api_key=Enter the Google API key:"
	if "!google_api_key!"=="" (
		echo Google API key cannot be blank. Please try again.
		goto input_google_api_key
	)

	:input_google_cse_id_key
	set /p "google_cse_id=Enter the Google Custom Search Engine ID:"
	if "!google_cse_id!"=="" (
		echo Google Custom Search Engine ID cannot be blank. Please try again.
		goto input_google_cse_id
	)
	
	set /p "advanced_search_params=Enter the advanced search parameters (optional):"
	goto skip_selection
) else if "ollama" == "!current_folder!" (
	REM not quick
	if "%1" == "quick" (
		exit /b 0
	)
) else if "whisper" == "!current_folder!" (
	echo Init Whisper
	echo EXECUTOR_TYPE=custom
	echo EXECUTOR_NAME=Whisper
	echo EXECUTOR_ACCESS_CODE=whisper
	
	set "EXECUTOR_TYPE=custom"
	set "EXECUTOR_NAME=Whisper"
	set "EXECUTOR_ACCESS_CODE=whisper"
	set "worker_path=main.py"
	set "working_dir=..\..\..\src\executor\speech_recognition\"
	goto continue
) else if "painter" == "!current_folder!" (
	echo Init Painter
	echo EXECUTOR_TYPE=custom
	echo EXECUTOR_NAME=Painter
	echo EXECUTOR_ACCESS_CODE=painter
	
	set "EXECUTOR_TYPE=custom"
	set "EXECUTOR_NAME=Painter"
	set "EXECUTOR_ACCESS_CODE=painter"
	set "worker_path=main.py"
	set "working_dir=..\..\..\src\executor\image_generation\"
	goto continue
)

REM Check if the current folder matches any option
for %%a in (1 2 3 4 5) do (
	if "!models[%%a]!"=="!current_folder!" (
		echo Using predefined...
		echo EXECUTOR_TYPE=!models[%%a]!
		echo EXECUTOR_NAME=!names[%%a]!
		echo EXECUTOR_ACCESS_CODE=!models[%%a]!
		
		set "EXECUTOR_TYPE=!models[%%a]!"
		set "EXECUTOR_NAME=!names[%%a]!"
		set "EXECUTOR_ACCESS_CODE=!models[%%a]!"
		goto skip_selection
	)
)

REM not quick
if "%1" == "quick" (
	exit /b 0
)

REM Display the options
echo Select an option:

for %%a in (1 2 3 4 5 6) do (
	echo %%a - !names[%%a]!
)

REM Ask for user input
:input_option
set /p "option=Enter the option number (1-6): "
if not defined models[%option%] (
    echo Invalid option. Please try again.
    goto input_option
)

REM Set the model type based on the selected option
set "EXECUTOR_TYPE=!models[%option%]!"

if "!option!" == "6" (
    REM Ask for worker path (must-fill field)
    :input_worker_path
    set /p "worker_path=Enter the worker path: "
    if "!worker_path!"=="" (
        echo Worker path cannot be blank. Please try again.
        goto input_worker_path
    )
)

REM Ask for model name
:input_EXECUTOR_NAME
set /p "EXECUTOR_NAME=Enter the model name: "
if "!EXECUTOR_NAME!"=="" (
    echo Model name cannot be blank. Please try again.
    goto input_EXECUTOR_NAME
)

if "%1" == "quick" (
	exit /b 0
)
REM Ask for access code (must-fill field)
:input_EXECUTOR_ACCESS_CODE
set /p "EXECUTOR_ACCESS_CODE=Enter the access code: "
if "!EXECUTOR_ACCESS_CODE!"=="" (
    echo Access code cannot be blank. Please try again.
    goto input_EXECUTOR_ACCESS_CODE
)

:skip_selection
if "%1" == "quick" (
	goto continue
)
REM Ask for API key if the model type is geminipro or ChatGPT
if "!EXECUTOR_TYPE!"=="geminipro" (
    set "api_key="
    :input_api_key
    set /p "api_key=Enter the API key (press Enter to leave blank): "
    if "!api_key!"=="" goto continue
) else if "!EXECUTOR_TYPE!"=="chatgpt" (
    set "api_key="
    :input_api_key
    set /p "api_key=Enter the API key (press Enter to leave blank): "
    if "!api_key!"=="" goto continue
) else if "!EXECUTOR_TYPE!"=="ollama" (
    set "ollama_host="
    :input_ollama_host
    set /p "ollama_host=Enter the Ollama host (press Enter to leave blank): "
	
	:input_model_name
    set /p "model_name=Enter the model name: "
    if "!model_name!"=="" (
        echo Model name cannot be blank. Please try again.
        goto input_model_name
    )
    goto continue
)

:continue

REM Ask for model path if the model type is llamacpp or Hugging Face
if "!EXECUTOR_TYPE!"=="llamacpp" (
	for /r %%i in (*.gguf) do (
		echo "using founded .gguf file"
		echo model_path=%%~nxi
		set "model_path=%%~nxi"
		goto skip_model_path
	)
	
	if "%1" == "quick" (
		exit /b 0
	)

    :input_model_path
    set /p "model_path=Enter the model path: "
    if "!model_path!"=="" (
        echo Model path cannot be blank. Please try again.
        goto input_model_path
    )
) else if "!EXECUTOR_TYPE!"=="huggingface" (
	for /r %%i in (*.model *.bin *.safetensor) do (
		echo "model folder detected, using current folder path"
		echo model_path=%%~dpi
		set "model_path=%%~dpi"
		goto skip_model_path
	)

	if "%1" == "quick" (
		exit /b 0
	)

    :input_model_path
    set /p "model_path=Enter the model path: "
    if "!model_path!"=="" (
        echo Model path cannot be blank. Please try again.
        goto input_model_path
    )
)

:skip_model_path

for /r %%i in (*.jpg *.jpeg *.png *.gif *.webp *.bmp *.ico *.svg *.tiff *.tif *.jp2 *.jxr *.wdp *.hdp) do (
    echo "Image detected, using founded image."
    for %%F in ("%%~pi.") do (
		for %%G in ("%%~pi\..") do (
			for %%H in ("%%~pi\..\..") do (
				echo image_path=..\..\%%~nxH\%%~nxG\%%~nxF\%%~nxi
				set "image_path=..\..\%%~nxH\%%~nxG\%%~nxF\%%~nxi"
				goto skip_image_path
			)
		)
	)
)

if "%1" == "quick" (
	goto skip_image_path
)
:input_image_path
set /p "image_path=Enter the image path: (press Enter to leave blank)"

:skip_image_path

if "%1" == "quick" (
	goto skip_arguments_path
)
:input_arguments_path
set /p "arguments=Arguments to use: (press Enter to leave blank if you don't need or don't know what this is.)"

:skip_arguments_path

del run.bat

if "!EXECUTOR_NAME!" == "docQA & webQA" (
	if not "!EXECUTOR_ACCESS_CODE!" == "docQA & webQA" (
		set target_access_code=!EXECUTOR_ACCESS_CODE!
	)
	REM Save configuration to run.bat
	echo pushd ..\..\src> run.bat
	echo call variables.bat>> run.bat
	echo popd>> run.bat
	echo set "EXECUTOR_ACCESS_CODE="doc_qa --exclude=web_qa"">> run.bat

	REM webQA
	echo pushd ..\..\..\src\multi-chat>>run.bat
	set command=php artisan model:config "web_qa" "Web QA"
	IF DEFINED image_path (
		set command=!command! --image "..\..\windows\executors\docQA & webQA\webQA.png"
	)
	echo !command!>> run.bat

	REM docQA
	set command=php artisan model:config "doc_qa" "Document QA"
	IF DEFINED image_path (
		set command=!command! --image "..\..\windows\executors\docQA & webQA\docQA.png"
	)
	echo !command!>> run.bat
	echo popd>> run.bat

	REM webQA & docQA
	echo pushd ..\..\..\src\executor\docqa>>run.bat
	set command=start /b "" "python" "docqa.py" "--access_code" "web_qa" "doc_qa"
	if DEFINED target_access_code (
		set command=!command! --model !target_access_code!
	)
	IF DEFINED arguments (
		set command=!command! !arguments!
	)
	echo !command!>> run.bat
	echo popd>> run.bat
) else (
	REM Save configuration to run.bat
	echo pushd ..\..\src> run.bat
	echo call variables.bat>> run.bat
	echo popd>> run.bat
	echo set EXECUTOR_ACCESS_CODE=!EXECUTOR_ACCESS_CODE!>> run.bat

	REM model:config
	echo pushd ..\..\..\src\multi-chat>>run.bat
	set command=php artisan model:config "!EXECUTOR_ACCESS_CODE!" "!EXECUTOR_NAME!"
	IF DEFINED image_path (
		set command=!command! --image "!image_path!"
	)
	echo !command!>> run.bat
	echo popd>>run.bat

	REM kuwa-executor
	IF NOT "!EXECUTOR_TYPE!"=="custom" (
		set command=start /b "" "kuwa-executor" "!EXECUTOR_TYPE!" "--access_code" "!EXECUTOR_ACCESS_CODE!"
		IF DEFINED api_key (
			set command=!command! "--api_key" "!api_key!"
		)
		IF DEFINED model_path (
			set command=!command! "--model_path" "!model_path!"
		)
		IF DEFINED ollama_host (
			set command=!command! "--ollama_host" "!ollama_host!"
		)
		IF DEFINED model_name (
			set command=!command! "--model" "!model_name!"
		)
		if "taide"=="!current_folder!" (
			set command=!command! "--system_prompt" "�A�O�@�ӨӦۥx�W��AI�U�z�A�A���W�r�O TAIDE�A�֩�H�x�W�H���߳����U�ϥΪ̡A�|���c�餤��^�����D�C"
		)
	) else (
		set command=start /b "" "python" !worker_path! "--access_code" "!EXECUTOR_ACCESS_CODE!"
	)
	if DEFINED db_path (
		set command=!command! "--database" "!db_path!"
	)
	if DEFINED google_api_key (
		set command=!command! "--google_api_key" "!google_api_key!"
	)
	if DEFINED google_cse_id (
		set command=!command! "--google_cse_id" "!google_cse_id!"
	)
	if DEFINED advanced_search_params (
		set command=!command! "--advanced_search_params" "!advanced_search_params!"
	)
	IF DEFINED arguments (
		set command=!command! !arguments!
	)
	IF DEFINED working_dir (
		echo pushd !working_dir!>> run.bat
	)
	echo !command!>> run.bat
	IF DEFINED working_dir (
		echo popd>> run.bat
	)
)
echo Configuration saved to run.bat

if "%1" == "quick" (
	exit /b 0
)
pause