<script>
    $users = {}
    $groupnames = {
        "-1": "{{ __('users.label.other_users') }}"
    }
</script>
<form style="display:none;" id="list_users" method="post" action="{{ route('manage.tab') }}">
    @csrf
    <input name="last_tab">
    <input name="last_tool">
    <input name="list_group">
</form>
@php
    if (session('fuzzy_search')) {
        $case_insensitive_like = config('database.default') === "pgsql" ? 'ilike' : 'like';
        $fuzzy_result = App\Models\User::where('name', $case_insensitive_like, '%' . session('fuzzy_search') . '%')
            ->orWhere('email', $case_insensitive_like, '%' . session('fuzzy_search') . '%')
            ->orderby('name')
            ->get();
    } else {
        $fuzzy_result = null;
    }
@endphp

@if (session('list_group') || ($fuzzy_result != null && count($fuzzy_result) > 0))
    <div id="delete_user_modal" tabindex="-1"
        class="fixed top-0 left-0 right-0 z-50 hidden p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full">
        <div class="relative w-full max-w-md max-h-full">
            <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
                <button type="button"
                    class="absolute top-3 right-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white"
                    data-modal-hide="delete_user_modal">
                    <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                    </svg>
                    <span class="sr-only">Close modal</span>
                </button>
                <div class="p-6 text-center">
                    <svg class="mx-auto mb-4 text-gray-400 w-12 h-12 dark:text-gray-200" aria-hidden="true"
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 11V6m0 8h.01M19 10a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <h3 class="mb-5 text-lg font-normal text-gray-500 dark:text-gray-400">
                        {{ __('users.header.delete_user') }} "<span>NULL</span>"?</h3>
                    <form action="{{ route('manage.user.delete') }}" method="post" class="inline-block">
                        @csrf
                        @method('delete')
                        <input name="id" type="hidden">
                        <button type="submit"
                            class="text-white bg-red-600 hover:bg-red-800 focus:ring-4 focus:outline-none focus:ring-red-300 dark:focus:ring-red-800 font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                            {{ __('users.button.delete') }}
                        </button>
                    </form>
                    <button data-modal-hide="delete_user_modal" type="button"
                        class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-gray-200 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900 focus:z-10 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-500 dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-gray-600">{{ __('users.button.cancel') }}</button>
                </div>
            </div>
        </div>
    </div>
@endif
<div class="flex flex-1 flex-col overflow-hidden bg-gray-100 dark:bg-gray-600">
    <ol
        class="flex items-center w-full space-x-2 text-sm font-medium text-center text-gray-500 bg-gray-200 dark:bg-gray-700 px-4">
        <li class="flex items-center text-teal-600 dark:text-teal-500">
            Menu
        </li>
    </ol>
    <div id="menu" style="{{ session('last_tool') ? 'display:none;' : '' }}">
        <div class="grid flex flex-1 mx-auto max-w-screen-xl px-4 py-5 text-gray-900 dark:text-white sm:grid-cols-2">
            <button href="#" class="block p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700"
                onclick="update_stepper(['Menu','Group Selector']);$('#menu').hide();$('#group_selector').show();">
                <div class="font-semibold">{{ __('users.header.group_selector') }}</div>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('users.label.group_selector') }}</span>
            </button>
            <button href="#" class="block p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700"
                onclick="update_stepper(['Menu','Fuzzy Search']);$('#menu').hide();$('#fuzzy_selector').show();">
                <div class="font-semibold">{{ __('users.header.fuzzy_search') }}</div>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('users.label.fuzzy_search') }}</span>
            </button>
            <button href="#" class="block p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700"
                onclick="update_stepper(['Menu','Create User']);$('#menu').hide();$('#create_user_form').show();">
                <div class="font-semibold">{{ __('users.header.create_user') }}</div>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('users.label.create_user') }}</span>
            </button>
        </div>
    </div>
    <form class="flex flex-col overflow-hidden" style="display:none;" id="create_user_form" method="post"
        action="{{ route('manage.user.create') }}">
        @csrf
        <div class="w-full bg-gray-300 dark:bg-gray-600 p-3 flex items-center justify-center">
            <p class="text-lg mr-auto">{{ __('users.header.create_user') }}</p>
            <button type="submit"
                class="py-2 px-3 bg-green-600 rounded-lg hover:bg-green-700 transition duration-300 text-white">{{ __('users.button.create') }}</button>
        </div>

        <div class="scrollbar overflow-y-auto w-full">
            <div class="grid gap-3 md:grid-cols-4 w-full px-3 pt-2">
                <div class="md:col-span-2 lg:col-span-1">
                    <label for="create_user_name"
                        class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('users.label.name') }}
                        <span class="text-red-500">*</span></label>
                    <input type="text" id="create_user_name" name="name" autocomplete="off"
                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-teal-500 focus:border-teal-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-teal-500 dark:focus:border-teal-500"
                        placeholder="{{ __('users.placeholder.username') }}" required>
                </div>
                <div class="md:col-span-2 lg:col-span-1">
                    <label for="create_user_group"
                        class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('users.label.joined_group') }}</label>
                    <input type="text" list="joinable_groups" name="group" autocomplete="off"
                        id="create_user_group"
                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-teal-500 focus:border-teal-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-teal-500 dark:focus:border-teal-500"
                        placeholder="{{ __('users.placeholder.group_name') }}">
                    <datalist id="joinable_groups">
                        @foreach (App\Models\Groups::orderby('name')->get() as $group)
                            <option value="{{ $group->name }}">
                        @endforeach
                    </datalist>
                </div>
                <div class="md:col-span-4 lg:col-span-2">
                    <label for="create_user_email"
                        class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('users.label.email') }}
                        <span class="text-red-500">*</span></label>
                    <input type="text" id="create_user_email" name="email" autocomplete="off"
                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-teal-500 focus:border-teal-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-teal-500 dark:focus:border-teal-500"
                        placeholder="{{ __('users.placeholder.email') }}" required>
                </div>
            </div>
            <div class="grid gap-3 md:grid-cols-1 w-full px-3 pt-2">
                <div class="md:col-span-2 lg:col-span-1">
                    <label for="create_user_password"
                        class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('users.label.password') }}
                        <span class="text-red-500">*</span></label>
                    <input type="password" id="create_user_password" name="password" autocomplete="off"
                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-teal-500 focus:border-teal-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-teal-500 dark:focus:border-teal-500"
                        placeholder="{{ __('users.label.password') }}" required>
                </div>
            </div>
            <div class="grid gap-3 md:grid-cols-1 w-full px-3 pt-2">
                <div class="md:col-span-2 lg:col-span-1">
                    <label for="create_user_detail"
                        class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('users.label.detail') }}</label>
                    <input type="text" id="create_user_detail" name="detail" autocomplete="off"
                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-teal-500 focus:border-teal-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-teal-500 dark:focus:border-teal-500"
                        placeholder="{{ __('users.label.detail') }}">
                </div>
            </div>
            <div class="grid gap-3 md:grid-cols-1 w-full px-3 pt-2">
                <div class="md:col-span-2 lg:col-span-1">
                    <p class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2">
                        {{ __('users.label.extra_setting') }}
                    </p>

                    <div class="flex items-center">
                        <input id="create_require_change_password" name="require_change_password" value="true"
                            type="checkbox"
                            class="w-4 h-4 text-teal-600 bg-gray-100 border-gray-300 rounded focus:ring-teal-500 dark:focus:ring-teal-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                        <label for="create_require_change_password"
                            class="ml-2 ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                            {{ __('users.label.require_change_password') }}
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <div class="flex flex-1 overflow-hidden">
        <div class="flex flex-1 flex-col">
            <div id="fuzzy_selector" class="flex flex-1 h-full flex-col p-3 w-64 bg-white dark:bg-gray-700"
                style="{{ session('last_tool') == 'fuzzy_selector' ? '' : 'display:none;' }}">
                <button
                    class="text-center cursor-pointer hover:bg-gray-200 text-black dark:text-white dark:hover:bg-gray-500 rounded p-2 mb-2"
                    onclick="update_stepper(['Menu']);$('#fuzzy_selector').hide();$('#edit_user_form').hide(); $('#menu').show();">←
                    {{ __('chat.button.return_to_menu') }}</button>
                <form class="mb-2 border border-black dark:border-white border-1 rounded-lg overflow-hidden"
                    action="{{ route('manage.user.search') }}" method="post">
                    @csrf
                    <div class="flex">
                        <div class="relative w-full">
                            <input type="search" type="submit" name="search" id="fuzzy_search_input"
                                class="p-2.5 w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-r-lg border-l-gray-50 border-l-2 border border-gray-300 dark:bg-gray-700 dark:border-l-gray-700  dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-teal-500"
                                placeholder="{{ __('users.placeholder.search_user') }}" autocomplete="off"
                                value="{{ session('fuzzy_search') }}" required>
                            <button type="submit"
                                class="absolute top-0 right-0 p-2.5 text-sm font-medium h-full text-white bg-teal-700 rounded-r-lg border border-teal-700 hover:bg-teal-800 focus:ring-4 focus:outline-none focus:ring-teal-300 dark:bg-teal-600 dark:hover:bg-teal-700 dark:focus:ring-teal-800">
                                <svg class="w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 20 20">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                        stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z" />
                                </svg>
                                <span class="sr-only">Search</span>
                            </button>
                            <script>
                                var typingTimer;
                                var doneTypingInterval = 1000;
                                var $input = $('#fuzzy_search_input');

                                //on keyup, start the countdown
                                $input.on('keyup', function() {
                                    clearTimeout(typingTimer);
                                    typingTimer = setTimeout(doneTyping, doneTypingInterval);
                                });

                                //on keydown, clear the countdown 
                                $input.on('keydown', function() {
                                    clearTimeout(typingTimer);
                                });

                                //user is "finished typing," do something
                                function doneTyping() {
                                    $("#fuzzy_search_input").closest('form').submit()
                                }
                            </script>
                        </div>
                    </div>
                </form>
                @if (session('fuzzy_search'))
                    @if ($fuzzy_result->count() == 0)
                        <p>Can't find any records</p>
                    @else
                        <div class="flex-1 overflow-y-auto scrollbar">
                            @foreach ($fuzzy_result as $user)
                                <script>
                                    $users[{{ $user->id }}] = {!! json_encode(
                                        [
                                            $user->name,
                                            $user->email,
                                            $user->group_id == null ? -1 : $user->group_id,
                                            $user->detail,
                                            $user->require_change_password,
                                            $user->created_at,
                                            $user->updated_at,
                                        ],
                                        JSON_HEX_APOS,
                                    ) !!}
                                </script>

                                <div
                                    class="mb-2 border border-black dark:border-white border-1 rounded-lg overflow-hidden">
                                    <button onclick='edit_group_user({{ $user->id }})'
                                        class="flex menu-btn flex items-center justify-center w-full overflow-x-hidden break-all min-h-12 dark:hover:bg-gray-600 hover:bg-gray-200 transition duration-300">
                                        <p class="flex-1 text-center text-gray-700 dark:text-white">
                                            <span
                                                class="block border-gray-700 dark:border-white border-b">{{ $user->name }}</span>
                                            <span>{{ $user->email }}</span>
                                        </p>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @else
                    <p>{{ __('users.placeholder.enter_to_search') }}</p>
                @endif
            </div>
            <div id="group_selector" class="flex flex-1 h-full"
                style="{{ session('last_tool') == 'group_selector' ? '' : 'display:none;' }}">
                <div id="group_selector_list"
                    class="flex flex-col bg-white dark:bg-gray-700 p-2 text-white w-48 flex-shrink-0 relative overflow-hidden"
                    style="{{ session('list_group') ? 'display:none;' : '' }}">
                    <button
                        class="text-center cursor-pointer hover:bg-gray-200 text-black dark:text-white dark:hover:bg-gray-500 rounded p-2 mb-2"
                        onclick="update_stepper(['Menu']);$('#group_selector').hide(); $('#menu').show();">←
                        {{ __('chat.button.return_to_menu') }}</button>
                    <div
                        class="mb-2 border border-orange-400 dark:border-orange-400 border-1 rounded-lg overflow-hidden">
                        <button onclick='update_tab("users","group_selector",-1)'
                            class="flex menu-btn flex items-center justify-center w-full break-all min-h-12 dark:hover:bg-gray-600 hover:bg-gray-200 transition duration-300">
                            <p class="flex-1 text-center text-orange-400 dark:text-orange-400">
                                <span
                                    class="block border-orange-400 border-b">{{ __('users.label.other_users') }}</span>
                                <span
                                    class="text-sm">{{ App\Models\User::where('group_id', null)->count() . ' ' . __('users.label.members') }}</span>
                            </p>
                        </button>
                    </div>
                    <hr class="mb-2">
                    <div class="flex-1 overflow-y-auto scrollbar">
                        @foreach (App\Models\Groups::leftjoin('users', 'group_id', '=', 'groups.id')->selectRaw('groups.name as name, groups.id as id, count(users.id) as members')->groupby('groups.id')->get() as $group)
                            <script>
                                $groupnames[{{ $group->id }}] = "{{ $group->name }}"
                            </script>

                            <div
                                class="mb-2 border border-black dark:border-white border-1 rounded-lg overflow-hidden">
                                <button onclick='update_tab("users","group_selector",{{ $group->id }})'
                                    class="flex menu-btn flex items-center justify-center w-full break-all min-h-12 dark:hover:bg-gray-600 hover:bg-gray-200 transition duration-300">
                                    <p class="flex-1 text-center text-gray-700 dark:text-white">
                                        <span
                                            class="block border-gray-700 dark:border-white border-b">{{ $group->name }}</span>
                                        <span
                                            class="text-sm">{{ $group->members . ' ' . __('users.label.members') }}</span>
                                    </p>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
                @if (session('list_group'))
                    <div id="group_userlist" style="{{ session('list_group') ? '' : 'display:none;' }}"
                        class="flex flex-col bg-white dark:bg-gray-700 p-2 text-black dark:text-white w-64 flex-shrink-0 relative overflow-hidden">
                        <p></p>
                        <button
                            class="text-center cursor-pointer hover:bg-gray-200 text-black dark:text-white dark:hover:bg-gray-500 rounded p-2 mb-2"
                            onclick="update_stepper(['Menu','Group Selector']);$('#group_userlist').hide(); $('#edit_user_form').hide(); $('#group_selector_list').show();">←
                            {{ __('users.button.return_group_list') }}</button>
                        <form class="mb-2 border border-black dark:border-white border-1 rounded-lg overflow-hidden">
                            <div class="flex">
                                <div class="relative w-full">
                                    <input type="search" oninput="search_group($(this).val())"
                                        class="p-2.5 w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-r-lg border-l-gray-50 border-l-2 border border-gray-300 dark:bg-gray-700 dark:border-l-gray-700  dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-teal-500"
                                        placeholder="{{ __('users.placeholder.search_user') }}" autocomplete="off">
                                </div>
                            </div>
                        </form>


                        <div class="flex-1 overflow-y-auto scrollbar">
                            @foreach (App\Models\User::where('group_id', '=', session('list_group') == -1 ? null : session('list_group'))->orderby('name')->get() as $user)
                                <script>
                                    $users[{{ $user->id }}] = {!! json_encode(
                                        [
                                            $user->name,
                                            $user->email,
                                            $user->group_id == null ? -1 : $user->group_id,
                                            $user->detail,
                                            $user->require_change_password,
                                            $user->created_at,
                                            $user->updated_at,
                                        ],
                                        JSON_HEX_APOS,
                                    ) !!}
                                </script>

                                <div
                                    class="mb-2 border border-black dark:border-white border-1 rounded-lg overflow-hidden">
                                    <button onclick='edit_group_user({{ $user->id }})'
                                        class="flex menu-btn flex items-center justify-center w-full overflow-x-hidden break-all min-h-12 dark:hover:bg-gray-600 hover:bg-gray-200 transition duration-300">
                                        <p class="flex-1 text-center text-gray-700 dark:text-white">
                                            <span
                                                class="block border-gray-700 dark:border-white border-b">{{ $user->name }}</span>
                                            <span>{{ $user->email }}</span>
                                        </p>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
        @if (session('list_group') || ($fuzzy_result != null && count($fuzzy_result) > 0))
            <form class="flex flex-col w-full h-full" style="display:none;" id="edit_user_form" method="post"
                action="{{ route('manage.user.update') }}">
                @csrf
                @method('patch')
                <input name="list_group_id" hidden value="{{ session('list_group') }}">
                <input name="id" hidden>
                <div class="w-full bg-gray-300 text-white dark:bg-gray-600 p-3 flex items-center justify-center">
                    <p class="text-lg mr-auto text-black dark:text-white">{{ __('users.header.group_selector') }}</p>
                    <span id="user_id" class="mr-2">ID:null</span>
                    <a id="delete_user_btn" onclick="delete_user(undefined)" data-modal-target="delete_user_modal"
                        data-modal-toggle="delete_user_modal"
                        class="py-2 px-3 bg-red-600 rounded-lg hover:bg-red-700 transition mr-2 duration-300 cursor-pointer">{{ __('users.button.delete') }}</a>
                    <button
                        class="py-2 px-3 bg-green-600 rounded-lg hover:bg-green-700 transition duration-300">{{ __('users.button.update') }}</button>
                </div>

                <div class="scrollbar overflow-y-auto w-full">
                    <div class="grid gap-3 md:grid-cols-4 w-full px-3 pt-2">
                        <div class="md:col-span-2 lg:col-span-1">
                            <label for="edit_user_name"
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('users.label.name') }}
                                <span class="text-red-500">*</span></label>
                            <input type="text" id="edit_user_name" name="name" autocomplete="off"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-teal-500 focus:border-teal-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-teal-500 dark:focus:border-teal-500"
                                placeholder="{{ __('users.placeholder.username') }}" required>
                        </div>
                        <div class="md:col-span-2 lg:col-span-1">
                            <label for="edit_joined_group"
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('users.label.joined_group') }}</label>
                            <input type="text" list="joinable_groups" name="group" autocomplete="off"
                                id="edit_joined_group"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-teal-500 focus:border-teal-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-teal-500 dark:focus:border-teal-500"
                                placeholder="{{ __('users.placeholder.group_name') }}">
                            <datalist id="joinable_groups">
                                @foreach (App\Models\Groups::orderby('name')->get() as $group)
                                    <option value="{{ $group->name }}">
                                @endforeach
                            </datalist>
                        </div>
                        <div class="md:col-span-4 lg:col-span-2">
                            <label for="edit_user_email"
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('users.label.email') }}
                                <span class="text-red-500">*</span></label>
                            <input type="text" id="edit_user_email" name="email" autocomplete="off"
                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-teal-500 focus:border-teal-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-teal-500 dark:focus:border-teal-500"
                                placeholder="{{ __('users.placeholder.email') }}" required>
                        </div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-1 w-full px-3 pt-2">
                        <div class="md:col-span-2 lg:col-span-1">
                            <label for="edit_user_password"
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('users.label.update_password') }}</label>
                            <input type="password" id="edit_user_password" name="password" autocomplete="off"
                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-teal-500 focus:border-teal-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-teal-500 dark:focus:border-teal-500"
                                placeholder="{{ __('users.placeholder.new_password') }}">
                        </div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-1 w-full px-3 pt-2">
                        <div class="md:col-span-2 lg:col-span-1">
                            <label for="edit_detail"
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('users.label.detail') }}</label>
                            <input type="text" id="edit_detail" name="detail" autocomplete="off"
                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-teal-500 focus:border-teal-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-teal-500 dark:focus:border-teal-500"
                                placeholder="{{ __('users.label.detail') }}">
                        </div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-1 w-full px-3 pt-2">
                        <div class="md:col-span-2 lg:col-span-1">
                            <p class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2">
                                {{ __('users.label.extra_setting') }}
                            </p>

                            <div class="flex items-center">
                                <input id="edit_require_change_password" name="require_change_password"
                                    value="true" type="checkbox"
                                    class="w-4 h-4 text-teal-600 bg-gray-100 border-gray-300 rounded focus:ring-teal-500 dark:focus:ring-teal-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                                <label for="edit_require_change_password"
                                    class="ml-2 ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                                    {{ __('users.label.require_change_password') }}
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2 w-full px-3 pt-2">
                        <div class="md:col-span-2 lg:col-span-1">
                            <div>
                                <label for="edit_created_at">{{ __('users.label.created_at') }}</label>
                                <input readonly class="appearance-none block w-full text-gray-700 border border-gray-200 rounded py-3 px-4 leading-tight focus:outline-none focus:bg-white focus:border-gray-500" id="edit_created_at" name="edit_created_at" type="datetime-local">
                            </div>
                        </div>
                        <div class="md:col-span-2 lg:col-span-1">
                            <div>
                                <label for="edit_updated_at">{{ __('users.label.updated_at') }}</label>
                                <input readonly class="appearance-none block w-full text-gray-700 border border-gray-200 rounded py-3 px-4 leading-tight focus:outline-none focus:bg-white focus:border-gray-500" id="edit_updated_at" name="edit_updated_at" type="datetime-local">
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        @endif
    </div>
</div>



<script>
    var last_user_id = null;

    function delete_user(id) {
        $('#delete_user_modal h3 >span').text($users[id][0]);
        $("#delete_user_modal input[name='id']").val(id)
    }

    function update_tab(tab, tool, group) {
        $("#list_users input[name='last_tab']").val(tab)
        $("#list_users input[name='last_tool']").val(tool)
        $("#list_users input[name='list_group']").val(group)
        $("#list_users").submit();
    }

    function search_group(data) {
        $("#group_userlist >div >div").hide();
        $("#group_userlist >div >div").each(function() {
            var founds = false;
            $(this).find("span").each(function() {
                if ($(this).text().toLowerCase().includes(data.toLowerCase())) {
                    founds = true
                }
            })
            if (founds) {
                $(this).show();
            }
        })
    }

    function edit_group_user(id) {
        $('#edit_user_form >div >p').text("{{ __('users.header.group_selector') }} " + $users[id][0])
        $('#edit_user_form input[name=id]').val(id)
        $('#edit_user_form input[name=name]').val($users[id][0])
        $('#edit_user_form input[name=group]').val($users[id][2] == -1 ? "" : $groupnames[$users[id][2]])
        $('#edit_user_form input[name=email]').val($users[id][1])
        $('#edit_user_form input[name=detail]').val($users[id][3])
        $('#edit_user_form input[name=require_change_password]').attr("checked", $users[id][4] != 0)
        $('#edit_user_form input[name=edit_created_at]').val(new Date($users[id][5]).toISOString().slice(0, 19))
        $('#edit_user_form input[name=edit_updated_at]').val(new Date($users[id][6]).toISOString().slice(0, 19))
        $('#user_id').text('ID:' + id)
        $("#delete_user_btn").attr("onclick", `delete_user(${id})`)

        if (last_user_id != id) {
            $("#edit_user_form").show()
        } else {
            $("#edit_user_form").toggle();
        }
        last_user_id = id;
        if ($("#edit_user_form").is(":visible")) update_stepper(['Menu', $("#fuzzy_selector").is(":visible") ?
            'Fuzzy Search' : 'Group Selector', $groupnames[$users[id][2]],
            $users[id][0]
        ]);
        else {
            update_stepper(['Menu', $("#fuzzy_selector").is(":visible") ? 'Fuzzy Search' : 'Group Selector',
                $groupnames[$users[id][2]]
            ]);
        }
    }

    function update_stepper(datas) {
        symbol = `<svg class="w-3 h-3 ml-2 sm:ml-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none"
                viewBox="0 0 12 10">
                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="m7 9 4-4-4-4M1 9l4-4-4-4" />
            </svg>`
        onclicks = {
            "Menu": `update_stepper(['Menu']); $('#group_userlist').hide(); $('#create_user_form').hide();$('#group_selector').hide();$('#fuzzy_selector').hide(); $('#group_selector_list').show(); $('#menu').show();$('#edit_user_form').hide();`,
            "Group Selector": `update_stepper(['Menu', 'Group Selector']);$('#group_userlist').hide();$('#group_selector_list').show();$('#edit_user_form').hide();`,
            "Create User": `update_stepper(['Menu', 'Create User']);`,
            "Fuzzy Search": `update_stepper(['Menu', 'Fuzzy Search']);$('#edit_user_form').hide();`
        }
        parse = {
            "Menu": "{{ __('users.header.menu') }}",
            "Group Selector": "{{ __('users.header.group_selector') }}",
            "Create User": "{{ __('users.header.create_user') }}",
            "Fuzzy Search": "{{ __('users.header.fuzzy_search') }}"
        }
        $("ol").html("");
        for (var index in datas) {
                $("ol").append(`<li class="flex items-center ${datas.length-1 == index ? "text-teal-600 dark:text-teal-500":""}">
    <a class="${onclicks[datas[index]] && index < 2 ? 'cursor-pointer' : ''}" onclick="${onclicks[datas[index]] && index < 2 ?  onclicks[datas[index]] : '' }">${parse[datas[index]] ? parse[datas[index]] : datas[index]}</a>
    ${datas.length-1 == index ? "" : symbol}
</li>`);
        }
    }
    tools = {
        'group_selector': "Group Selector",
        "fuzzy_selector": "Fuzzy Search"
    }
    update_stepper(["Menu"])

    @if (session('last_tab') === 'users')
        @if (session('last_tool'))
            update_stepper(['Menu', tools["{{ session('last_tool') }}"]]);
            @if (session('list_group'))
                update_stepper(['Menu', 'Group Selector', $groupnames[{{ session('list_group') }}]]);
                $("#group_userlist >p").text($groupnames[{{ session('list_group') }}]);
                @if (session('edit_user'))
                    edit_group_user({{ session('edit_user') }})
                @endif
            @endif
        @endif
    @endif
</script>
