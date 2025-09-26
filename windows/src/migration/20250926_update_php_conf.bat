@echo off
setlocal enabledelayedexpansion

REM Define the source file
set "source_file=..\src\multi-chat\php.ini"

REM Check if the source file exists
if not exist "%source_file%" (
    echo No need to update php.ini, skipped
    exit /b 0
)

REM Loop through all directories starting with "php" inside the "packages" folder
for /d %%d in (packages\php*) do (
    set "php_folder=%%d"
    set "destination_file=!php_folder!\php.ini"

    REM Check if a php.ini file exists in the target directory before attempting to delete
    if exist "!destination_file!" (
        echo Deleting !destination_file!
        del /f /q "!destination_file!"
    )

    REM Copy the new php.ini file
    echo Copying %source_file% to !destination_file!
    copy "%source_file%" "!destination_file!"
)

echo.
echo PHP.ini replacement process completed.
exit /b 0
