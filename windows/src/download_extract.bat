@echo off
cd "%~dp0.."
setlocal enabledelayedexpansion
REM Usage: download_extract.bat <url> <check_location> <folder_name> <archive_name>

set "url=%1"
set "check_location=%2"
set "folder_name=%3"
set "archive_name=%4"

if not exist "%check_location%" (
    echo Downloading %archive_name%...
    if exist "packages\%archive_name%.download" del /q "packages\%archive_name%.download"
    curl --fail --location --retry 3 --retry-all-errors -# -o "packages\%archive_name%.download" "%url%"
    if errorlevel 1 (
        echo Download failed for %archive_name%.
        del /q "packages\%archive_name%.download" 2>nul
        exit /b 1
    )
    move /Y "packages\%archive_name%.download" "packages\%archive_name%" >nul
    if errorlevel 1 (
        echo Failed to save %archive_name%.
        exit /b 1
    )

    :: Check if the file is a tar.xz archive
    if "%archive_name:~-7%"==".tar.xz" (
        echo Extracting packages\%archive_name%...
        tar -xf "packages\%archive_name%" -C "%folder_name%"
        if errorlevel 1 goto :extract_failed
    ) else if "%archive_name:~-7%"==".7z.exe" (
        echo Extracting packages\%archive_name%...
        .\packages\%archive_name% -o "%folder_name%" -y
        if errorlevel 1 goto :extract_failed
    ) else (
        echo Extracting packages\%archive_name%...
        powershell -NoProfile -Command "Expand-Archive -LiteralPath 'packages\%archive_name%' -DestinationPath '%folder_name%' -Force"
        if errorlevel 1 goto :extract_failed
    )
    
    REM Check if the folder is not empty
    for /F %%i in ('dir /b /a "%check_location%"') do (
        echo Unzipping successful.
        goto :cleanup
    )
	echo Can't find %check_location%
    echo Unzipping failed: expected path was not created.
    RD /Q /S "%check_location%"
    del /q "packages\%archive_name%" 2>nul
    exit /b 1
) else (
	echo "%check_location%" already exists, skipping download and extraction.
    goto :eof
)

:cleanup
echo Cleaning up...
del /q "packages\%archive_name%"
exit /b 0

:extract_failed
echo Extraction failed for packages\%archive_name%.
del /q "packages\%archive_name%" 2>nul
if exist "%check_location%" RD /Q /S "%check_location%"
exit /b 1
goto :eof