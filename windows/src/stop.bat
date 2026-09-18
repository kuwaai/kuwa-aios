@echo off
chcp 65001 > NUL
setlocal enabledelayedexpansion
call src\variables.bat

set "NODE_EXE=node"
IF EXIST "%~dp0..\packages\%node_folder%\node.exe" (
    set "NODE_EXE=%~dp0..\packages\%node_folder%\node.exe"
)

echo Running stop.js...
"%NODE_EXE%" "%~dp0..\..\src\launcher\stop.js"
if errorlevel 1 (
    echo stop.js failed, falling back to manual stop...
    goto :bat_stop
)
goto :eof

:bat_stop
ECHO Stopping services manually...
IF "%HTTP_Server_Runtime%" == "nginx" (
    pushd "packages\%nginx_folder%"
    .\nginx.exe -s quit
    popd
)
IF "%HTTP_Server_Runtime%" == "apache" (
    taskkill /F /IM "httpd.exe" /T >nul 2>&1
)
REM Stop Redis server gracefully
pushd "packages\%redis_folder%"
redis-cli.exe shutdown
popd
REM Cleanup everything
pushd "..\src\multi-chat\"
call php artisan worker:stop
popd
echo Force stopping any remaining processes.
taskkill /F /IM "nginx.exe" /T >nul 2>&1
taskkill /F /IM "redis-server.exe" /T >nul 2>&1
taskkill /F /IM "php-cgi.exe" /T >nul 2>&1
taskkill /F /IM "php.exe" /T >nul 2>&1
taskkill /F /IM "node.exe" /T >nul 2>&1
taskkill /F /IM "python.exe" /T >nul 2>&1