@echo off
cd "%~dp0"
setlocal enabledelayedexpansion
rem Initialize the CUDA version variable
set "version=cpu"

rem Check if the version parameter is provided
if not "%1"=="" (
    set "set_version=%1"
)

if "!set_version!"=="" (
	rem Check if nvcc (NVIDIA CUDA Compiler) is available
	nvcc --version >NUL
	if errorlevel 1 (
		echo CUDA is not installed
	) else (
		for /f "tokens=6" %%v in ('nvcc --version ^| findstr /i "release"') do (
			set "cuda_version=%%v"
		)
		echo Found CUDA !cuda_version! installed.
		rem Parse the version number
		for /f "tokens=1,2,3 delims=." %%a in ("!cuda_version!") do (
			set "major=%%a"
			set "minor=%%b"
			set "patch=%%c"
		)
		rem Set the variable based on the CUDA version
		if !major:~1! geq 12 (
			set "version=cu128"
		) else (
			echo [WARNING] CUDA < 12.0 are not supported.
		)
	)
) else (
	set "version=!set_version!"
)
echo Picked version: !version!
xcopy /s /e /i /Y version_patch\!version!\* ..\..\
endlocal