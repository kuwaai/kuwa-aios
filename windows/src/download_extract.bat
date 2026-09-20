@echo off
cd "%~dp0.."
setlocal enabledelayedexpansion
REM Usage: download_extract.bat <url> <check_location> <folder_name> <archive_name>

set "url=%1"
set "check_location=%2"
set "folder_name=%3"
set "archive_name=%4"

if not exist "%check_location%" (
if not exist "packages\%archive_name%" (
        echo File "packages\%archive_name%" does not exist. Downloading now.
        curl -L -# -o "packages\%archive_name%" %url%
    )

    :: Check if the file is a tar.xz archive
    if "%archive_name:~-7%"==".tar.xz" (
        echo Extracting packages\%archive_name%...
        tar -xf packages\%archive_name% -C "%folder_name%"
    ) else if "%archive_name:~-7%"==".7z.exe" (
        echo Extracting packages\%archive_name%...
        .\packages\%archive_name% -o "%folder_name%" -y
    ) else (
        echo Extracting packages\%archive_name%...
        powershell Expand-Archive -Path packages\%archive_name% -DestinationPath "%folder_name%"
    )
    
    REM Check if the folder is not empty
    for /F %%i in ('dir /b /a "%check_location%"') do (
        echo Unzipping successful.
        goto :cleanup
    )
echo Can't find %check_location%
    echo Unzipping failed.
    RD /Q /S "%check_location%"
) else (
    echo "packages\%check_location%" already exists, skipping download and extraction.
    goto :eof
)

:cleanup
echo Cleaning up...
del /q "packages\%archive_name%"
exit /b
goto :eof