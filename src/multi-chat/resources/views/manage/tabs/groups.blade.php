@php
    $models = fn() => App\Models\LLMs::getModelPermIds()->orderby('created_at', 'desc');
@endphp
<div id="delete_group_modal" tabindex="-1"
    data-modal-backdrop-classes="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed inset-0 z-40"
    class="fixed top-0 left-0 right-0 z-50 hidden p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-full bg-black/40 dark:bg-black/60 backdrop-blur-[1px]">
    <div class="relative w-full max-w-md max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
            <button type="button"
                class="absolute top-3 right-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white"
                data-modal-hide="delete_group_modal">
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
                    {{ __('groups.header.delete_group') }}"<span>NULL</span>"?</h3>
                <form action="{{ route('manage.group.delete') }}" method="post" class="inline-block">
                    @csrf
                    @method('delete')
                    <input name="id" type="hidden">
                    <button type="submit"
                        class="text-white bg-red-600 hover:bg-red-800 focus:ring-4 focus:outline-none focus:ring-red-300 dark:focus:ring-red-800 font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                        {{ __('groups.button.delete') }}
                    </button>
                </form>
                <button data-modal-hide="delete_group_modal" type="button"
                    class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-gray-200 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900 focus:z-10 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-500 dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-gray-600">{{ __('groups.button.cancel') }}</button>
            </div>
        </div>
    </div>
</div>

<div class="flex flex-1 h-full mx-auto">
    <div class="flex flex-col bg-white dark:bg-gray-700 p-2 text-white w-48 flex-shrink-0 relative overflow-hidden">
        <div class="mb-2 border border-black dark:border-white border-1 rounded-lg overflow-hidden">
            <button onclick="edit_group(undefined)" id="new_group_btn"
                class="flex menu-btn flex items-center justify-center w-full h-12 bg-green-400 hover:bg-green-500 dark:bg-green-600 dark:hover:bg-green-700 transition duration-300">
                <p class="flex-1 text-center text-white">{{ __('groups.button.new_group') }}</p>
            </button>
        </div>
        <hr class="border-black dark:border-gray-300 mb-2">
        <div id="group-list" class="flex-1 overflow-y-auto scrollbar">
            @foreach (App\Models\Groups::where('is_system', false)->get() as $group)
                <div class="mb-2 border border-black dark:border-white border-1 rounded-lg overflow-hidden">
                    <script>
                        $groups[{{ $group->id }}] = {!! json_encode(
                            [
                                $group->name,
                                $group->describe ? $group->describe : '',
                                App\Models\GroupPermissions::where('group_id', '=', $group->id)->orderby('perm_id')->pluck('perm_id'),
                                $group->invite_token,
                            ],
                            JSON_HEX_APOS,
                        ) !!}
                    </script>
                    <button onclick='edit_group({{ $group->id }})'
                        class="flex menu-btn flex items-center justify-center w-full h-12 dark:hover:bg-gray-600 hover:bg-gray-200 transition duration-300">
                        <p class="flex-1 text-center text-gray-700 dark:text-white">
                            {{ $group->name }}
                        </p>
                    </button>
                </div>
            @endforeach
        </div>
    </div>
    <div
        class="flex-1 h-full flex flex-col w-full bg-gray-100 dark:bg-gray-500 shadow-xl items-center text-gray-700 dark:text-white">
        <form class="flex flex-col w-full h-full" style="display:none;" id="create_group_form" method="post"
            action="{{ route('manage.group.create') }}">
            @csrf
            <div class="w-full bg-gray-300 dark:bg-gray-600 p-3 text-white flex items-center justify-center">
                <p class="text-lg mr-auto text-gray-700 dark:text-white">{{ __('groups.header.create_group') }}</p>
                <button
                    class="py-2 px-3 bg-green-600 rounded-lg hover:bg-green-700 transition duration-300">{{ __('groups.button.create') }}</button>
            </div>
            <div class="scrollbar overflow-y-auto w-full">
                <div class="grid gap-3 grid-cols-1 xl:grid-cols-4 md:grid-cols-2 w-full px-3 pt-2">
                    <div>
                        <label for="create_group_name"
                            class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('groups.label.group_name') }}
                            <span class="text-red-500">*</span></label>
                        <input type="text" id="create_group_name" name="name" autocomplete="off"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                            placeholder="{{ __('groups.placeholder.group_name') }}" required>
                    </div>
                    <div>
                        <label for="create_group_invite_code"
                            class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('groups.label.invite_code') }}</label>
                        <div class="flex rounded-lg overflow-hidden">
                            <label class="bg-gray-600 p-2 rounded-l-lg flex justify-center items-center"
                                for="create_enable_invite_code">
                                <input type="checkbox" style="box-shadow:none;" id="create_enable_invite_code"
                                    onclick="$(this).parent().next().val('');$(this).parent().next().prop('disabled', !$(this).prop('checked')); $(this).parent().next().next().attr('style',!$(this).prop('checked') ? 'display:none':'')">
                            </label>
                            <input type="text" id="create_group_invite_code" name="invite_code" autocomplete="off"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                placeholder="{{ __('groups.label.invite_code') }}" required disabled>
                            <div class="p-3 rounded-r-lg bg-green-500 hover:bg-green-600 cursor-pointer"
                                style="display:none" onclick="$(this).prev().val(generateRandomString(12))"><svg
                                    xmlns="http://www.w3.org/2000/svg" height="1em" style="fill:white"
                                    viewBox="0 0 640 512"><!--! Font Awesome Free 6.4.2 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2023 Fonticons, Inc. -->
                                    <path
                                        d="M274.9 34.3c-28.1-28.1-73.7-28.1-101.8 0L34.3 173.1c-28.1 28.1-28.1 73.7 0 101.8L173.1 413.7c28.1 28.1 73.7 28.1 101.8 0L413.7 274.9c28.1-28.1 28.1-73.7 0-101.8L274.9 34.3zM200 224a24 24 0 1 1 48 0 24 24 0 1 1 -48 0zM96 200a24 24 0 1 1 0 48 24 24 0 1 1 0-48zM224 376a24 24 0 1 1 0-48 24 24 0 1 1 0 48zM352 200a24 24 0 1 1 0 48 24 24 0 1 1 0-48zM224 120a24 24 0 1 1 0-48 24 24 0 1 1 0 48zm96 328c0 35.3 28.7 64 64 64H576c35.3 0 64-28.7 64-64V256c0-35.3-28.7-64-64-64H461.7c11.6 36 3.1 77-25.4 105.5L320 413.8V448zM480 328a24 24 0 1 1 0 48 24 24 0 1 1 0-48z" />
                                </svg></div>
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <label for="create_group_describe"
                            class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('groups.label.describe') }}</label>
                        <input type="text" id="create_group_describe" name="describe" autocomplete="off"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                            placeholder="{{ __('groups.placeholder.group_detail') }}">
                    </div>
                </div>
                <div class="w-full p-3">
                    <span
                        class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('groups.label.tab_permissions') }}</span>
                    <div id="edit_tab_permissions">
                        @foreach (App\Models\Permissions::where('name', 'Like', 'tab_%')->get() as $perm)
                            <div
                                class="mt-4 pb-2 flex flex-col justicfy-center px-2 border border-gray-500 dark:border-gray-200 rounded-lg dark:border-white">
                                <div style="margin-top:-0.875rem;" class="bg-gray-100 dark:bg-gray-500 pr-2 mr-auto">
                                    <label for="create_checkbox_{{ $perm->id }}">
                                        <span
                                            class="w-full my-4 ml-2 text-sm font-medium text-gray-900 dark:text-white">
                                            {{ __(strtolower(substr($perm->name, 4)) . '.route') }}
                                        </span>
                                        <input id="create_checkbox_{{ $perm->id }}" type="checkbox"
                                            onclick="$(this).closest('div').next().toggle();$(this).closest('div').next().next().toggle();$(this).closest('div').parent().find('input').prop('disabled',!$(this).prop('checked')).prop('checked',false); $(this).prop('checked',!$(this).prop('disabled')).prop('disabled',false)"
                                            value="{{ $perm->id }}" name="permissions[]"
                                            class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                                            style="box-shadow:none;">
                                    </label>
                                </div>
                                <div class="mx-3">
                                    <p>{{ App\Models\Permissions::where('name', 'Like', substr($perm->name, 4) . '_%')->count() > 0 ? '...' : '' }}
                                    </p>
                                </div>
                                <div style="display:none;">
                                    @foreach (['Create', 'Update', 'Read', 'Delete'] as $action)
                                        @php
                                            $sub_perms = App\Models\Permissions::where(
                                                'name',
                                                'Like',
                                                substr($perm->name, 4) . '_' . strtolower($action) . '_%',
                                            )->get();
                                        @endphp
                                        @if (count($sub_perms) > 0)
                                            <div
                                                class="mt-4 flex flex-col justicfy-center px-2 border border-gray-500 rounded-lg dark:border-white">
                                                <div style="margin-top:-0.875rem;"
                                                    class="bg-gray-100 dark:bg-gray-500 pr-2 mr-auto disabled:text-gray-700 ">
                                                    <label
                                                        for="create_quickCheck_{{ substr($perm->name, 4) }}_{{ $action }}">
                                                        <span
                                                            class="w-full my-4 ml-2 text-sm font-medium text-gray-900 dark:text-white">
                                                            {{ __('groups.label.' . strtolower($action)) }}
                                                        </span>
                                                        <input type="checkbox"
                                                            id="create_quickCheck_{{ substr($perm->name, 4) }}_{{ $action }}"
                                                            onclick='$(this).closest("div").next().find("input").prop("checked",$(this).prop("checked"))'
                                                            class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                                                            style="box-shadow:none;" disabled>
                                                    </label>
                                                </div>

                                                <div
                                                    class="grid gap-2 lg:grid-cols-4 md:grid-cols-3 sm:grid-cols-2 grid-cols-1 mb-2">
                                                    @foreach ($sub_perms as $sub_perm)
                                                        <div data-tooltip-target="create_checkbox_{{ $sub_perm->id }}_tooltip"
                                                            class="flex items-center pl-4 border border-gray-500 rounded-lg dark:border-white">
                                                            <input id="create_checkbox_{{ $sub_perm->id }}"
                                                                type="checkbox" value="{{ $sub_perm->id }}"
                                                                name="permissions[]"
                                                                class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                                                                style="box-shadow:none;" disabled> <label
                                                                for="create_checkbox_{{ $sub_perm->id }}"
                                                                class="w-full py-4 ml-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('perm.' . $sub_perm->name) }}</label>
                                                        </div>
                                                        <div id="create_checkbox_{{ $sub_perm->id }}_tooltip"
                                                            role="tooltip"
                                                            class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-700">
                                                            {{ __('perm_describe.' . $sub_perm->name) }}
                                                            <div class="tooltip-arrow" data-popper-arrow></div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="w-full mb-3 mt-2">
                        @foreach ([true => __('groups.label.llm_permission.enabled'), false => __('groups.label.llm_permission.disabled')] as $isEnabled => $label)
                            <span
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ $label }}</span>
                            <div
                                class="mt-4 pb-2 flex flex-col justicfy-center px-2 border border-gray-500 dark:border-gray-200 rounded-lg dark:border-white">
                                <div style="margin-top:-0.875rem;"
                                    class="bg-gray-100 dark:bg-gray-500 pr-2 mr-auto disabled:text-gray-700 ">
                                    <label for="edit_quickCheck_models_enabled">
                                        <span
                                            class="w-full my-4 ml-2 text-sm font-medium text-gray-900 dark:text-white">
                                            {{ __('groups.label.selectAll') }}
                                        </span>
                                        <input type="checkbox" id="edit_quickCheck_models_enabled"
                                            onclick='$(this).closest("div").next().find("input").prop("checked",this.checked)'
                                            class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                                            style="box-shadow:none;">
                                    </label>
                                </div>
                                <div class="grid gap-3 lg:grid-cols-3 md:grid-cols-2 grid-cols-1">
                                    @foreach ($models()->where('enabled', '=', $isEnabled)->get() as $LLM)
                                        <div
                                            class="flex items-center pl-4 border border-gray-500 dark:border-gray-200 rounded-lg dark:border-white">
                                            <input id="create_checkbox_{{ $LLM->id }}" type="checkbox"
                                                value="{{ $LLM->id }}" name="permissions[]"
                                                class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                                                style="box-shadow:none;">
                                            <label for="create_checkbox_{{ $LLM->id }}"
                                                class="w-full py-4 ml-2 text-sm font-medium text-gray-900 dark:text-white">{{ $LLM->name }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </form>
        <form class="flex flex-col w-full h-full" style="display:none;" id="edit_group_form" method="post"
            action="{{ route('manage.group.update') }}">
            @csrf
            @method('patch')
            <input id="edit_group_id" name="id" hidden>
            <div class="w-full bg-gray-300 dark:bg-gray-600 p-3 text-white flex items-center justify-center">
                <p class="text-lg mr-auto dark:text-white text-gray-700">Edit NULL</p><a id="delete_group_btn"
                    onclick="delete_group(undefined)" data-modal-target="delete_group_modal"
                    data-modal-toggle="delete_group_modal"
                    class="py-2 px-3 bg-red-600 rounded-lg hover:bg-red-700 transition mr-2 duration-300 cursor-pointer">{{ __('groups.button.delete') }}</a>
                <button
                    class="py-2 px-3 bg-green-600 rounded-lg hover:bg-green-700 transition duration-300">{{ __('groups.button.update') }}</button>
            </div>
            <div class="scrollbar overflow-y-auto w-full">
                @if (session('last_action') === 'update')
                    @if (session('status') === 'success')
                        <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)"
                            id="alert-border-3"
                            class="flex items-center p-4 mb-4 text-green-800 border-t-4 border-green-300 bg-green-50 dark:text-green-400 dark:bg-gray-800 dark:border-green-800"
                            role="alert">
                            <svg class="flex-shrink-0 w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
                                fill="currentColor" viewBox="0 0 20 20">
                                <path
                                    d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5ZM9.5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM12 15H8a1 1 0 0 1 0-2h1v-3H8a1 1 0 0 1 0-2h2a1 1 0 0 1 1 1v4h1a1 1 0 0 1 0 2Z" />
                            </svg>
                            <div class="ml-3 text-sm font-medium">{{ __('groups.placeholder.group_updated') }}
                            </div>
                        </div>
                    @endif
                @elseif (session('last_action') === 'create')
                    @if (session('status') === 'success')
                        <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)"
                            id="alert-border-3"
                            class="flex items-center p-4 mb-4 text-green-800 border-t-4 border-green-300 bg-green-50 dark:text-green-400 dark:bg-gray-800 dark:border-green-800"
                            role="alert">
                            <svg class="flex-shrink-0 w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
                                fill="currentColor" viewBox="0 0 20 20">
                                <path
                                    d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5ZM9.5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM12 15H8a1 1 0 0 1 0-2h1v-3H8a1 1 0 0 1 0-2h2a1 1 0 0 1 1 1v4h1a1 1 0 0 1 0 2Z" />
                            </svg>
                            <div class="ml-3 text-sm font-medium">{{ __('groups.placeholder.group_created') }}
                            </div>
                        </div>
                    @endif
                @endif
                <div class="grid gap-3 grid-cols-1 xl:grid-cols-4 md:grid-cols-2 w-full px-3 pt-2">
                    <div>
                        <label for="edit_group_name"
                            class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('groups.label.group_name') }}
                            <span class="text-red-500">*</span></label>
                        <input type="text" id="edit_group_name" name="name" autocomplete="off"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                            placeholder="{{ __('groups.placeholder.group_name') }}" required>
                    </div>
                    <div>
                        <label for="edit_group_invite_code"
                            class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('groups.label.invite_code') }}</label>
                        <div class="flex rounded-lg overflow-hidden">
                            <label
                                class="bg-gray-300 dark:bg-gray-600 p-2 rounded-l-lg flex justify-center items-center"
                                for="edit_enable_invite_code">
                                <input type="checkbox" style="box-shadow:none;" id="edit_enable_invite_code"
                                    onclick="$(this).parent().next().val('');$(this).parent().next().prop('disabled', !$(this).prop('checked')); $(this).parent().next().next().attr('style',!$(this).prop('checked') ? 'display:none':'')">
                            </label>
                            <input type="text" id="edit_group_invite_code" name="invite_code" autocomplete="off"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                placeholder="{{ __('groups.label.invite_code') }}" required disabled>
                            <div class="p-3 rounded-r-lg bg-green-500 hover:bg-green-600 cursor-pointer"
                                style="display:none" onclick="$(this).prev().val(generateRandomString(12))"><svg
                                    xmlns="http://www.w3.org/2000/svg" height="1em" style="fill:white"
                                    viewBox="0 0 640 512">
                                    <path
                                        d="M274.9 34.3c-28.1-28.1-73.7-28.1-101.8 0L34.3 173.1c-28.1 28.1-28.1 73.7 0 101.8L173.1 413.7c28.1 28.1 73.7 28.1 101.8 0L413.7 274.9c28.1-28.1 28.1-73.7 0-101.8L274.9 34.3zM200 224a24 24 0 1 1 48 0 24 24 0 1 1 -48 0zM96 200a24 24 0 1 1 0 48 24 24 0 1 1 0-48zM224 376a24 24 0 1 1 0-48 24 24 0 1 1 0 48zM352 200a24 24 0 1 1 0 48 24 24 0 1 1 0-48zM224 120a24 24 0 1 1 0-48 24 24 0 1 1 0 48zm96 328c0 35.3 28.7 64 64 64H576c35.3 0 64-28.7 64-64V256c0-35.3-28.7-64-64-64H461.7c11.6 36 3.1 77-25.4 105.5L320 413.8V448zM480 328a24 24 0 1 1 0 48 24 24 0 1 1 0-48z" />
                                </svg></div>
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <label for="edit_group_describe"
                            class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('groups.label.describe') }}</label>
                        <input type="text" id="edit_group_describe" name="describe" autocomplete="off"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                            placeholder="{{ __('groups.placeholder.group_detail') }}">
                    </div>
                </div>
                <div class="w-full p-3">
                    <span
                        class="block mb-3 text-sm font-medium text-gray-900 dark:text-white">{{ __('groups.label.tab_permissions') }}</span>
                    <div id="edit_tab_permissions">
                        @foreach (App\Models\Permissions::where('name', 'Like', 'tab_%')->get() as $perm)
                            <div
                                class="mt-4 pb-2 flex flex-col justicfy-center px-2 border border-gray-500 dark:border-gray-200 rounded-lg dark:border-white">
                                <div style="margin-top:-0.875rem;" class="bg-gray-100 dark:bg-gray-500 pr-2 mr-auto">
                                    <label for="edit_checkbox_{{ $perm->id }}">
                                        <span
                                            class="w-full my-4 ml-2 text-sm font-medium text-gray-900 dark:text-white">
                                            {{ __(strtolower(substr($perm->name, 4)) . '.route') }}
                                        </span>
                                        <input id="edit_checkbox_{{ $perm->id }}" type="checkbox"
                                            onclick="$(this).closest('div').next().toggle();$(this).closest('div').next().next().toggle();$(this).closest('div').parent().find('input').prop('disabled',!$(this).prop('checked')).prop('checked',false); $(this).prop('checked',!$(this).prop('disabled')).prop('disabled',false)"
                                            value="{{ $perm->id }}" name="permissions[]"
                                            class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                                            style="box-shadow:none;">
                                    </label>
                                </div>
                                <div class="mx-3">
                                    <p>{{ App\Models\Permissions::where('name', 'Like', substr($perm->name, 4) . '_%')->count() > 0 ? '...' : '' }}
                                    </p>
                                </div>
                                <div style="display:none;">
                                    @foreach (['Create', 'Update', 'Read', 'Delete'] as $action)
                                        @php
                                            $sub_perms = App\Models\Permissions::where(
                                                'name',
                                                'Like',
                                                substr($perm->name, 4) . '_' . strtolower($action) . '_%',
                                            )->get();
                                        @endphp
                                        @if (count($sub_perms) > 0)
                                            <div
                                                class="mt-4 flex flex-col justicfy-center px-2 border border-gray-500 rounded-lg dark:border-white">
                                                <div style="margin-top:-0.875rem;"
                                                    class="bg-gray-100 dark:bg-gray-500 pr-2 mr-auto disabled:text-gray-700 ">
                                                    <label
                                                        for="edit_quickCheck_{{ substr($perm->name, 4) }}_{{ $action }}">
                                                        <span
                                                            class="w-full my-4 ml-2 text-sm font-medium text-gray-900 dark:text-white">
                                                            {{ __('groups.label.' . strtolower($action)) }}
                                                        </span>
                                                        <input type="checkbox"
                                                            id="edit_quickCheck_{{ substr($perm->name, 4) }}_{{ $action }}"
                                                            onclick='$(this).closest("div").next().find("input").prop("checked",$(this).prop("checked"))'
                                                            class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                                                            style="box-shadow:none;" disabled>
                                                    </label>
                                                </div>

                                                <div
                                                    class="grid gap-2 lg:grid-cols-4 md:grid-cols-3 sm:grid-cols-2 grid-cols-1 mb-2">
                                                    @foreach ($sub_perms as $sub_perm)
                                                        <div data-tooltip-target="edit_checkbox_{{ $sub_perm->id }}_tooltip"
                                                            class="flex items-center pl-4 border border-gray-500 rounded-lg dark:border-white">
                                                            <input id="edit_checkbox_{{ $sub_perm->id }}"
                                                                type="checkbox" value="{{ $sub_perm->id }}"
                                                                name="permissions[]"
                                                                class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                                                                style="box-shadow:none;" disabled>
                                                            <label for="edit_checkbox_{{ $sub_perm->id }}"
                                                                class="w-full py-4 ml-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('perm.' . $sub_perm->name) }}</label>

                                                        </div>
                                                        <div id="edit_checkbox_{{ $sub_perm->id }}_tooltip"
                                                            role="tooltip"
                                                            class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-700">
                                                            {{ __('perm_describe.' . $sub_perm->name) }}
                                                            <div class="tooltip-arrow" data-popper-arrow></div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="w-full mb-3 mt-2">
                        @foreach ([true => __('groups.label.llm_permission.enabled'), false => __('groups.label.llm_permission.disabled')] as $isEnabled => $label)
                            <span
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ $label }}</span>
                            <div
                                class="mt-4 pb-2 flex flex-col justicfy-center px-2 border border-gray-500 dark:border-gray-200 rounded-lg dark:border-white">
                                <div style="margin-top:-0.875rem;"
                                    class="bg-gray-100 dark:bg-gray-500 pr-2 mr-auto disabled:text-gray-700 ">
                                    <label for="edit_quickCheck_models_enabled">
                                        <span
                                            class="w-full my-4 ml-2 text-sm font-medium text-gray-900 dark:text-white">
                                            {{ __('groups.label.selectAll') }}
                                        </span>
                                        <input type="checkbox" id="edit_quickCheck_models_enabled"
                                            onclick='$(this).closest("div").next().find("input").prop("checked",this.checked)'
                                            class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                                            style="box-shadow:none;">
                                    </label>
                                </div>
                                <div class="grid gap-3 lg:grid-cols-3 md:grid-cols-2 grid-cols-1">
                                    @foreach ($models()->where('enabled', '=', $isEnabled)->get() as $LLM)
                                        <div
                                            class="flex items-center pl-4 border border-gray-500 dark:border-gray-200 rounded-lg dark:border-white">
                                            <input id="edit_checkbox_{{ $LLM->id }}" type="checkbox"
                                                value="{{ $LLM->id }}" name="permissions[]"
                                                class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded dark:bg-gray-700 dark:border-gray-600"
                                                style="box-shadow:none;">
                                            <label for="edit_checkbox_{{ $LLM->id }}"
                                                class="w-full py-4 ml-2 text-sm font-medium text-gray-900 dark:text-white">{{ $LLM->name }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>


<script>
    $last_group = undefined

    async function refreshGroupsFromApi() {
        try {
            const response = await client.listGroups();
            const groups = Array.isArray(response.data) ? response.data : (response.result || []);
            const list = $('#group-list').empty();
            Object.keys($groups).forEach(key => delete $groups[key]);
            groups.forEach(group => {
                $groups[group.id] = [group.name, group.describe || '', group.permissions || [], group.invite_token || ''];
                $('<div class="mb-2 border border-black dark:border-white border-1 rounded-lg overflow-hidden">')
                    .append($('<button type="button" class="flex menu-btn items-center justify-center w-full h-12 dark:hover:bg-gray-600 hover:bg-gray-200 transition duration-300">')
                        .attr('onclick', `edit_group(${group.id})`)
                        .append($('<p class="flex-1 text-center text-gray-700 dark:text-white">').text(group.name)))
                    .appendTo(list);
            });
        } catch (error) {
            console.error('Failed to load groups from API:', error);
        }
    }

    async function submitGroupForm(form, groupId = null) {
        const data = Object.fromEntries(new FormData(form).entries());
        data.permissions = Array.from(form.querySelectorAll('input[name="permissions[]"]:checked')).map(input => Number(input.value));
        try {
            if (groupId) await client.updateGroup(groupId, data);
            else await client.createGroup(data);
            await refreshGroupsFromApi();
            location.reload();
        } catch (error) {
            console.error('Failed to save group:', error);
        }
        return false;
    }

    $('#create_group_form, #edit_group_form').on('submit', function (event) {
        event.preventDefault();
        return submitGroupForm(this, this.id === 'edit_group_form' ? Number($('#edit_group_id').val()) : null);
    });

    $('#delete_group_modal form').on('submit', async function (event) {
        event.preventDefault();
        try {
            await client.deleteGroup(Number($(this).find('input[name="id"]').val()));
            await refreshGroupsFromApi();
            location.reload();
        } catch (error) {
            console.error('Failed to delete group:', error);
        }
    });

    refreshGroupsFromApi();

    function delete_group(index) {
        $('#delete_group_modal h3 >span').text($groups[index][0]);
        $("#delete_group_modal input[name='id']").val(index)
    }

    function generateRandomString(length) {
        const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-=+[]";
        let result = "";

        for (let i = 0; i < length; i++) {
            const randomIndex = Math.floor(Math.random() * charset.length);
            result += charset.charAt(randomIndex);
        }

        return result;
    }

    function edit_group(index) {
        if (index == undefined) {
            $('#create_group_form').toggle();
            $('#new_group_btn').toggleClass("bg-green-600 hover:bg-green-700 hover:bg-gray-600")
            $('#edit_group_form').hide();
        } else {
            data = $groups[index]
            $('#create_group_form').hide();
            $('#new_group_btn').addClass("bg-green-600 hover:bg-green-700")
            $('#new_group_btn').removeClass("hover:bg-gray-600")
            $("#edit_group_form >div >p").text("{{ __('groups.header.edit_group') }} " + data[0])
            $("#edit_group_form input[type=checkbox]").prop("checked", false)
            $("#edit_group_id").val(index)
            $("#edit_group_name").val(data[0])
            $("#edit_group_describe").val(data[1])
            $("#delete_group_btn").attr("onclick", `delete_group(${index})`)
            $("#edit_enable_invite_code").click().click();
            if (data[3]) {
                $("#edit_enable_invite_code").click();
                $("#edit_group_invite_code").val(data[3])
            }


            $("#edit_tab_permissions >div").children().next().show();
            $("#edit_tab_permissions >div").children().next().next().hide();


            for (var i in data[2]) {
                $("#edit_checkbox_" + data[2][i]).click()
            }

            if ($last_group == index) {
                $("#edit_group_form").toggle();
            } else {
                $("#edit_group_form").show();
            }
            $last_group = index
        }
    }

    @if (session('last_tab') === 'groups')
        @if (session('last_group'))
            edit_group({{ session('last_group') }})
        @endif
    @endif
</script>
