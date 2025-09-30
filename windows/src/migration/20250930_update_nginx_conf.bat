@echo off
setlocal enabledelayedexpansion

set "source_file=src\nginx.conf"

if not exist "%source_file%" (
    echo No need to update nginx.conf, source file not found: %source_file%
    exit /b 1
)

for /d %%d in (packages\nginx*) do (
    set "nginx_folder=%%d"
    set "destination_file=!nginx_folder!\conf\nginx.conf"
    set "backup_file=!nginx_folder!\conf\nginx.conf.old"

    if exist "!destination_file!" (
        echo Backing up !destination_file! to !backup_file!
        
        move /y "!destination_file!" "!backup_file!" >nul
    )

    echo Copying %source_file% to !destination_file!
    copy /y "%source_file%" "!destination_file!" >nul
)

echo.
echo Nginx.conf replacement process completed.
exit /b 0