@echo off
chcp 65001 > NUL
cd "%~dp0"
call src\variables.bat no_migrate

REM Launch shell if "cmd" is passed
if /I "%~1"=="cmd" (
    powershell -Command "exit" 2>nul
    if %errorlevel% equ 0 (
        echo Launching PowerShell...
        powershell -NoLogo
    ) else (
        echo PowerShell not found, falling back to CMD...
        cmd /k
    )
    exit /B
)

REM Check if Node.js is available, install if missing
if not exist "packages\%node_folder%\node.exe" (
    echo Node.js not found. Downloading and installing...
    if exist "packages\%node_folder%" rd /s /q "packages\%node_folder%"
    call src\download_extract.bat "%url_NodeJS%" "packages\%node_folder%" packages "%filename_NodeJS%"
    if errorlevel 1 (
        echo Node.js installation failed. Please check your network connection and try again.
        exit /B 1
    )
)

if not exist "packages\%node_folder%\node.exe" (
    echo Node.js installation failed: node.exe was not found.
    exit /B 1
)

"%~dp0packages\%node_folder%\node.exe" ..\src\launcher\tool.js %*
