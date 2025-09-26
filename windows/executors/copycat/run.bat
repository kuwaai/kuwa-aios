set EXECUTOR_ACCESS_CODE=.tool/kuwa/copycat
pushd ..\..\..\src\multi-chat
php artisan model:config ".tool/kuwa/copycat" "CopyCat" --order=999000 --image "..\..\windows\executors\copycat\copycat.png"
popd
start /b "" "kuwa-executor" "debug" "--access_code" ".tool/kuwa/copycat" "--log" "debug"