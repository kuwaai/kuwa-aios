@php
    $allowedCIDRs = array_filter(explode(',', env('ALLOWED_IPS', '')), 'strlen');
    $ip_allowed =
        !$allowedCIDRs || App\Http\Controllers\ProfileController::isIPInCIDRList(request()->ip(), $allowedCIDRs);
    $languages = config('app.LANGUAGES');
@endphp


<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script>
        (() => {
            const media = window.matchMedia('(prefers-color-scheme: dark)');
            const apply = (isDark) => document.documentElement.classList.toggle('dark', isDark);
            apply(media.matches);
            if (media.addEventListener) {
                media.addEventListener('change', (event) => apply(event.matches));
            } else {
                media.addListener((event) => apply(event.matches));
            }
        })();
    </script>

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link href="{{ asset('css/fontBunny.css') }}" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('css/font_awesome..all.min.css') }}" />

    <!-- Styles -->
    <link href="{{ asset('css/flowbite.min.css') }}" rel="stylesheet" />
    <script src="{{ asset('js/flowbite.min.js') }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- Scripts -->
    <script src="{{ asset('js/jquery-3.7.1.min.js') }}"></script>
</head>

<body class="antialiased scrollbar">
    <div
        class="relative z-9999 min-h-screen bg-dots-darker bg-center bg-gray-100 dark:bg-dots-lighter dark:bg-gray-900 selection:bg-red-500 selection:text-white">

        @if (Route::has('login'))
            <div class="flex justify-end p-6 text-right">
                @if ($ip_allowed)
                    @auth
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            @if (Auth::user()->hasPerm('tab_Manage'))
                                <a href="{{ url('/manage') }}"
                                    class="ml-4 font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white focus:rounded-sm focus:outline-red-500">{{ __('manage.route') }}</a>
                            @endif
                            @if (Auth::user()->hasPerm('tab_Room'))
                                <a href="{{ route('room.home') }}"
                                    class="ml-4 font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white focus:rounded-sm focus:outline-red-500">{{ __('room.route') }}</a>
                            @endif
                            @if (Auth::user()->hasPerm('tab_Store'))
                                <a href="{{ route('store.home') }}"
                                    class="ml-4 font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white focus:rounded-sm focus:outline-red-500">{{ __('store.route') }}</a>
                            @endif
                            <a href="{{ route('logout') }}"
                                onclick="event.preventDefault(); this.closest('form').submit();"
                                class="ml-4 font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white focus:rounded-sm focus:outline-red-500">{{ __('login.button.sign_out') }}</a>
                        </form>
                    @else
                        <a href="{{ route('login') }}"
                            class="ml-4 font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white focus:rounded-sm focus:outline-red-500">{{ __('login.button.sign_in') }}</a>

                        @if (Route::has('register') &&
                                \App\Models\SystemSetting::where('key', 'allow_register')->where('value', 'true')->exists())
                            <a href="{{ route('register') }}"
                                class="ml-4 font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white focus:rounded-sm focus:outline-red-500">{{ __('login.button.sign_up') }}</a>
                        @endif
                    @endauth
                @else
                    @env('nuk')
                    <a
                        class="ml-4 font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white focus:rounded-sm focus:outline-red-500">{{ __('welcome.service_campus_only') }}</a>
                @else
                    <a
                        class="ml-4 font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white focus:rounded-sm focus:outline-red-500">{{ __('welcome.service_internal_only') }}</a>
                    @endenv
                @endif

                <button type="button" data-dropdown-toggle="language-dropdown-menu" data-dropdown-trigger="hover"
                    data-dropdown-delay="100"
                    class="ml-8 pl-2 font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white focus:rounded-sm focus:outline-red-500">
                    <div class="flex items-center">
                        <i class="fas fa-language mr-2"></i>
                    </div>
                </button>
            </div>
        @endif

        <div class="z-50 hidden my-4 text-base list-none bg-white divide-y divide-gray-100 rounded-lg shadow dark:bg-gray-700"
            id="language-dropdown-menu">
            <ul class="py-2 font-medium" role="none">
                @foreach ($languages as $key => $value)
					<li>
						<a href="#" onclick="changeLanguage('{{ $key }}')"
							class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-600 dark:hover:text-white {{$value == ($languages[session('locale') ?? config('app.locale')]) ? 'bg-gray-100 dark:bg-gray-600' : ''}}"
							role="menuitem">
							<div class="inline-flex items-center">
								{{ $value }}
							</div>
						</a>
					</li>
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

        <div class="max-w-7xl mx-auto px-6 pt-6 lg:px-8 lg:pt-8 pb-3">
            <x-Logo />
            <x-WelcomeBody />
            <x-WelcomeFooter />
        </div>
    </div>
</body>

</html>
