@echo off
chcp 65001 > NUL
cd "%~dp0"
setlocal enabledelayedexpansion

call src\variables.bat

REM Local launcher diagnostics API (port 7679) is enabled by default.
REM Set this to 0 to disable the debug process/config/log endpoints:
REM set "KUWA_LAUNCHER_DEBUG_API=0"

REM Check if Node.js is available, install if missing
if not exist "packages\%node_folder%\node.exe" (
    echo Node.js not found. Downloading and installing...
    if exist "packages\%node_folder%" rd /s /q "packages\%node_folder%"
    call src\download_extract.bat %url_NodeJS% packages\%node_folder% packages %filename_NodeJS%
    if errorlevel 1 (
        echo Node.js installation failed. Please check your network connection and try again.
        exit /B 1
    )
)

if not exist "packages\%node_folder%\node.exe" (
    echo Node.js installation failed: node.exe was not found.
    exit /B 1
)

REM Handle direct subcommands
if /I "%~1"=="seed" (
    "%~dp0packages\%node_folder%\node.exe" "%~dp0..\src\launcher\tool.js" seed
    exit /B
)

REM Run launcher.js (Kuwa Launcher)
start "Kuwa Launcher" /B /WAIT "%~dp0packages\%node_folder%\node.exe" "%~dp0..\src\launcher\launcher.js"
