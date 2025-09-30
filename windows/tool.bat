REM Loop to wait for commands
@echo off
cd "%~dp0"
call src\variables.bat no_migrate
cd "%~dp0"
setlocal enabledelayedexpansion
set "PATH=%~dp0packages\%python_folder%;%~dp0packages\%python_folder%\Scripts;%PATH%"

if "%1"=="stop" (
    echo Stopping everything
    call src\stop.bat
    goto end
)

:loop
set userInput=
set /p userInput=Enter a command (quit, seed, hf login, prune, switch, stop, cmd): 

if /I "%userInput%"=="quit" (
	echo Quit.
) else if /I "%userInput%"=="seed" (
    echo Running seed command...
    pushd ..\src\multi-chat\executables\bat\
    call AdminSeeder.bat
    popd
    goto loop
) else if /I "%userInput%"=="hf login" (
    echo Running huggingface login command...
	hf login
    goto loop
) else if /I "%userInput%"=="stop" (
    echo Stopping everything
    call src\stop.bat
    goto loop
) else if /I "%userInput%"=="cmd" (
    echo Opening cmd
    call cmd
    goto loop
) else if /I "%userInput%"=="switch" (
	set version=
    pushd src\version_patch
    for /D %%f in (*.*) do set "available_version=!available_version! | %%f"
    popd
	set /p version="Switch to version (!available_version:~3!):"
    pushd src
	call switch.bat !version!
	popd
    goto loop
) else if /I "%userInput%"=="prune" (
    echo Running prune command...
	pushd ..\src\multi-chat\
    call ..\..\windows\packages\%php_folder%\php.exe artisan model:prune
	popd
    goto loop
) else (
    goto loop
)
endlocal
