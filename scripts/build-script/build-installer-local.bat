@echo off
chcp 65001 > NUL
cd /d "%~dp0"
node "%~dp0build-installer-local.js" %*
set "EXIT_CODE=%ERRORLEVEL%"
if not "%EXIT_CODE%"=="0" (
	echo.
	echo Build failed with exit code %EXIT_CODE%.
	pause
	exit /b %EXIT_CODE%
)
echo.
echo Build completed successfully.
pause
