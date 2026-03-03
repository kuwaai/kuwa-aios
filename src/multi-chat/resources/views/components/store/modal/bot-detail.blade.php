@props(['result'])
<div id="detail-modal" tabindex="-1" aria-hidden="true" data-modal-backdrop="static"
    data-modal-backdrop-classes="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed inset-0 z-40"
    class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-full bg-black/40 dark:bg-black/60 backdrop-blur-[1px]">
    <div class="relative w-full max-w-4xl max-h-full">
        <!-- Modal content -->
        <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
            <!-- Modal header -->
            <div class="flex items-start justify-between p-4 border-b rounded-t dark:border-gray-600">
                <div style="display:none;"
                    class="mr-4 flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full sm:h-10 sm:w-10 bg-green-100">
                    <svg stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24"
                        stroke-linecap="round" stroke-linejoin="round" class="icon-lg text-green-700" aria-hidden="true"
                        height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3">
                        </path>
                    </svg>
                </div>
                <div style="display:none;"
                    class="mr-4 flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full sm:h-10 sm:w-10 bg-red-100">
                    <svg stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24"
                        stroke-linecap="round" stroke-linejoin="round" class="icon-lg text-red-600" aria-hidden="true"
                        height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17">
                        </path>
                    </svg>
                </div>
                <h3 class="text-xl my-auto font-semibold text-gray-900 dark:text-white">
                    Null
                </h3>
                <button type="button"
                    class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white"
                    data-modal-hide="detail-modal">
                    <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                    </svg>
                    <span class="sr-only">Close modal</span>
                </button>
            </div>
            <!-- Modal body -->
            <form method="post" action="{{ route('store.update') }}" class="p-6" id="update_bot"
                enctype="multipart/form-data" onsubmit="return checkUpdateBotForm()">
                @csrf
                @method('patch')
                <input name="id" hidden>
                <ul class="flex flex-wrap flex-col -mx-3 mb-2 items-center">
                    <div class="w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap">
                        <div class="w-full md:w-1/3 mb-2">
                            <div class="w-full px-3 mb-5">
                                <div class="flex flex-wrap -mx-3">
                                    <div class="w-full px-3 flex flex-col items-center">
                                        <label for="update-bot_image">
                                            <img id="llm_img2" class="rounded-full m-auto bg-black" width="50px"
                                                height="50px">
                                        </label>
                                        <input id="update-bot_image" name="bot_image"
                                            onchange="change_bot_image('#llm_img2', '#update-bot_image')" type="file"
                                            accept="image/*" style="display:none">
                                    </div>
                                </div>
                            </div>
                            <div class="w-full flex justify-center items-center">
                                <button id="visibility2" data-dropdown-toggle="visibility_list2"
                                    class="text-white rounded-l-lg bg-blue-700 hover:bg-blue-800 focus:outline-none focus:ring-blue-300 font-medium text-sm px-5 py-2.5 text-center inline-flex items-center dark:bg-blue-600 dark:hover:bg-blue-700"
                                    type="button">{{ __('store.button.community') }}</button>
                                <div id="visibility_list2"
                                    class="z-10 hidden bg-gray-200 divide-y divide-gray-100 rounded-lg shadow w-60 dark:bg-gray-800 dark:divide-gray-600">
                                    <ul class="p-3 space-y-1 text-sm text-gray-700 dark:text-gray-200"
                                        aria-labelledby="visibility2">
                                        @php
                                            $radioItems = [
                                                [
                                                    'id' => 'visibility_system_option2',
                                                    'title' => __('store.button.system'),
                                                    'description' => __('store.placeholder.button.system'),
                                                    'value' => '0',
                                                    'checked' => request()
                                                        ->user()
                                                        ->hasPerm('Store_create_community_bot')
                                                        ? false
                                                        : true,
                                                    'onchange' =>
                                                        "this.value == 0 ? $('#visibility2').text('" .
                                                        __('store.button.system') .
                                                        "') : 1",
                                                    'name' => 'visibility',
                                                ],
                                                [
                                                    'id' => 'visibility_community_option2',
                                                    'title' => __('store.button.community'),
                                                    'description' => __('store.placeholder.button.community'),
                                                    'value' => '1',
                                                    'checked' => true,
                                                    'onchange' =>
                                                        "this.value == 1 ?$('#visibility2').text('" .
                                                        __('store.button.community') .
                                                        "') : 1",
                                                    'name' => 'visibility',
                                                ],
                                                [
                                                    'id' => 'visibility_group_option2',
                                                    'title' => __('store.button.groups'),
                                                    'description' => __('store.placeholder.button.groups'),
                                                    'value' => '2',
                                                    'checked' => request()
                                                        ->user()
                                                        ->hasPerm('Store_create_community_bot')
                                                        ? false
                                                        : true,
                                                    'onchange' =>
                                                        "this.value == 2 ? $('#visibility2').text('" .
                                                        __('store.button.groups') .
                                                        "') : 1",
                                                    'name' => 'visibility',
                                                ],
                                                [
                                                    'id' => 'visibility_private_option2',
                                                    'title' => __('store.button.private'),
                                                    'description' => __('store.placeholder.button.private'),
                                                    'value' => '3',
                                                    'checked' => request()
                                                        ->user()
                                                        ->hasPerm('Store_create_community_bot')
                                                        ? false
                                                        : true,
                                                    'onchange' =>
                                                        "this.value == 3 ? $('#visibility2').text('" .
                                                        __('store.button.private') .
                                                        "') : 1",
                                                    'name' => 'visibility',
                                                ],
                                            ];
                                        @endphp

                                        @foreach ($radioItems as $item)
                                            @include('components.radio_item', $item)
                                        @endforeach
                                    </ul>
                                </div>
                                <script>
                                    $('#visibility_list2 input[checked]:last()').click()
                                </script>
                                <button id="react_button2" data-dropdown-toggle="react_button_list2"
                                    class="text-white rounded-r-lg bg-blue-700 hover:bg-blue-800 focus:outline-none focus:ring-blue-300 font-medium text-sm px-5 py-2.5 text-center inline-flex items-center dark:bg-blue-600 dark:hover:bg-blue-700"
                                    type="button">{{ __('store.bot.react_buttons') }}
                                </button>
                                <div id="react_button_list2"
                                    class="z-10 hidden bg-gray-200 divide-y divide-gray-100 rounded-lg shadow w-72 dark:bg-gray-800 dark:divide-gray-600">
                                    <ul class="p-3 space-y-1 text-sm text-gray-700 dark:text-gray-200"
                                        aria-labelledby="react_button2">

                                        @foreach (['Feedback', 'Quote', 'Other'] as $label)
                                            @php $id = strtolower($label); @endphp
                                            <li>
                                                <div class="flex rounded hover:bg-gray-100 dark:hover:bg-gray-600">
                                                    <label for="{{ $id }}2"
                                                        class="inline-flex p-2 items-center w-full cursor-pointer">
                                                        <input checked id="{{ $id }}2" name="react_btn[]"
                                                            value="{{ $id }}" type="checkbox"
                                                            class="sr-only peer">
                                                        <div
                                                            class="relative w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full rtl:peer-checked:after:translate-x-[-100%] peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all dark:border-gray-500 peer-checked:bg-blue-600">
                                                        </div>
                                                        <span
                                                            class="ms-3 text-sm font-medium text-gray-900 dark:text-gray-300">{{ __('store.bot.react.allow_' . strtolower($label)) }}</span>
                                                    </label>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="w-full md:w-2/3">
                            <div class="w-full">
                                <label class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2"
                                    for="llm_name2">
                                    {{ __('store.bot.base_model') }}
                                </label>
                                <input id="llm_name2" type="text" list="llm-list" name="llm_name"
                                    autocomplete="off"
                                    oninput="change_bot_image('#llm_img2', '#update-bot_image', $(this).val())"
                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                    placeholder="{{ __('store.bot.base_model.label') }}">
                                @once
                                    <datalist id="llm-list">
                                        @foreach ($result as $LLM)
                                            <option
                                                src="{{ $LLM->image ? asset(Storage::url($LLM->image)) : '/' . config('app.LLM_DEFAULT_IMG') }}"
                                                value="{{ $LLM->name }}" data-access-code="{{ $LLM->access_code }}">
                                            </option>
                                        @endforeach
                                    </datalist>
                                @endonce
                            </div>
                            <div class="w-full">
                                <label for="bot_name2"
                                    class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('store.bot.name') }}</label>
                                <input type="text" name="bot_name" autocomplete="off" id="bot_name2"
                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                    placeholder="{{ __('store.bot.name.label') }}">
                            </div>
                        </div>
                    </div>
                    <div class="w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap">
                        <div class="w-full">
                            <label for="bot_describe2"
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('store.bot.description') }}</label>
                            <input type="text" name="bot_describe" autocomplete="off" id="bot_describe2"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                placeholder="{{ __('store.bot.description.label') }}">
                        </div>
                    </div>
                    <div class="w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap">
                        <div class="w-full">
                            <label
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('store.bot.modelfile') }}</label>
                            <div>
                                <textarea name="modelfile" hidden></textarea>
                                <div id="modelfile-editor" class="w-full h-48"></div>
                            </div>
                        </div>
                    </div>
                    <div class="w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap">
                        @if (request()->user()->hasPerm('Room_update_new_chat'))
                            <button type="button" onclick="$('#ChatWithBot').submit()"
                                class="bg-green-500 hover:bg-green-600 text-white font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                                {{ __('store.bot.button.chat') }}
                            </button>
                        @endif
                        @if (request()->user()->hasPerm('Store_update_modify_bot'))
                            <button type="button" id="save_bot" data-modal-target="update_modal"
                                data-modal-toggle="update_modal"
                                class="bg-green-500 hover:bg-green-600 text-white font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                                {{ __('store.button.save') }}
                            </button>
                        @endif
                        <button type="button" id="export_bot"
                            class="bg-green-500 hover:bg-green-600 text-white font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2"
                            onclick="exportBot()">
                            {{ __('store.button.export') }}
                        </button>
                        @if (request()->user()->hasPerm(['tab_Manage', 'Store_create_community_bot', 'Store_create_group_bot', 'Store_create_private_bot']) &&
                                request()->user()->hasPerm(['tab_Manage', 'Store_read_any_modelfile']))
                            <button type="button" id="duplicate_bot" data-modal-target="create-bot-modal"
                                data-modal-toggle="create-bot-modal" onclick="duplicateBot()"
                                data-modal-hide="detail-modal"
                                class="bg-green-500 hover:bg-green-600 text-white font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                                {{ __('store.button.duplicate') }}
                            </button>
                        @endif
                        @if (request()->user()->hasPerm('Store_delete_delete_bot'))
                            <button type="button" id="delete_bot" data-modal-target="delete_modal"
                                data-modal-toggle="delete_modal"
                                class="bg-red-500 hover:bg-red-600 text-white font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                                {{ __('store.button.delete') }}
                            </button>
                        @endif
                    </div>
                </ul>
                <input name="referer" value="{{ url()->current() }}" hidden>
                @foreach (session('llms') ?? [] as $bot_id)
                    <input name="selected_bots[]" value="{{ $bot_id }}" hidden>
                @endforeach
            </form>
        </div>
    </div>
</div>

<form method="post" id="ChatWithBot" style="display:none" action="{{ route('room.new') }}">
    @csrf
    <input name="llm[]" value="">
</form>

<form id="del_bot_by_ID" method="post" action="{{ route('store.delete') }}" style="display:none">
    @csrf
    @method('delete')
    <input name="id">
</form>
<div id="update_modal" data-modal-backdrop="static" tabindex="-2"
    data-modal-backdrop-classes="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed inset-0 z-40"
    class="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed top-0 left-0 right-0 z-50 hidden p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-full bg-black/40 dark:bg-black/60 backdrop-blur-[1px]">
    <div class="relative w-full max-w-md max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
            <button type="button"
                class="absolute top-3 right-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center dark:hover:bg-gray-800 dark:hover:text-white"
                data-modal-hide="update_modal">
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
                <h3 class="mb-5 text-lg font-normal text-gray-500 dark:text-gray-400">
                    {{ __('models.modal.update_model.header') }}</h3>
                <button data-modal-hide="update_modal" type="submit" id="update_bot_btn"
                    class="text-white bg-green-500 hover:bg-green-600 font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                    {{ __('store.button.yes') }}
                </button>
                <button data-modal-hide="update_modal" type="button"
                    class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-gray-200 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900 focus:z-10 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-500 dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-gray-600">{{ __('store.button.no') }}</button>
            </div>
        </div>
    </div>
</div>
<div id="delete_modal" data-modal-backdrop="static" tabindex="-2"
    data-modal-backdrop-classes="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed inset-0 z-40"
    class="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed top-0 left-0 right-0 z-50 hidden p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-full bg-black/40 dark:bg-black/60 backdrop-blur-[1px]">
    <div class="relative w-full max-w-md max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
            <button type="button"
                class="absolute top-3 right-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center dark:hover:bg-gray-800 dark:hover:text-white"
                data-modal-hide="delete_modal">
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
                <h3 class="mb-5 text-lg font-normal text-gray-500 dark:text-gray-400">
                    {{ __('models.modal.delete_model.header') }}</h3>
                <button id="delete_bot_btn" data-modal-hide="delete_modal" type="button"
                    class="text-white bg-red-600 hover:bg-red-800 font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                    {{ __('store.button.yes') }}
                </button>
                <button data-modal-hide="delete_modal" type="button"
                    class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-gray-200 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900 focus:z-10 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-500 dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-gray-600">{{ __('store.button.no') }}</button>
            </div>
        </div>
    </div>
</div>
<script>
    function detail_update(data, readonly) {
        $("#save_bot").hide()
        $("#delete_bot").hide()
        if (!readonly) {
            $("#save_bot").show()
            $("#delete_bot").show()
        }
        $("#modelfile-editor textarea").show()
        $("#detail-modal input").prop("disabled", readonly)
        $("#detail-modal input").prop("readonly", readonly)
        ace.edit('modelfile-editor').setReadOnly(readonly);
        $("#detail-modal h3").text(data.name);
        $("#detail-modal input[name='llm_name']").val(data.llm_name)
        $("#detail-modal input[name='bot_name']").val(data.name)
        $("#detail-modal img").attr("src", data.image || data.base_image)
        $("#detail-modal img").data("follow-base-bot", data.follow_base_bot_image)
        $("#detail-modal input[name='bot_describe']").val(data.description)
        $("#detail-modal input[name='visibility']").prop('disabled', false)
        visibility = $("#detail-modal input[name='visibility'][value=" + data.visibility + "]")
        visibility.click()
        if (readonly) {
            $("#detail-modal input[name='visibility']").prop('disabled', true);
        } else {
            @if (!request()->user()->hasPerm('tab_Manage'))
                $("#visibility_system_option2").prop('disabled', true)
            @endif

            @if (!request()->user()->hasPerm('Store_create_community_bot'))
                $("#visibility_community_option2").prop('disabled', true)
            @endif

            @if (!request()->user()->hasPerm('Store_create_group_bot'))
                $("#visibility_group_option2").prop('disabled', true)
            @endif

            @if (!request()->user()->hasPerm('Store_create_private_bot'))
                $("#visibility_private_option2").prop('disabled', true)
            @endif
        }
        $("#detail-modal input[name='react_btn[]']").prop("checked", false);
        if (data.config) {
            config = JSON.parse(data.config)
            if (config["react_btn"]) {
                config["react_btn"].forEach((a) => {
                    $(`#detail-modal input[value='${a}']`).prop("checked", true);
                });
            }
            modelfile = modelfile_to_string(config['modelfile'] ?? [])
            if (modelfile.length == 0) {
                ace.edit('modelfile-editor').setValue()
            } else {
                ace.edit('modelfile-editor').setValue(modelfile)
            }
        } else {
            ace.edit('modelfile-editor').setValue()
        }
        $("#update_bot_btn").off('click').on('click', function() {
            $("#update_bot input[name=id]").val(data.id);
            $("#update_bot").submit();
        });
        $("#delete_bot_btn").off('click').on('click', function() {
            $("#del_bot_by_ID input:eq(2)").val(data.id);
            $("#del_bot_by_ID").submit();
        });
        $('#ChatWithBot input[name=\'llm[]\']').val(data.id)
        ace.edit('modelfile-editor').gotoLine(0);
    }

    function checkUpdateBotForm() {
        if ($("#update_bot input[name='llm_name']").val() && $("#update_bot input[name='bot_name']").val()) {
            $('#detail-modal textarea[name=modelfile]').val(modelfile_to_string(modelfile_parse(ace.edit(
                    'modelfile-editor')
                .getValue())))
            ace.edit('modelfile-editor').setValue(modelfile_to_string(modelfile_parse(ace.edit('modelfile-editor')
                .getValue())))
            ace.edit('modelfile-editor').gotoLine(0);
            return true;
        }
        if (!$("#update_bot input[name='llm_name']").val()) $("#create_error").text(
            "{{ __('store.placeholder.must_select_base_model') }}")
        else if (!$("#update_bot input[name='bot_name']").val()) $("#create_error").text(
            "{{ __('You must name your bot') }}")
        $("#create_error").show().delay(3000).fadeOut();
        return false;
    }

    function saveTextAsFile(textToWrite, fileNameToSaveAs) {
        var textFileAsBlob = new Blob([textToWrite], {
            type: 'text/plain'
        });
        var downloadLink = document.createElement("a");
        downloadLink.download = fileNameToSaveAs;
        downloadLink.innerHTML = "Download File";
        if (window.webkitURL != null) {
            // Chrome allows the link to be clicked
            // without actually adding it to the DOM.
            downloadLink.href = window.webkitURL.createObjectURL(textFileAsBlob);
        } else {
            // Firefox requires the link to be added to the DOM
            // before it can be clicked.
            downloadLink.href = window.URL.createObjectURL(textFileAsBlob);
            downloadLink.onclick = destroyClickedElement;
            downloadLink.style.display = "none";
            document.body.appendChild(downloadLink);
        }

        downloadLink.click();
    }

    function formatDate() {
        const date = new Date();

        const dayOfWeek = date.toLocaleString('en-US', {
            weekday: 'short'
        });
        const day = date.toLocaleString('en-US', {
            day: '2-digit'
        });
        const month = date.toLocaleString('en-US', {
            month: 'short'
        });
        const year = date.getFullYear();
        const hours = date.toLocaleString('en-US', {
            hour: '2-digit',
            hour12: false
        });
        const minutes = date.toLocaleString('en-US', {
            minute: '2-digit'
        });
        const seconds = date.toLocaleString('en-US', {
            second: '2-digit'
        });

        // Get timezone offset in hours and minutes
        const timezoneOffset = -date.getTimezoneOffset();
        const tzHours = Math.floor(Math.abs(timezoneOffset) / 60);
        const tzMinutes = Math.abs(timezoneOffset) % 60;
        const tzSign = timezoneOffset >= 0 ? '+' : '-';
        const timezone = `${tzSign}${String(tzHours).padStart(2, '0')}${String(tzMinutes).padStart(2, '0')}`;

        return `${dayOfWeek}, ${day} ${month} ${year} ${hours}:${minutes}:${seconds} ${timezone}`;
    }

    async function imageUrl2Base64(url) {
        const response = await fetch(url);
        const blob = await response.blob();
        return new Promise((onSuccess, onError) => {
            try {
                const reader = new FileReader();
                reader.onload = function() {
                    onSuccess(this.result)
                };
                reader.readAsDataURL(blob);
            } catch (e) {
                onError(e);
            }
        });
    }
    let default_visibility = null;

    function create_bot() {
        if (default_visibility == null)
            default_visibility = $("#visibility_list input").last().val()
        $("#visibility_list input").last().click().prop("checked", true)
        $('#visibility').text($("#visibility_list input[value='" + default_visibility + "']").closest("label").find("div >div").text())
        $("#bot_type").val("prompt")
        showBotConfigLayout()
        $("#bot_name").val("")
        $("#bot_describe").val("")
        $("#llm_name").val("")
        change_bot_image('#llm_img', '#create-bot_image', "")
        toggleModelfile()
        ace.edit('bot-modelfile-editor').setValue("")
        toggleModelfile()
    }

    function duplicateBot() {
        if (default_visibility == null)
            default_visibility = $("#visibility_list input").last().val()
        $("#visibility_list input").last().click().prop("checked", true)

        $("#bot_type").val($("#llm_name2").val() == "Weblet" ? "server" : ($("#llm_name2").val() == "Agent" ? "agent" : "prompt"))
        showBotConfigLayout()
        $("#bot_name").val($("#bot_name2").val())
        $("#bot_describe").val($("#bot_describe2").val())
        $("#llm_name").val($("#llm_name2").val())
        fetch_and_insert_image($("#llm_img2")[0].src, "create-bot_image")
        change_bot_image('#llm_img', '#create-bot_image', $("#llm_name2").val())
        $('#visibility').text($("#visibility_list input[value='" + default_visibility + "']").closest("label").find("div >div").text())
        toggleModelfile()
        ace.edit('bot-modelfile-editor').setValue(ace.edit('modelfile-editor').getValue())
    }

    async function exportBot() {
        let name = $("#bot_name2").val();
        let description = $("#bot_describe2").val();
        let base_name = $("#llm_name2").val();
        let base = $(`#llm-list option[value='${base_name}']`).data('access-code')
        let modelfile = ace.edit('modelfile-editor').getValue();
        let follow_base_bot = $("#detail-modal img").data("follow-base-bot");

        const prefix = "KUWABOT";
        const shebang = "#!";
        modelfile = modelfile.replace(new RegExp(`^${prefix}.*`, "gm"), '');
        modelfile = modelfile.replace(new RegExp(`^${shebang}.*`), '');
        modelfile = `${shebang}\n` + `${prefix} version 0.3.3\n` +
            (name ? `${prefix} name "${name}"\n` : "") +
            (description ? `${prefix} description "${description}"\n` : "") +
            (base ? `${prefix} base "${base}"\n` : "") +
            modelfile

        let boundary = "kuwa" + (Math.random() + 1).toString(36).slice(-5);
        let botfile = [
            `Subject: Exported bot "${encodeURIComponent(name)}"`,
            `Date: ${formatDate()}`,
            `Content-Type: multipart/related; boundary="${boundary}"; type=application/vnd.kuwabot`,
            "Content-Transfer-Encoding: quoted-printable",
            "",
            `--${boundary}`,
            "Content-Type: application/vnd.kuwabot;",
            "Content-Language: {{ str_replace('_', '-', $locale = App::getLocale()) }}",
            "",
            modelfile.trim(),
            "",
        ]
        if (!follow_base_bot) {
            const avatar_data_url = await imageUrl2Base64($("#detail-modal img")[0].src);
            const matches = avatar_data_url.match(/^data:(.+);base64,(.+)$/);
            const content_type = matches[1];
            const avatar_data = matches[2];
            botfile.push(
                `--${boundary}`,
                `Content-Type: ${content_type}`,
                `Content-Transfer-Encoding: base64`,
                `Content-Location: /bot-avatar`,
                "",
                `${avatar_data}`,
                ""
            );
        }
        botfile.push(`--${boundary}--`);

        saveTextAsFile(botfile.join('\r\n'), `bot-${name.replace(/\s+/g, '_')}.bot`);
    }

    var editor = ace.edit($('#modelfile-editor')[0], {
        mode: "ace/mode/dockerfile",
        selectionStyle: "text"
    })
    editor.setHighlightActiveLine(true);
    // Set the onblur event
    $('#modelfile-editor textarea').on('blur', function() {
        let data = modelfile_parse(ace.edit('modelfile-editor').getValue());
        for (let obj of data) {
            if (obj.name === 'system') {
                $("#bot-system_prompt").val(obj.args)
            }
        }
        ace.edit('modelfile-editor').setValue(modelfile_to_string(data))
        ace.edit('modelfile-editor').gotoLine(0);
    });
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        editor.setTheme("ace/theme/monokai");
    }
    @once

    function change_bot_image(bot_image_elem, user_upload_elem, new_base_bot_name) {
        /**
         * Dynamically updates the bot's displayed image based on user interaction.
         *
         * Image selection priority:
         * 1. User-uploaded image (highest)
         * 2. Base bot image (if the bot image hasn't been changed)
         * 3. Original image (lowest)
         */
        const [user_uploaded_image] = $(user_upload_elem)[0].files;
        const follow_base_bot = $(bot_image_elem).data("follow-base-bot") ?? true;
        let bot_image_uri = $(bot_image_elem).attr("src");
        if (user_uploaded_image) {
            bot_image_uri = URL.createObjectURL(user_uploaded_image);
        } else if (follow_base_bot && new_base_bot_name) {
            const fallback_image_uri = "{{ asset('/' . config('app.LLM_DEFAULT_IMG')) }}";
            bot_image_uri = $(`#llm-list option[value="${new_base_bot_name}"]`).attr("src") ?? fallback_image_uri;
        }
        $(bot_image_elem).attr("src", bot_image_uri);
    }

    function fetch_and_insert_image(imageUrl, fileInputId) {
        return new Promise((resolve, reject) => {
            const fileInput = document.getElementById(fileInputId);

            if (!fileInput) {
                reject(new Error(`File input element with ID "${fileInputId}" not found.`));
                return;
            }

            let content_type = null;
            fetch(imageUrl)
            .then(response => {
                if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
                }
                content_type = response.headers.get('content-type') || 'image/jpeg';
                return response.blob();
            })
            .then(blob => {
                const file = new File([blob], 'image', { type: content_type });

                //For modern browsers supporting FileList
                if (fileInput.files) {
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    fileInput.files = dataTransfer.files;
                } else { //Fallback for older browsers
                    fileInput.files = [file]; //This might not work reliably across all older browsers.
                }
                fileInput.dispatchEvent(new Event('change'))
                resolve(); // Resolve the promise after successful insertion
            })
            .catch(error => {
                reject(error); // Reject the promise if any error occurs
            });
        });
    }
        
    @endonce
</script>
