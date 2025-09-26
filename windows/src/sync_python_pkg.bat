pushd "%~dp0"
pushd "."
call variables.bat
popd

echo Syncing Python dependencies
cd "..\.."
echo %cd%
uv pip uninstall --system -r windows\src\force-reinstall-requirements.txt
uv pip sync --refresh --system windows\src\requirements.txt.lock
popd