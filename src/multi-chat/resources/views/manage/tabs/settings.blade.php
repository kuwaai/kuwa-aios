<div class="flex flex-1">
    <!-- Navigator Sidebar -->
    <div class="bg-gray-100 dark:bg-gray-800 pl-4 pt-4 overflow-y-auto">
        <ul class="space-y-2">
            <li>
                <a href="#ui-settings"
                    class="block p-4 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-gray-200 rounded-md transition duration-200 ease-in-out hover:bg-gray-300 dark:hover:bg-gray-600">
                    {{ __('settings.header.setting.ui') }}
                </a>
            </li>
            <li>
                <a href="#storage-settings"
                    class="block p-4 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-gray-200 rounded-md transition duration-200 ease-in-out hover:bg-gray-300 dark:hover:bg-gray-600">
                    {{ __('settings.header.setting.storage') }}
                </a>
            </li>
            <li>
                <a href="#kernel-settings"
                    class="block p-4 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-gray-200 rounded-md transition duration-200 ease-in-out hover:bg-gray-300 dark:hover:bg-gray-600">
                    {{ __('settings.header.setting.kernel') }}
                </a>
            </li>
            <li>
                <a href="#env-settings"
                    class="block p-4 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-gray-200 rounded-md transition duration-200 ease-in-out hover:bg-gray-300 dark:hover:bg-gray-600">
                    {{ __('settings.header.setting.env') }}
                </a>
            </li>
            <li>
                <a href="#other-feature"
                    class="block p-4 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-gray-200 rounded-md transition duration-200 ease-in-out hover:bg-gray-300 dark:hover:bg-gray-600">
                    {{ __('settings.header.setting.other') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="flex flex-1 h-full mx-auto flex-col scrollbar overflow-y-auto p-2">
        <div class="flex justify-end">
            <button type="submit" onclick='submitSettings(false);'
                class="fixed z-10 flex justify-center items-center px-6 py-2 rounded-lg bg-green-500 hover:bg-green-600 text-white font-semibold transition duration-200 ease-in-out shadow-md">
                {{ __('settings.button.save') }}
            </button>
        </div>
        <form class="setting-form" method="post" action="{{ route('manage.setting.update') }}"
            onsubmit="submitSettings(false);" class="space-y-6 flex-1 m-4 rounded p-2">
            @csrf
            @method('patch')
            <input name='tab' value='settings' hidden />
            <div class="flex items-center flex-col">
                @php
                    $alerts = [
                        'update' => [
                            'success' => [
                                'status' => 'success',
                                'message' => __('settings.placeholder.saved'),
                                'type' => 'green',
                            ],
                            'smtp_not_configured' => [
                                'status' => 'smtp_not_configured',
                                'message' => __('settings.placeholder.smtp_not_configured'),
                                'type' => 'blue',
                            ],
                        ],
                        'resetRedis' => [
                            'success' => [
                                'status' => 'success',
                                'message' => __('settings.placeholder.redis_cache_cleared'),
                                'type' => 'green',
                            ],
                        ],
                    ];

                    $lastAction = session('last_action');
                    $status = session('status');
                    $alert = $alerts[$lastAction][$status] ?? null;
                @endphp

                @if ($alert)
                    <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 5000)" id="alert-border-3"
                        class="flex items-center w-full mt-12 p-4 mb-2 text-{{ $alert['type'] }}-800 border-t-4 border-{{ $alert['type'] }}-300 bg-{{ $alert['type'] }}-50 dark:text-{{ $alert['type'] }}-400 dark:bg-gray-800 dark:border-{{ $alert['type'] }}-800"
                        role="alert">
                        <svg class="flex-shrink-0 w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
                            fill="currentColor" viewBox="0 0 20 20">
                            <path
                                d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5ZM9.5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM12 15H8a1 1 0 0 1 0-2h1v-3H8a1 1 0 0 1 0-2h2a1 1 0 0 1 1 1v4h1a1 1 0 0 1 0 2Z" />
                        </svg>
                        <div class="ml-3 text-sm font-medium">{{ $alert['message'] }}</div>
                    </div>
                @endif

                @if ($lastAction === 'update' && $status === 'smtp_not_configured')
                    <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 5000)" id="alert-border-3"
                        class="flex items-center w-full p-4 mb-2 text-green-800 border-t-4 border-green-300 bg-green-50 dark:text-green-400 dark:bg-gray-800 dark:border-green-800"
                        role="alert">
                        <svg class="flex-shrink-0 w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
                            fill="currentColor" viewBox="0 0 20 20">
                            <path
                                d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5ZM9.5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM12 15H8a1 1 0 0 1 0-2h1v-3H8a1 1 0 0 1 0-2h2a1 1 0 0 1 1 1v4h1a1 1 0 0 1 0 2Z" />
                        </svg>
                        <div class="ml-3 text-sm font-medium">{{ __('settings.placeholder.saved') }}</div>
                    </div>
                @endif
            </div>

            <!-- UI Settings -->
            <div class="p-3" id="ui-settings">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                    {{ __('settings.header.setting.ui') }}</h3>
                <label class="relative inline-flex items-center mr-5 cursor-pointer">
                    <input type="checkbox" value="allow" name="allow_register" class="sr-only peer"
                        {{ \App\Models\SystemSetting::where('key', 'allow_register')->first()->value == 'true' ? 'checked' : '' }}>
                    <div
                        class="w-11 h-6 bg-gray-200 rounded-full peer dark:bg-gray-700 peer-focus:ring-4 peer-focus:ring-green-300 dark:peer-focus:ring-green-800 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-green-600">
                    </div>
                    <span
                        class="ml-3 text-sm font-medium text-gray-900 dark:text-gray-300">{{ __('settings.label.allow_register') }}</span>
                </label>
                <label class="relative inline-flex items-center mr-5 cursor-pointer">
                    <input type="checkbox" value="allow" name="register_need_invite" class="sr-only peer"
                        {{ \App\Models\SystemSetting::where('key', 'register_need_invite')->first()->value == 'true' ? 'checked' : '' }}>
                    <div
                        class="w-11 h-6 bg-gray-200 rounded-full peer dark:bg-gray-700 peer-focus:ring-4 peer-focus:ring-green-300 dark:peer-focus:ring-green-800 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-green-600">
                    </div>
                    <span
                        class="ml-3 text-sm font-medium text-gray-900 dark:text-gray-300">{{ __('settings.label.need_invite') }}</span>
                </label>
                <div>
                    <x-input-label for="announcement" :value="__('settings.label.anno')" />
                    <div class="flex items-center">
                        <textarea id="announcement" name="announcement" type="text" oninput="adjustTextareaRows(this)" rows="1"
                            max-rows="5"
                            class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm mr-2 mb-1 block w-full resize-none scrollbar">{{ \App\Models\SystemSetting::where('key', 'announcement')->first()->value }}</textarea>
                    </div>
                </div>

                <div>
                    <x-input-label for="tos" :value="__('settings.label.tos')" />
                    <div class="flex items-center">
                        <textarea id="tos" name="tos" type="text" oninput="adjustTextareaRows(this)" rows="1" max-rows="5"
                            class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm mr-2 mb-1 block w-full resize-none scrollbar">{{ \App\Models\SystemSetting::where('key', 'tos')->first()->value }}</textarea>
                    </div>
                </div>
                <div>
                    <x-input-label for="warning_footer" :value="__('settings.label.footer_warning')" />
                    <div class="flex items-center">
                        <x-text-input id="warning_footer" name="warning_footer" type="text"
                            class="mr-2 mb-1 block w-full"
                            value="{{ \App\Models\SystemSetting::where('key', 'warning_footer')->first()->value }}"
                            autocomplete="off" />
                    </div>
                </div>
            </div>
            <!-- Storage Settings -->
            <div class="p-3" id="storage-settings">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                    {{ __('settings.header.setting.storage') }}
                </h3>
                <div>
                    <x-input-label for="upload_max_size_mb" :value="__('settings.label.upload_max_size_mb')" />
                    <div class="flex items-center">
                        <x-text-input id="upload_max_size_mb" name="upload_max_size_mb" type="text"
                            class="mr-2 mb-1 block w-full"
                            value="{{ \App\Models\SystemSetting::where('key', 'upload_max_size_mb')->first()->value }}"
                            autocomplete="off" />
                    </div>
                </div>
                <div>
                    <x-input-label for="upload_allowed_extensions" :value="__('settings.label.upload_allowed_extensions')" />
                    <div class="flex items-center">
                        <x-text-input id="upload_allowed_extensions" name="upload_allowed_extensions" type="text"
                            class="mr-2 mb-1 block w-full"
                            value="{{ \App\Models\SystemSetting::where('key', 'upload_allowed_extensions')->first()->value }}"
                            autocomplete="off" />
                    </div>
                </div>
                <div>
                    <x-input-label for="upload_max_file_count" :value="__('settings.label.upload_max_file_count')" />
                    <div class="flex items-center">
                        @php
                            $upload_max_file_count = \App\Models\SystemSetting::where(
                                'key',
                                'upload_max_file_count',
                            )->first()->value;
                        @endphp
                        <x-text-input id="upload_max_file_count" name="upload_max_file_count" type="text"
                            class="mr-2 mb-1 block w-full" value="{{ $upload_max_file_count }}"
                            data-original-value="{{ $upload_max_file_count }}" autocomplete="off" />
                    </div>
                </div>
            </div>
            <!-- Kernel Settings -->
            <div class="p-3" id="kernel-settings">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                    {{ __('settings.header.setting.kernel') }}
                </h3>
                <div>
                    <x-input-label for="kernel_location" :value="__('settings.label.kernel_location')" />
                    <div class="flex items-center">
                        <x-text-input id="kernel_location" name="kernel_location" type="text"
                            class="mr-2 mb-1 block w-full"
                            value="{{ \App\Models\SystemSetting::where('key', 'kernel_location')->first()->value }}"
                            autocomplete="off" />
                    </div>
                </div>
                <div>
                    <x-input-label for="safety_guard_location" :value="__('settings.label.safety_guard_API')" />
                    <div class="flex items-center">
                        <x-text-input id="safety_guard_location" name="safety_guard_location" type="text"
                            class="mr-2 mb-1 block w-full"
                            value="{{ \App\Models\SystemSetting::where('key', 'safety_guard_location')->first()->value }}"
                            autocomplete="off" />
                    </div>
                </div>
            </div>

            <!-- Env Settings -->
            <div class="p-3" id="env-settings">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                    {{ __('settings.header.setting.env') }}</h3>
                <div>
                    <x-input-label for="updateweb_git_ssh_command" :value="__('settings.label.updateweb_git_ssh_command')" />
                    <div class="flex items-center">
                        <x-text-input id="updateweb_git_ssh_command" name="updateweb_git_ssh_command" type="text"
                            class="mr-2 mb-1 block w-full"
                            value="{{ \App\Models\SystemSetting::where('key', 'updateweb_git_ssh_command')->first()->value }}"
                            autocomplete="off" />
                    </div>
                </div>
                <div>
                    <x-input-label for="updateweb_path" :value="__('settings.label.updateweb_path')" />
                    <div class="flex items-center">
                        <x-text-input id="updateweb_path" name="updateweb_path" type="text"
                            class="mr-2 mb-1 block w-full"
                            value="{{ \App\Models\SystemSetting::where('key', 'updateweb_path')->first()->value }}"
                            autocomplete="off" />
                    </div>
                </div>

                <div class="space-y-6 flex-1 rounded">
                    <div class="my-2">
                        <div id="updateWebBtn" onclick='updateWeb()'
                            class="bg-teal-500 inline-block hover:bg-teal-700 text-white font-bold py-2 px-4 rounded-lg text-center cursor-pointer">
                            {{ __('settings.button.updateWeb') }}
                        </div>
                    </div>

                </div>
            </div>

            <!-- Debug Settings -->
            <div class="p-3" id="other-feature">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                    {{ __('settings.header.setting.other') }}
                </h3>
                <div class="my-2"><a
                        class="bg-teal-500 inline-block hover:bg-teal-700 text-white font-bold py-2 px-4 rounded-lg text-center"
                        href="{{ route('manage.setting.resetRedis') }}">{{ __('settings.button.reset_redis') }}</a>
                </div>
            </div>
            <div class="h-[400px]"></div>
        </form>
    </div>
    <div class="show-confirm-modal hide" data-modal-target="confirm-modal" data-modal-show="confirm-modal"></div>
    <div id="confirm-modal" data-modal-backdrop="static" tabindex="-2"
        class="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed top-0 left-0 right-0 z-50 hidden p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full">
        <div class="relative w-full max-w-md max-h-full">
            <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
                <button type="button"
                    class="absolute top-3 right-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center dark:hover:bg-gray-800 dark:hover:text-white"
                    data-modal-hide="confirm-modal">
                    <svg aria-hidden="true" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                            d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <span class="sr-only">Close modal</span>
                </button>
                <div class="p-6 text-center">
                    <svg aria-hidden="true" class="mx-auto mb-4 text-gray-400 w-14 h-14 dark:text-gray-200"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <h3 class="message mb-5 text-lg font-normal text-gray-500 dark:text-gray-400">
                    </h3>
                    <button data-modal-hide="confirm-modal" type="button"
                        class="text-white bg-red-600 hover:bg-red-800 font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2"
                        onclick="submitSettings(true);">
                        {{ __('settings.button.yes') }}
                    </button>
                    <div id="status" class="mt-4"></div>
                    <button data-modal-hide="confirm-modal" type="button"
                        class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-gray-200 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900 focus:z-10 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-500 dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-gray-600">
                        {{ __('settings.button.no') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    $('a[href^="#"]').on('click', function(e) {
        e.preventDefault();

        const targetElement = $(this.getAttribute('href'))[0]; // Get the DOM element from the jQuery object

        // Use scrollIntoView for smooth scrolling
        targetElement.scrollIntoView({
            behavior: 'smooth'
        });

        // Add classes for styling
        $(targetElement).addClass('bg-gray-200 dark:bg-gray-600 transition duration-500 ease-in-out ring-2 ring-gray-400');

        // Remove classes after a timeout
        setTimeout(() => {
            $(targetElement).removeClass('bg-gray-200 dark:bg-gray-600 ring-2 ring-gray-400');
        }, 500);
    });


    function submitSettings(confirmed) {
        let form = $(".setting-form");
        if (form.data("confirmed")) {
            return;
        }

        let confirm_needed = false;
        let messages = [];
        let cur_max_file_cnt = $("#upload_max_file_count").val();
        let orig_max_file_cnt = $("#upload_max_file_count").data("original-value");
        let parse_int = (x) => parseInt(x) || 0;
        let parse_max_file_cnt = (x) => parse_int(x) === -1 ? Number.MAX_SAFE_INTEGER : parse_int(x);

        if (parse_max_file_cnt(cur_max_file_cnt) < parse_max_file_cnt(orig_max_file_cnt)) {
            messages.push("{{ __('settings.modal.confirm_setting_modal.shrink_max_upload_file_count') }}");
            confirm_needed = true;
        }

        if (confirm_needed && !confirmed) {
            event.preventDefault();
            $("#confirm-modal").find(".message").html(messages.join("<br>"));
            $(".show-confirm-modal").click();
        } else {
            form.data("confirmed", true);
            form.submit();
        }
    }
</script>
