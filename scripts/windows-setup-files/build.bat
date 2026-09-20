@echo off
setlocal

set ARCHIVE_NAME=package.zip
cd "%~dp0"

REM Common root directory
set ROOT_DIR=..\..

REM Paths to include (relative to ROOT_DIR)
set REL_DIR1=src\multi-chat\node_modules
set REL_DIR2=src\multi-chat\vendor
set REL_DIR3=windows\packages
set REL_DIR4=windows\cache

if /I "%1"=="install" (
    echo Installing Kuwa runtime packages at runtime...
    call "%ROOT_DIR%\windows\src\download_extract.bat" "https://github.com/wenshui2008/RunHiddenConsole/releases/download/1.0/RunHiddenConsole.zip" "%ROOT_DIR%\windows\packages\RunHiddenConsole\x64\RunHiddenConsole.exe" "%ROOT_DIR%\windows\packages" "RunHiddenConsole.zip"
    call "%ROOT_DIR%\windows\src\download_extract.bat" "https://nodejs.org/dist/v22.22.0/node-v22.22.0-win-x64.zip" "%ROOT_DIR%\windows\packages\node-v22.22.0-win-x64\node.exe" "%ROOT_DIR%\windows\packages" "node.zip"
    call "%ROOT_DIR%\windows\src\download_extract.bat" "https://windows.php.net/downloads/releases/php-8.3.24-Win32-vs16-x64.zip" "%ROOT_DIR%\windows\packages\php-8.3.24-Win32-vs16-x64\php.exe" "%ROOT_DIR%\windows\packages" "php.zip"
    call "%ROOT_DIR%\windows\src\download_extract.bat" "https://nginx.org/download/nginx-1.26.3.zip" "%ROOT_DIR%\windows\packages\nginx-1.26.3\nginx.exe" "%ROOT_DIR%\windows\packages" "nginx.zip"
    call "%ROOT_DIR%\windows\src\download_extract.bat" "https://www.python.org/ftp/python/3.10.11/python-3.10.11-embed-amd64.zip" "%ROOT_DIR%\windows\packages\python-3.10.11-embed-amd64\python.exe" "%ROOT_DIR%\windows\packages" "python.zip"
    call "%ROOT_DIR%\windows\src\download_extract.bat" "https://github.com/redis-windows/redis-windows/releases/download/6.0.20/Redis-6.0.20-Windows-x64-msys2.zip" "%ROOT_DIR%\windows\packages\Redis-6.0.20-Windows-x64-msys2" "%ROOT_DIR%\windows\packages" "redis.zip"
    call "%ROOT_DIR%\windows\src\download_extract.bat" "https://github.com/git-for-windows/git/releases/download/v2.45.1.windows.1/PortableGit-2.45.1-64-bit.7z.exe" "%ROOT_DIR%\windows\packages\PortableGit-2.45.1-64-bit\usr\bin\bash.exe" "%ROOT_DIR%\windows\packages" "gitbash.7z.exe"
    call "%ROOT_DIR%\windows\src\download_extract.bat" "https://github.com/GyanD/codexffmpeg/releases/download/7.1.1/ffmpeg-7.1.1-full_build-shared.7z" "%ROOT_DIR%\windows\packages\ffmpeg-7.1.1-full_build-shared\bin\ffmpeg.exe" "%ROOT_DIR%\windows\packages" "ffmpeg.7z"
    if not exist "%ROOT_DIR%\windows\executors\gemma3-1b\gemma-3-1b-it-q4_0.gguf" curl -L --fail --silent --show-error -o "%ROOT_DIR%\windows\executors\gemma3-1b\gemma-3-1b-it-q4_0.gguf" "https://huggingface.co/tetf/gemma-3-1b-it-qat-q4_0-GGUF/resolve/main/gemma-3-1b-it-q4_0.gguf?download=true"
    if errorlevel 1 exit /b 1
    echo Runtime package installation completed.
    exit /b 0
)

REM Create zip using 7-Zip
if "%1"=="zip" (
    echo Creating archive %ARCHIVE_NAME% from %ROOT_DIR%...
    pushd %ROOT_DIR%
    where 7z >nul 2>&1
    if not errorlevel 1 (
        7z a -tzip "scripts/windows-setup-files/%ARCHIVE_NAME%" "%REL_DIR1%" "%REL_DIR2%" "%REL_DIR3%" "%REL_DIR4%"
    ) else (
        echo 7-Zip not found; using native tar...
        tar -a -c -f "scripts/windows-setup-files/%ARCHIVE_NAME%" "%REL_DIR1%" "%REL_DIR2%" "%REL_DIR3%" "%REL_DIR4%"
    )
    set "ARCHIVE_EXIT=%ERRORLEVEL%"
    popd
    exit /b %ARCHIVE_EXIT%
)

REM Restore using native tar to ../../
if "%1"=="restore" (
    echo Restoring from archive %ARCHIVE_NAME% to ../../ using native tar...
    tar -xf "%ARCHIVE_NAME%" -C ..\..
    goto :eof
)

echo Usage: %0 ^<zip^|restore^>
endlocal
