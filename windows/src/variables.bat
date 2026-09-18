@echo off
cd "%~dp0.."

if "%KUWA_ENV_INIT%" neq "" (exit /b)

set HTTP_Server_Runtime=nginx

REM Variables for RunHiddenConsole
set "url_RunHiddenConsole=https://github.com/wenshui2008/RunHiddenConsole/releases/download/1.0/RunHiddenConsole.zip"
for %%I in ("%url_RunHiddenConsole%") do set "filename_RunHiddenConsole=%%~nxI"
set "RunHiddenConsole_folder=%filename_RunHiddenConsole:~0,-4%"

REM Variables for Node.js
set "url_NodeJS=https://nodejs.org/dist/v22.22.0/node-v22.22.0-win-x64.zip"
for %%I in ("%url_NodeJS%") do set "filename_NodeJS=%%~nxI"
set "node_folder=%filename_NodeJS:~0,-4%"
for /f "tokens=2 delims=-" %%v in ("%filename_NodeJS%") do set "version_NodeJS=%%v"

REM Variables for PHP
set "url_PHP=https://windows.php.net/downloads/releases/php-8.3.24-Win32-vs16-x64.zip"
for %%I in ("%url_PHP%") do set "filename_PHP=%%~nxI"
set "php_folder=%filename_PHP:~0,-4%"
for /f "tokens=2 delims=-" %%v in ("%filename_PHP%") do set "version_PHP=%%v"

REM Variables for fallback version of PHP
set "url_PHP_Fallback=https://windows.php.net/downloads/releases/archives/php-8.3.24-Win32-vs16-x64.zip"
for %%I in ("%url_PHP_Fallback%") do set "filename_PHP_Fallback=%%~nxI"
set "php_folder_Fallback=%filename_PHP_Fallback:~0,-4%"
for /f "tokens=2 delims=-" %%v in ("%filename_PHP_Fallback%") do set "version_PHP_Fallback=%%v"

REM Variables for Nginx
set "url_Nginx=https://nginx.org/download/nginx-1.26.3.zip"
for %%I in ("%url_Nginx%") do set "filename_Nginx=%%~nxI"
set "nginx_folder=%filename_Nginx:~0,-4%"
for /f "tokens=2 delims=-" %%v in ("%filename_Nginx%") do set "version_Nginx=%%v"

REM Variables for Python 3.10.12
set "url_Python=https://www.python.org/ftp/python/3.10.11/python-3.10.11-embed-amd64.zip"
for %%I in ("%url_Python%") do set "filename_Python=%%~nxI"
set "python_folder=%filename_Python:~0,-4%"
for /f "tokens=2 delims=-" %%v in ("%filename_Python%") do set "version_Python=%%v"

REM Variables for Redis 6.0.20
set "url_Redis=https://github.com/redis-windows/redis-windows/releases/download/6.0.20/Redis-6.0.20-Windows-x64-msys2.zip"
for %%I in ("%url_Redis%") do set "filename_Redis=%%~nxI"
set "redis_folder=%filename_Redis:~0,-4%"
for /f "tokens=2 delims=-" %%v in ("%filename_Redis%") do set "version_Redis=%%v"

REM Variables for Pandoc 3.9.0.2
set "url_Pandoc=https://github.com/jgm/pandoc/releases/download/3.9.0.2/pandoc-3.9.0.2-windows-x86_64.zip"
for %%I in ("%url_Pandoc%") do set "filename_Pandoc=%%~nxI"
set "pandoc_folder=%filename_Pandoc:~0,-19%"
for /f "tokens=2 delims=-" %%v in ("%filename_Pandoc%") do set "version_Pandoc=%%v"

REM Variables for git bash
set "url_gitbash=https://github.com/git-for-windows/git/releases/download/v2.45.1.windows.1/PortableGit-2.45.1-64-bit.7z.exe"
for %%I in ("%url_gitbash%") do set "filename_gitbash=%%~nxI"
set "gitbash_folder=%filename_gitbash:~0,-7%"
for /f "tokens=2 delims=-" %%v in ("%filename_gitbash%") do set "version_gitbash=%%v"

REM Variables for FFmpeg
set "url_ffmpeg=https://github.com/GyanD/codexffmpeg/releases/download/7.1.1/ffmpeg-7.1.1-full_build-shared.7z"
for %%I in ("%url_ffmpeg%") do set "filename_ffmpeg=%%~nxI"
set "ffmpeg_folder=%filename_ffmpeg:~0,-3%"
for /f "tokens=2 delims=-" %%v in ("%filename_ffmpeg%") do set "version_ffmpeg=%%v"

REM Environment variables for model cache
set "KUWA_CACHE=%~dp0..\cache"
if not exist "%KUWA_CACHE%" mkdir "%KUWA_CACHE%"
set "XDG_CACHE_HOME=%KUWA_CACHE%"
set "PIP_CACHE_DIR=%KUWA_CACHE%\pip"
set "TORCH_HOME=%KUWA_CACHE%\torch"
set "CSIDL_LOCAL_APPDATA=%KUWA_CACHE%\appdata"
set "HF_HOME=%KUWA_CACHE%\huggingface"
set "COMPOSER_HOME=%~dp0..\packages\Composer\"
set "CACHE_PATH_ENV=%KUWA_CACHE%\selenium"
set "PYANNOTE_CACHE=%KUWA_CACHE%\torch\pyannote"
set "HOME=%~dp0.."
::set "TRANSFORMERS_OFFLINE=1"
::set "HF_HUB_OFFLINE=1"

REM Kuwa env
set "KUWA_ROOT=%~dp0..\root"

REM Set GIT_SSH_COMMAND if the SSH deploy key is present
if exist "%~dp0..\.git\test_pack_perm.priv" (
    set "GIT_SSH_COMMAND=ssh -i "%~dp0..\.git\test_pack_perm.priv" -o IdentitiesOnly=yes -o StrictHostKeyChecking=no"
)

REM Prepare migration file
mkdir src\conf 2>nul
if not exist "src\conf\migrations.txt" (
    type nul > "src\conf\migrations.txt"
)

REM Prepare packages folder
mkdir packages 2>nul

REM init env - build local PATH prefix
set "KUWA_PATH=%~dp0..\packages\Composer\vendor\bin;%~dp0\bin;%~dp0..\packages\;%~dp0..\packages\%python_folder%\Scripts;%~dp0..\packages\%python_folder%;%~dp0..\packages\%php_folder%;%~dp0..\packages\%node_folder%;%~dp0..\packages\%gitbash_folder%\cmd;%~dp0..\packages\%ffmpeg_folder%\bin;%~dp0..\packages\%ffmpeg_folder%\lib;%~dp0..\packages\%pandoc_folder%"

REM Remove system-installed python/nginx/redis/php/node/ffmpeg/pandoc from PATH to avoid conflicts
setlocal enabledelayedexpansion
set "CLEAN_PATH="
for %%A in ("%PATH:;=";"%") do (
    echo %%~A | findstr /i /r "\\python[0-9]* \\nginx \\redis \\php \\node \\nodejs \\ffmpeg \\pandoc" >nul 2>nul
    if errorlevel 1 (
        if defined CLEAN_PATH (
            set "CLEAN_PATH=!CLEAN_PATH!;%%~A"
        ) else (
            set "CLEAN_PATH=%%~A"
        )
    )
)
endlocal & set "PATH=%KUWA_PATH%;%CLEAN_PATH%"

if "%1"=="no_migrate" (
    echo Skipped migration
) else (
    REM Run migration
    for %%i in ("src\migration\*.bat") do (
        findstr /i /c:"%%~nxi" "src\conf\migrations.txt" >nul || (
            echo Running %%~nxi
            call "%%i"
            if errorlevel 1 (
                echo %%~nxi did not execute successfully.
            ) else (
                echo %%~nxi executed successfully.
                echo %%~nxi>>"src\conf\migrations.txt"
            )
        )
    )
)

:: This variable prevents execute variable.bat too many times.
set KUWA_ENV_INIT=1

