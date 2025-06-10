<nav x-data="{ open: false }" class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
    <!-- Primary Navigation Menu -->
    <div class="mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('/') }}">
                        <x-APP-Logo class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-200" />
                    </a>
                </div>

                <!-- Navigation Links -->
                @if (Auth::user()->hasPerm('tab_Dashboard'))
                    <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                        <x-nav-link :href="route('dashboard.home')" :active="request()->routeIs('dashboard.*')">
                            {{ __('dashboard.route') }}
                        </x-nav-link>
                    </div>
                @endif
                @if (Auth::user()->hasPerm('tab_Room'))
                    <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                        <x-nav-link :href="route('room.home')" :active="request()->routeIs('room.*')">
                            {{ __('room.route') }}
                        </x-nav-link>
                    </div>
                @endif
                @if (Auth::user()->hasPerm('tab_Store'))
                    <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                        <x-nav-link :href="route('store.home')" :active="request()->routeIs('store.*')">
                            {{ __('store.route') }}
                        </x-nav-link>
                    </div>
                @endif
                @if (Auth::user()->hasPerm('tab_Cloud'))
                    <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                        <x-nav-link :href="route('cloud.home')" :active="request()->routeIs('cloud.*')">
                            {{ __('cloud.route') }}
                        </x-nav-link>
                    </div>
                @endif
                @if (Auth::user()->hasPerm('tab_Manage'))
                    <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                        <x-nav-link :href="route('manage.home')" :active="request()->routeIs('manage.*')">
                            {{ __('manage.route') }}
                        </x-nav-link>
                    </div>
                @endif
            </div>
            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ml-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button onclick="$(this).children().eq(1).children().toggleClass('rotate-180')"
                            class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ml-1">
                                <i class="fas fa-chevron-up mx-3 transform duration-500 rotate-180"
                                    style="font-size:10px;"></i>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <a href="/help/index.html" target="_blank"
                        class="block w-full px-4 py-2 text-left text-sm leading-5 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out">{{ __('welcome.button.help') }}</a>

                        <hr class="border-gray-300 dark:border-gray-600">
                        @if (env('INFORMATION_URL'))
                            <a class="block w-full px-4 py-2 text-left text-sm leading-5 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out"
                                href="{{ env('INFORMATION_URL') }}"
                                target="_blank">{{ __('welcome.button.information') }}</a>
                        @endif
                        @if (\App\Models\SystemSetting::where('key', 'announcement')->first()->value != '')
                            <button
                                class="block w-full px-4 py-2 text-left text-sm leading-5 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out"
                                onclick="$modal1.show();">{{ __('settings.label.anno') }}</button>
                        @endif
                        @if (\App\Models\SystemSetting::where('key', 'tos')->first()->value != '')
                            <button
                                class="block w-full px-4 py-2 text-left text-sm leading-5 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out"
                                onclick="$modal2.show();">{{ __('settings.label.tos') }}</button>
                        @endif
                        <hr class="border-gray-300 dark:border-gray-600">
                        <!-- Authentication -->
                        @if (Auth::user()->hasPerm('tab_Profile'))
                            <x-dropdown-link :href="route('profile.edit')">
                                {{ __('profile.route') }}
                            </x-dropdown-link>
                        @endif
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('login.button.sign_out') }}
                            </x-dropdown-link>
                        </form>
                        <div data-dropdown-toggle="language-dropdown-menu" data-dropdown-trigger="hover"
                            data-dropdown-delay="100"
                            class="block w-full px-4 py-2 text-left text-sm leading-5 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out">
                            <div class="flex items-center">
                                <i class="fas fa-language mr-2"></i>
                                <p>{{ $languages[session('locale') ?? config('app.locale')] }}</p>
                                <i class="fas fa-chevron-up mx-2 rotate-180" style="font-size:14px;"></i>
                            </div>
                        </div>
                        <div class="z-50 hidden my-4 text-base list-none bg-white divide-y divide-gray-100 rounded-lg shadow dark:bg-gray-800"
                            id="language-dropdown-menu">
                            <ul class="py-2 font-medium" role="none">
                                @foreach ($languages as $key => $value)
                                    @unless ($key == session('locale', config('app.locale')))
                                        <li>
                                            <a href="#" onclick="changeLanguage('{{ $key }}')"
                                                class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-600 dark:hover:text-white"
                                                role="menuitem">
                                                <div class="inline-flex items-center">
                                                    {{ $value }}
                                                </div>
                                            </a>
                                        </li>
                                    @endunless
                                @endforeach
                                <script>
                                    function changeLanguage(locale) {
                                        $.ajax({
                                            url: '/lang/' + locale,
                                            type: 'GET',
                                            success: function() {
                                                location.reload();
                                            }
                                        });
                                    }
                                </script>
                            </ul>
                        </div>
                    </x-slot>
                </x-dropdown>
                @if (Auth::user()->hasPerm('tab_Manage'))
                    <div class="flex justify-center items-center min-h-screen updateBtn hidden">
                        <button id="updateAvailableBtn" data-tooltip-target="tooltip-default" type="button"
                            onclick='updateWeb()'
                            class="text-green-500 hover:text-green-600 focus:ring-4 focus:outline-none focus:ring-green-300 font-medium rounded-lg p-2 flex items-center transition-transform transform hover:scale-110">
                            <i class="fa fa-download text-2xl"></i>
                        </button>
                        <div id="tooltip-default" role="tooltip"
                            class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-700">
                            Update Available
                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>
                    </div>

                    <div class="flex justify-center items-center min-h-screen updateBtn hidden">
                        <button id="updateFailedBtn" data-tooltip-target="tooltip-failed" type="button"
                            class="text-red-500 hover:text-red-600 focus:ring-4 focus:outline-none focus:ring-red-300 font-medium rounded-lg p-2 flex items-center transition-transform transform hover:scale-110">
                            <i class="fa fa-times-circle text-2xl"></i> <!-- Failed icon -->
                        </button>
                        <div id="tooltip-failed" role="tooltip"
                            class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-700">
                            Failed to check update: <br> <span></span>
                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>
                    </div>

                    <div class="flex justify-center items-center min-h-screen updateBtn hidden" id="spinnerDiv">
                        <button id="checkingBtn" data-tooltip-target="tooltip-checking" type="button"
                            class="animate-spin text-teal-500 hover:text-teal-600 focus:ring-4 focus:outline-none focus:ring-teal-300 font-medium rounded-lg p-2 flex items-center transition-transform transform hover:scale-110">
                            <div role="status">
                                <svg aria-hidden="true"
                                    class="w-6 h-6 text-gray-200 animate-spin dark:text-gray-600 fill-teal-600"
                                    viewBox="0 0 100 101" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M100 50.5908C100 78.2051 77.6142 100.591 50 100.591C22.3858 100.591 0 78.2051 0 50.5908C0 22.9766 22.3858 0.59082 50 0.59082C77.6142 0.59082 100 22.9766 100 50.5908ZM9.08144 50.5908C9.08144 73.1895 27.4013 91.5094 50 91.5094C72.5987 91.5094 90.9186 73.1895 90.9186 50.5908C90.9186 27.9921 72.5987 9.67226 50 9.67226C27.4013 9.67226 9.08144 27.9921 9.08144 50.5908Z"
                                        fill="currentColor" />
                                    <path
                                        d="M93.9676 39.0409C96.393 38.4038 97.8624 35.9116 97.0079 33.5539C95.2932 28.8227 92.871 24.3692 89.8167 20.348C85.8452 15.1192 80.8826 10.7238 75.2124 7.41289C69.5422 4.10194 63.2754 1.94025 56.7698 1.05124C51.7666 0.367541 46.6976 0.446843 41.7345 1.27873C39.2613 1.69328 37.813 4.19778 38.4501 6.62326C39.0873 9.04874 41.5694 10.4717 44.0505 10.1071C47.8511 9.54855 51.7191 9.52689 55.5402 10.0491C60.8642 10.7766 65.9928 12.5457 70.6331 15.2552C75.2735 17.9648 79.3347 21.5619 82.5849 25.841C84.9175 28.9121 86.7997 32.2913 88.1811 35.8758C89.083 38.2158 91.5421 39.6781 93.9676 39.0409Z"
                                        fill="currentFill" />
                                </svg>
                                <span class="sr-only">Loading...</span>
                            </div>
                        </button>
                        <div id="tooltip-checking" role="tooltip"
                            class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-700">
                            Checking for update
                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>
                    </div>

                    <script>
                        function checkUpdate(buttonSelector, spinnerSelector, routeUrl, forced = false) {
                            $(buttonSelector).addClass('hidden');
                            $(spinnerSelector).removeClass('hidden');

                            $.ajax({
                                url: routeUrl,
                                type: 'POST',
                                data: {
                                    _token: '{{ csrf_token() }}',
                                    forced: forced
                                },
                                success: function(response) {
                                    if (response.value === 'update-available') {
                                        $('#updateAvailableBtn').parent().removeClass('hidden');
                                    } else if (response.value === 'no-update') {} else {
                                        $('#updateFailedBtn').parent().removeClass('hidden');
                                        $('#updateFailedBtn').next().find('span').text(response.value);
                                    }

                                    $(spinnerSelector).addClass('hidden');
                                },
                                error: function(xhr, status, error) {
                                    console.error('Error:', error);

                                    $('#updateFailedBtn').parent().removeClass('hidden');
                                    $(spinnerSelector).addClass('hidden');
                                }
                            });
                        }

                        $('#updateFailedBtn').on('click', function() {
                            checkUpdate('.updateBtn', '#spinnerDiv', '{{ route('manage.setting.checkUpdate') }}', true);
                        });
                        checkUpdate('.updateBtn', '#spinnerDiv', '{{ route('manage.setting.checkUpdate') }}', false)
                    </script>

                    <div class="flex justify-center items-center min-h-screen workerBtn hidden">
                        <button id="start_worker_btn" data-tooltip-target="tooltip-start-workers" type="button"
                            class="text-green-500 hover:text-green-600 focus:ring-4 focus:outline-none focus:ring-green-300 font-medium rounded-lg p-2 flex items-center transition-transform transform hover:scale-110">
                            <i class="fa fa-cogs text-orange-400 text-2xl"></i>
                        </button>
                        <div id="tooltip-start-workers" role="tooltip"
                            class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-700">
                            {{ __('workers.label.no_workers_boot') }}
                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>
                    </div>
                    <script>
                        let canSubmit = true
                        const cooldownDuration = 2000;
                        $('#start_worker_btn').click(() => $('#start-workers-modal').removeClass('hidden'));
                        $('#confirm-start-workers').click(function() {
                            if (!canSubmit) return;
                            const count = parseInt($('#modal-worker-count-input').val()),
                                currentCount = $('#worker-count').data('current') || 0;
                            if (count > 0) {
                                $.ajax({
                                    url: '{{ route('manage.workers.start') }}',
                                    method: 'POST',
                                    data: {
                                        '_token': '{{ csrf_token() }}',
                                        count
                                    },
                                    beforeSend: function() {
                                        $('#start-workers-button').addClass('opacity-50 cursor-not-allowed')
                                            .prop('disabled', true);
                                        $('#start-icon').removeClass('fa-play').addClass(
                                            'fa-spinner fa-spin');
                                        $('#start-workers-modal').addClass('hidden');
                                        $('.workerBtn').addClass('hidden')
                                        canSubmit = false;
                                    },
                                    success: response => appendMessage(response.message, true, '#worker_result'),
                                    error: xhr => appendMessage('{{ __('workers.label.error') }}: ' + xhr
                                        .responseText, false, '#worker_result'),
                                    complete: function() {
                                        $('#start-workers-button').removeClass(
                                            'opacity-50 cursor-not-allowed').prop('disabled', false);
                                        $('#start-icon').removeClass('fa-spinner fa-spin').addClass(
                                            'fa-play');
                                        setTimeout(() => canSubmit = true, cooldownDuration);
                                    }
                                });
                            } else appendMessage('{{ __('workers.label.valid_worker_count') }}', false, '#worker_result');
                        });

                        $.ajax({
                            url: '{{ route('manage.workers.get') }}',
                            method: 'GET',
                            success: function(response) {
                                if (response.worker_count == 0) $('.workerBtn').removeClass('hidden')
                            },
                            error: null
                        });
                    </script>
                @endif
            </div>

            <!-- Hamburger -->
            <div class="-mr-2 flex items-center sm:hidden">
                <button @click="open = ! open"
                    class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-900 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-900 focus:text-gray-500 dark:focus:text-gray-400 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{ 'hidden': open, 'inline-flex': !open }" class="inline-flex"
                            stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{ 'hidden': !open, 'inline-flex': open }" class="hidden" stroke-linecap="round"
                            stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{ 'block': open, 'hidden': !open }" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            @if (Auth::user()->hasPerm('tab_Dashboard'))
                <x-responsive-nav-link :href="route('dashboard.home')" :active="request()->routeIs('dashboard.*')">
                    {{ __('dashboard.route') }}
                </x-responsive-nav-link>
            @endif
            @if (Auth::user()->hasPerm('tab_Room'))
                <x-responsive-nav-link :href="route('room.home')" :active="request()->routeIs('room.*')">
                    {{ __('room.route') }}
                </x-responsive-nav-link>
            @endif
            @if (Auth::user()->hasPerm('tab_Store'))
                <x-responsive-nav-link :href="route('store.home')" :active="request()->routeIs('store.*')">
                    {{ __('store.route') }}
                </x-responsive-nav-link>
            @endif
            @if (Auth::user()->hasPerm('tab_Cloud'))
                <x-responsive-nav-link :href="route('cloud.home')" :active="request()->routeIs('cloud.*')">
                    {{ __('cloud.route') }}
                </x-responsive-nav-link>
            @endif
            @if (Auth::user()->hasPerm('tab_Manage'))
                <x-responsive-nav-link :href="route('manage.home')" :active="request()->routeIs('manage.*')">
                    {{ __('manage.route') }}
                </x-responsive-nav-link>
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-600">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800 dark:text-gray-200">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <a href="/help/index.html" target="_blank"
                class="block w-full pl-3 pr-4 py-2 border-l-4 border-transparent text-left text-base font-medium text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 dark:hover:border-gray-600 focus:outline-none focus:text-gray-800 dark:focus:text-gray-200 focus:bg-gray-50 dark:focus:bg-gray-700 focus:border-gray-300 dark:focus:border-gray-600 transition duration-150 ease-in-out">{{ __('welcome.button.help') }}</a>

                @if (env('INFORMATION_URL'))
                    <a class="block w-full pl-3 pr-4 py-2 border-l-4 border-transparent text-left text-base font-medium text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 dark:hover:border-gray-600 focus:outline-none focus:text-gray-800 dark:focus:text-gray-200 focus:bg-gray-50 dark:focus:bg-gray-700 focus:border-gray-300 dark:focus:border-gray-600 transition duration-150 ease-in-out"
                        href="{{ env('INFORMATION_URL') }}"
                        target="_blank">{{ __('welcome.button.information') }}</a>
                @endif
                @if (\App\Models\SystemSetting::where('key', 'announcement')->first()->value != '')
                    <button
                        class="block w-full pl-3 pr-4 py-2 border-l-4 border-transparent text-left text-base font-medium text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 dark:hover:border-gray-600 focus:outline-none focus:text-gray-800 dark:focus:text-gray-200 focus:bg-gray-50 dark:focus:bg-gray-700 focus:border-gray-300 dark:focus:border-gray-600 transition duration-150 ease-in-out"
                        onclick="$modal1.show();">{{ __('settings.label.anno') }}</button>
                @endif
                @if (\App\Models\SystemSetting::where('key', 'tos')->first()->value != '')
                    <button
                        class="block w-full pl-3 pr-4 py-2 border-l-4 border-transparent text-left text-base font-medium text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 dark:hover:border-gray-600 focus:outline-none focus:text-gray-800 dark:focus:text-gray-200 focus:bg-gray-50 dark:focus:bg-gray-700 focus:border-gray-300 dark:focus:border-gray-600 transition duration-150 ease-in-out"
                        onclick="$modal2.show();">{{ __('settings.label.tos') }}</button>
                @endif
                @if (Auth::user()->hasPerm('tab_Profile'))
                    <x-responsive-nav-link :href="route('profile.edit')">
                        {{ __('profile.route') }}
                    </x-responsive-nav-link>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                        onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('login.button.sign_out') }}
                    </x-responsive-nav-link>
                </form>
                <button type="button" data-dropdown-toggle="language-dropdown-menu2" data-dropdown-trigger="hover"
                    data-dropdown-delay="100"
                    class="block w-full pl-3 pr-4 py-2 border-l-4 border-transparent text-left text-base font-medium text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 dark:hover:border-gray-600 focus:outline-none focus:text-gray-800 dark:focus:text-gray-200 focus:bg-gray-50 dark:focus:bg-gray-700 focus:border-gray-300 dark:focus:border-gray-600 transition duration-150 ease-in-out">
                    <div class="flex items-center">
                        <i class="fas fa-language mr-2"></i>
                        <p>{{ $languages[session('locale') ?? config('app.locale')] }}</p>
                        <i class="fas fa-chevron-up mx-2 rotate-180" style="font-size:14px;"></i>
                    </div>
                </button>
                <div class="z-50 hidden my-4 text-base list-none bg-white divide-y divide-gray-100 rounded-lg shadow dark:bg-gray-700"
                    id="language-dropdown-menu2">
                    <ul class="py-2 font-medium" role="none">
                        @foreach ($languages as $key => $value)
                            @unless ($key == session('locale', config('app.locale')))
                                <li>
                                    <a href="/lang/{{ $key }}"
                                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-600 dark:hover:text-white"
                                        role="menuitem">
                                        <div class="inline-flex items-center">
                                            {{ $value }}
                                        </div>
                                    </a>
                                </li>
                            @endunless
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>
