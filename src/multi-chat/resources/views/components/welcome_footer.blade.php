<div class="flex justify-center mt-4 px-0 sm:items-center sm:justify-between">
    <div class="text-center text-sm text-gray-500 dark:text-gray-400 sm:text-left">
        <div class="flex items-center gap-4">
            <a href="https://kuwaai.tw/" target="_blank"
                class="group inline-flex items-center hover:text-gray-700 dark:hover:text-white focus:rounded-sm focus:outline-red-500">GenAI OS</a>
        </div>
    </div>

    <div class="ml-4 text-center text-sm text-gray-500 dark:text-gray-400 sm:text-right sm:ml-0">
        <a class="group inline-flex items-center hover:text-gray-700 dark:hover:text-white focus:rounded-sm focus:outline-red-500"
            href="https://kuwaai.tw/" target="_blank">Kuwa</a>
        <span class="text-black dark:text-white flex justify-end text-sm">{{ __('welcome.version') }}
            {{ \App\Services\BuildInfoService::getVersion() }}</span>
    </div>
</div>
