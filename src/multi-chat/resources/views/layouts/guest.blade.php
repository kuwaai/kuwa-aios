<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="overflow-hidden h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

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
        <link href="{{asset('css/fontBunny.css')}}" rel="stylesheet" />
        <link href="{{asset('css/flowbite.min.css')}}" rel="stylesheet" />
        <link rel="stylesheet" href="{{ asset('css/font_awesome..all.min.css') }}" />

        <!-- Scripts -->
        <script src="{{asset('js/flowbite.min.js')}}"></script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-gray-900">
            <div>
                <a href="/">
                    <x-APP-Logo class="w-20 h-20 fill-current text-gray-500" />
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white dark:bg-gray-800 shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
